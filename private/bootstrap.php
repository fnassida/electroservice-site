<?php
declare(strict_types=1);

// ---------------------------------------------------------------- config
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('The work board is being set up. Please check back soon.');
}
$CONFIG = require $configFile;
require __DIR__ . '/sms.php';
require __DIR__ . '/stripe.php';
require __DIR__ . '/jobfiles.php';

// Blank values and leftover placeholders ("PASTE ...", "CHANGE ...") count as not set.
function cfg(string $key, $default = null) {
    global $CONFIG;
    $v = $CONFIG[$key] ?? null;
    if ($v === null || (is_string($v) && (trim($v) === '' || preg_match('/^(PASTE|CHANGE)\b/i', trim($v))))) return $default;
    return $v;
}

// ---------------------------------------------------------------- session
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('es_session');
session_start();

// ---------------------------------------------------------------- database
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(cfg('db_dsn'), cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    ensure_schema($pdo);
    return $pdo;
}

// Every statement in schema.sql is "CREATE TABLE IF NOT EXISTS", so it is safe to re-run.
// It runs again whenever schema.sql changes (new tables in a later stage).
function ensure_schema(PDO $pdo): void {
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $hash = md5($sql);
    try {
        $st = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute(['schema_hash']);
        if ($st->fetchColumn() === $hash) return;
    } catch (PDOException $e) {
        // settings table not there yet
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {   // local testing only
        $sql = str_replace(['AUTO_INCREMENT', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'], ['AUTOINCREMENT', ''], $sql);
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $lines = array_filter(explode("\n", $stmt), fn($l) => !str_starts_with(trim($l), '--'));
        $stmt = trim(implode("\n", $lines));
        if ($stmt !== '') $pdo->exec($stmt);
    }
    $pdo->prepare('DELETE FROM settings WHERE k = ?')->execute(['schema_hash']);
    $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute(['schema_hash', $hash]);
}

function now(): string { return gmdate('Y-m-d H:i:s'); }

// ---------------------------------------------------------------- helpers
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function redirect(string $path): never {
    header('Location: ' . $path);
    exit;
}

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? ''; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('This form expired. Go back, reload the page and try again.');
    }
}

function flash(?string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function log_activity(?int $userId, string $action, ?string $detail = null): void {
    db()->prepare('INSERT INTO activity_log (user_id, action, detail, ip, created_at) VALUES (?,?,?,?,?)')
        ->execute([$userId, $action, $detail ? mb_substr($detail, 0, 500) : null, client_ip(), now()]);
}

// ---------------------------------------------------------------- email
function site_link(string $path): string { return rtrim((string)cfg('site_url', 'https://electroservice.net'), '/') . $path; }

function send_mail(string $to, string $subject, string $text): bool {
    if ($log = cfg('mail_log_file')) {   // local testing only
        file_put_contents($log, json_encode(['to' => $to, 'subject' => $subject, 'text' => $text]) . "\n", FILE_APPEND);
        return true;
    }
    if (cfg('mail_disabled')) return true;
    $from = (string)cfg('mail_from', 'info@electroservice.net');
    $headers = "From: Electro Service <$from>\r\nReply-To: $from\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers, '-f' . $from);
}

// ---------------------------------------------------------------- email verification
function is_verified(array $u): bool {
    if ($u['role'] === 'admin') return true;
    $st = db()->prepare('SELECT verified_at FROM email_verifications WHERE user_id = ?');
    $st->execute([$u['id']]);
    return (bool)$st->fetchColumn();
}

function send_verification(array $u): bool {
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$u['id']]);
    db()->prepare('INSERT INTO email_verifications (user_id, token_hash, sent_at) VALUES (?,?,?)')
        ->execute([$u['id'], hash('sha256', $token), now()]);
    $link = site_link('/portal/verify.php?u=' . (int)$u['id'] . '&t=' . $token);
    return send_mail($u['email'], 'Confirm your email for Electro Service',
        "Hi " . strtok($u['full_name'], ' ') . ",\n\nPlease confirm your email address so you can use the work board:\n\n$link\n\n"
        . "If you didn't create an account at electroservice.net, ignore this email.\n\nElectro Service");
}

// Call before any job action. Returns true if allowed; otherwise sets a message.
function require_verified_action(array $u): bool {
    if (is_verified($u)) return true;
    flash('Confirm your email address first. Check your inbox for the link, or send a new one from your dashboard.');
    return false;
}

function verify_banner(array $u): string {
    if ($u['role'] === 'admin' || is_verified($u)) return '';
    return '<div class="error" role="status">Confirm your email address to post, request or accept jobs and to chat. We sent a link to <strong>'
         . e($u['email']) . '</strong>.<form method="post" action="/portal/verify.php" class="inline" style="margin-left:.6rem">'
         . csrf_field() . '<button class="link">Send a new link</button></form></div>';
}

// ---------------------------------------------------------------- auth
function current_user(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $user = $st->fetch() ?: null;
        if ($user && $user['status'] !== 'active') { $user = null; session_destroy(); }
    }
    return $user;
}

function require_login(?string $role = null): array {
    $u = current_user();
    if (!$u) redirect('/portal/login.php');
    if ($role && $u['role'] !== $role) {
        http_response_code(404);   // admin pages simply don't exist for anyone else
        exit('Not found');
    }
    return $u;
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([now(), $user['id']]);
}

function too_many_attempts(string $email): bool {
    $since = gmdate('Y-m-d H:i:s', time() - 15 * 60);
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > ? AND (email = ? OR ip = ?)');
    $st->execute([$since, $email, client_ip()]);
    return (int)$st->fetchColumn() >= 8;
}

// ---------------------------------------------------------------- site settings (changed from admin, stored in the database)
function setting(string $k, ?string $default = null): ?string {
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? $default : (string)$v;
}
function set_setting(string $k, ?string $v): void {
    db()->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
    if ($v !== null) db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

// Insurance referral shown to techs without current insurance.
const INSURANCE_DEFAULT_URL = 'https://www.hiscox.com/small-business-insurance';
function insurance_offer_on(): bool { return setting('ins_aff_enabled', '1') === '1'; }
function insurance_offer_html(string $context): string {
    if (!insurance_offer_on()) return '';
    $href = '/portal/go/insurance.php?from=' . rawurlencode($context);
    return '<aside class="offer"><div><h3>Need liability insurance?</h3>'
         . '<p>Hiscox sells general liability and professional liability for IT and tech businesses online, often in a few minutes. '
         . 'Get a quote, then upload the certificate here.</p>'
         . '<p class="muted small">Electro Service may earn a commission if you get a quote. That never affects whether your insurance is approved: any carrier is fine.</p></div>'
         . '<a class="btn primary" href="' . e($href) . '" target="_blank" rel="sponsored noopener">Get a Hiscox quote</a></aside>';
}

// ---------------------------------------------------------------- techs
const SKILLS = [
    'pc'        => 'Desktop and laptop repair',
    'network'   => 'Network switches and routers',
    'wifi'      => 'Wi-Fi and access points',
    'cabling'   => 'Structured cabling',
    'fiber'     => 'Fiber optics',
    'printers'  => 'Printers and copiers',
    'pos'       => 'POS and retail systems',
    'servers'   => 'Servers and racks',
    'smarthands'=> 'Data center smart hands',
    'voip'      => 'VoIP and phone systems',
    'cctv'      => 'Security cameras and access control',
    'av'        => 'Audio-visual',
    'rollouts'  => 'Retail and branch rollouts',
    'windows'   => 'Windows and Microsoft 365',
    'mac'       => 'Mac and Apple devices',
    'linux'     => 'Linux',
];

const US_STATES = ['AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD',
    'MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX',
    'UT','VT','VA','WA','WV','WI','WY'];

function upload_dir(): string {
    $d = __DIR__ . '/uploads';
    if (!is_dir($d)) mkdir($d, 0750, true);
    return $d;
}

function tech_profile(int $userId): ?array {
    $st = db()->prepare('SELECT * FROM tech_profiles WHERE user_id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

// The most recent insurance document, plus whether the tech is currently insured.
function insurance_state(int $userId): array {
    $st = db()->prepare('SELECT * FROM insurance_docs WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$userId]);
    $doc = $st->fetch() ?: null;
    $today = gmdate('Y-m-d');
    $insured = $doc && $doc['status'] === 'approved' && $doc['expires_on'] >= $today;
    $expSoon = $insured && $doc['expires_on'] <= gmdate('Y-m-d', time() + 30 * 86400);
    return ['doc' => $doc, 'insured' => $insured, 'expiring_soon' => $expSoon,
            'expired' => $doc && $doc['status'] === 'approved' && $doc['expires_on'] < $today];
}

// US Census geocoder: free, no key. Returns [lat, lng] or null.
function geocode_us(string $street, string $city, string $state, string $zip): ?array {
    if ($stub = cfg('geocode_stub')) return isset($stub[0]) ? $stub : ($stub[$zip] ?? null);   // local testing only
    $url = 'https://geocoding.geo.census.gov/geocoder/locations/address?' . http_build_query([
        'street' => $street, 'city' => $city, 'state' => $state, 'zip' => $zip,
        'benchmark' => 'Public_AR_Current', 'format' => 'json',
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: electroservice.net\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;
    $j = json_decode($raw, true);
    $m = $j['result']['addressMatches'][0]['coordinates'] ?? null;
    return $m ? [(float)$m['y'], (float)$m['x']] : null;
}

// Straight-line distance in miles.
function miles_between(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// ---------------------------------------------------------------- work orders
const WO_STATUS = [
    'open'        => 'Open',
    'assigned'    => 'Assigned',
    'in_progress' => 'In progress',
    'submitted'   => 'Waiting for approval',
    'approved'    => 'Approved',
    'cancelled'   => 'Cancelled',
];

function money(int $cents): string { return '$' . number_format($cents / 100, 2); }

function skill_labels(?string $csv): string {
    $out = [];
    foreach (array_filter(explode(',', (string)$csv)) as $k) $out[] = SKILLS[$k] ?? $k;
    return implode(', ', $out);
}

// A tech can be offered or request work once their profile has a location and their insurance is approved and current.
function tech_ready(int $userId): bool {
    $p = tech_profile($userId);
    return $p && $p['lat'] !== null && $p['skills'] && insurance_state($userId)['insured'] && tech_photo($userId) && identity_verified($userId);
}

function load_wo(int $id): ?array {
    $st = db()->prepare('SELECT w.*, u.company, u.full_name AS provider_name FROM work_orders w JOIN users u ON u.id = w.provider_id WHERE w.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function pending_request(int $woId, int $techId): ?array {
    $st = db()->prepare("SELECT * FROM wo_requests WHERE work_order_id = ? AND tech_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $st->execute([$woId, $techId]);
    return $st->fetch() ?: null;
}

// Give the job to a tech and close every other open request or offer on it.
function assign_wo(int $woId, int $techId): bool {
    $pdo = db();
    $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE work_orders SET tech_id = ?, status = 'assigned', updated_at = ? WHERE id = ? AND status = 'open'");
    $st->execute([$techId, now(), $woId]);
    if ($st->rowCount() !== 1) { $pdo->rollBack(); return false; }
    $pdo->prepare("UPDATE wo_requests SET status = 'accepted', decided_at = ? WHERE work_order_id = ? AND tech_id = ? AND status = 'pending'")
        ->execute([now(), $woId, $techId]);
    $pdo->prepare("UPDATE wo_requests SET status = 'closed', decided_at = ? WHERE work_order_id = ? AND status = 'pending'")
        ->execute([now(), $woId]);
    $pdo->commit();
    try { send_booking_emails($woId); } catch (Throwable $e) { error_log('booking email: ' . $e->getMessage()); }
    return true;
}

// Tells the provider and the tech that the job is booked, with links to add it to their calendars.
function send_booking_emails(int $woId): void {
    $w = load_wo($woId);
    if (!$w || !$w['tech_id']) return;
    $t = db()->prepare('SELECT full_name, email FROM users WHERE id = ?'); $t->execute([(int)$w['tech_id']]); $tech = $t->fetch();
    $p = db()->prepare('SELECT full_name, email FROM users WHERE id = ?'); $p->execute([(int)$w['provider_id']]); $prov = $p->fetch();
    $when = date('l, M j, Y \a\t g:i a', strtotime($w['start_at']));
    $where = $w['street'] . ', ' . $w['city'] . ', ' . $w['state'] . ' ' . $w['zip'];
    $links = "Add it to your calendar:\n"
        . "  Google Calendar: " . google_calendar_url($w) . "\n"
        . "  Outlook or Apple Calendar (reminds you the day before and an hour before): " . site_link('/portal/jobs/calendar.php?id=' . $w['id']) . "\n\n"
        . "Job page and printable work order: " . site_link('/portal/jobs/view.php?id=' . $w['id']);
    send_mail($tech['email'], "Booked: job #{$w['id']} on " . date('M j', strtotime($w['start_at'])),
        "You're booked for \"{$w['title']}\" with {$w['company']}.\n\nWhen: $when (local time)\nWhere: $where\nDirections: " . maps_directions_url($w)
        . "\n\n" . NO_SHOW_POLICY . "\n\nPrint the work order and bring it. Have the site manager sign it, then upload a photo of it with your completion photos.\n\n" . $links);
    send_mail($prov['email'], "Booked: {$tech['full_name']} for job #{$w['id']}",
        "{$tech['full_name']} is booked for \"{$w['title']}\".\n\nWhen: $when (local time)\nWhere: $where\n\n" . $links);
}

// ---------------------------------------------------------------- chat
// Who may use the thread between this job's provider and a given tech.
function can_chat(array $user, array $wo, int $techId): bool {
    if ($user['role'] === 'provider') {
        if ((int)$wo['provider_id'] !== (int)$user['id']) return false;
    } elseif ($user['role'] === 'tech') {
        if ((int)$user['id'] !== $techId) return false;
    } else {
        return false;   // admin reads only, never posts
    }
    if ((int)$wo['tech_id'] === $techId) return true;
    $st = db()->prepare('SELECT 1 FROM wo_requests WHERE work_order_id = ? AND tech_id = ? LIMIT 1');
    $st->execute([$wo['id'], $techId]);
    return (bool)$st->fetch();
}

function looks_like_contact_info(string $s): bool {
    return (bool)preg_match('/[\w.+-]+@[\w-]+\.[\w.]+|\(?\b\d{3}\)?[\s.-]*\d{3}[\s.-]*\d{4}\b/', $s);
}

function post_message(array $wo, int $techId, int $senderId, string $body, string $via = 'web'): int {
    $body = mb_substr(trim($body), 0, 4000);
    db()->prepare('INSERT INTO messages (work_order_id, tech_id, sender_id, body, via, flagged, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$wo['id'], $techId, $senderId, $body, $via, looks_like_contact_info($body) ? 1 : 0, now()]);
    $id = (int)db()->lastInsertId();
    notify_new_message($wo, $techId, $senderId);
    sms_relay_message($wo, $techId, $senderId, $body);
    return $id;
}

// Email the other side, but only for the first unread message, so a burst of messages sends one email.
function notify_new_message(array $wo, int $techId, int $senderId): void {
    $recipientId = $senderId === (int)$wo['provider_id'] ? $techId : (int)$wo['provider_id'];
    $st = db()->prepare('SELECT COUNT(*) FROM messages WHERE work_order_id = ? AND tech_id = ? AND sender_id <> ? AND read_at IS NULL');
    $st->execute([$wo['id'], $techId, $recipientId]);
    if ((int)$st->fetchColumn() !== 1) return;
    $st = db()->prepare('SELECT email, full_name FROM users WHERE id = ?');
    $st->execute([$recipientId]);
    $to = $st->fetch();
    if (!$to) return;
    $link = site_link('/portal/jobs/view.php?id=' . (int)$wo['id'] . '&thread=' . $techId . '#chat');
    send_mail($to['email'], 'New message about "' . $wo['title'] . '"',
        "Hi " . strtok($to['full_name'], ' ') . ",\n\nYou have a new message about the job \"" . $wo['title'] . "\".\n\nRead and reply: $link\n\nElectro Service");
}

// Mark the other side's messages in this thread as read by this participant.
function mark_read(array $wo, int $techId, int $readerId): void {
    db()->prepare('UPDATE messages SET read_at = ? WHERE work_order_id = ? AND tech_id = ? AND sender_id <> ? AND read_at IS NULL')
        ->execute([now(), $wo['id'], $techId, $readerId]);
}

function thread_messages(int $woId, int $techId, int $afterId = 0): array {
    $st = db()->prepare('SELECT m.id, m.sender_id, m.body, m.via, m.flagged, m.read_at, m.created_at, u.full_name, u.role
                         FROM messages m JOIN users u ON u.id = m.sender_id
                         WHERE m.work_order_id = ? AND m.tech_id = ? AND m.id > ? ORDER BY m.id');
    $st->execute([$woId, $techId, $afterId]);
    return $st->fetchAll();
}

function unread_count(int $userId, ?int $woId = null): int {
    $sql = 'SELECT COUNT(*) FROM messages m JOIN work_orders w ON w.id = m.work_order_id
            WHERE m.sender_id <> ? AND m.read_at IS NULL AND (w.provider_id = ? OR m.tech_id = ?)';
    $args = [$userId, $userId, $userId];
    if ($woId) { $sql .= ' AND m.work_order_id = ?'; $args[] = $woId; }
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
}

// Shows a stored UTC time in the site's display time zone (Eastern by default), e.g. "Nov 27, 5:31 pm".
function local_time(?string $utc, string $fmt = 'M j, g:i a'): string {
    if (!$utc) return '';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone((string)cfg('display_timezone', 'America/New_York')));
    return $d->format($fmt);
}
function local_now(): string {
    return (new DateTime('now', new DateTimeZone((string)cfg('display_timezone', 'America/New_York'))))->format('Y-m-d H:i:s');
}

function fmt_start(string $dt): string {
    $t = strtotime($dt);
    return date('D M j, Y', $t) . ' at ' . date('g:i a', $t);
}

// ---------------------------------------------------------------- layout
function page_start(string $title, ?array $user = null): void {
    $u = $user ?? current_user();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> | Electro Service</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@112,500;112,800;125,900&family=Atkinson+Hyperlegible:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="bar">
  <div class="wrap">
    <a class="brand" href="/"><img src="/assets/logo-es.svg" width="182" height="47" alt="Electro Service"></a>
    <nav aria-label="Account">
      <?php if ($u): ?>
        <?php if ($u['role'] === 'admin'): ?><a href="/portal/admin/">Admin</a><a href="/portal/admin/jobs.php">Jobs</a><a href="/portal/admin/messages.php">Messages</a><a href="/portal/admin/payments.php">Payments</a><?php endif; ?>
        <?php if ($u['role'] === 'tech'): ?><a href="/portal/jobs/browse.php">Find work</a><a href="/portal/payouts.php">Payouts</a><?php endif; ?>
        <?php if ($u['role'] !== 'admin'): $nUnread = unread_count((int)$u['id']); ?><a href="/portal/jobs/">My jobs<?= $nUnread ? ' <span class="tag provider">' . $nUnread . '</span>' : '' ?></a><?php endif; ?>
        <?php if ($u['role'] === 'provider'): ?><a href="/portal/jobs/new.php">Post a job</a><?php endif; ?>
        <?php if ($u['role'] !== 'admin'): ?><a href="/portal/settings.php">Settings</a><?php endif; ?>
        <a href="/portal/">Dashboard</a>
        <form method="post" action="/portal/logout.php" class="inline"><?= csrf_field() ?><button class="link">Log out</button></form>
      <?php else: ?>
        <a href="/portal/login.php">Log in</a>
        <a href="/portal/register.php">Sign up</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap page">
<?php if ($m = flash()): ?><p class="notice" role="status"><?= e($m) ?></p><?php endif;
    if ($u) echo verify_banner($u);
}

function page_end(): void {
    ?>
</main>
<footer class="foot"><div class="wrap"><span>© <?= date('Y') ?> Electro Service, Inc.</span>
<span class="links"><a href="/terms.html">Terms</a> <a href="/privacy.html">Privacy</a></span></div></footer>
</body>
</html>
<?php
    run_daily_jobs();
}

// ---------------------------------------------------------------- daily jobs
// No cron needed: the first page view each day (UTC) runs these after the page is sent.
function run_daily_jobs(bool $force = false): void {
    $today = gmdate('Y-m-d');
    if (!$force && setting('daily_ran_on') === $today) return;
    set_setting('daily_ran_on', $today);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    try { send_insurance_reminders(); } catch (Throwable $e) { error_log('insurance reminders: ' . $e->getMessage()); }
    // Safety net in case a Stripe webhook was missed: recheck techs still waiting on payout setup.
    try {
        if (stripe_enabled()) {
            $ids = db()->query("SELECT DISTINCT a.account_id FROM payments p JOIN work_orders w ON w.id = p.work_order_id
                                JOIN stripe_accounts a ON a.user_id = w.tech_id WHERE p.status = 'paid' AND p.payout_status IN ('waiting','none') AND w.status = 'approved'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $a) stripe_refresh_account($a);
            stripe_release_waiting();
        }
    } catch (Throwable $e) { error_log('stripe payouts: ' . $e->getMessage()); }
}

function send_insurance_reminders(): int {
    $today = gmdate('Y-m-d');
    $sent = 0;
    // The newest approved certificate for each active tech.
    $rows = db()->query("SELECT d.*, u.email, u.full_name FROM insurance_docs d JOIN users u ON u.id = d.user_id
                         WHERE d.status = 'approved' AND u.status = 'active' AND u.role = 'tech'
                           AND d.id = (SELECT MAX(d2.id) FROM insurance_docs d2 WHERE d2.user_id = d.user_id AND d2.status IN ('approved','pending'))")->fetchAll();
    foreach ($rows as $d) {
        $days = (int)floor((strtotime($d['expires_on'] . ' UTC') - strtotime($today . ' UTC')) / 86400);
        if ($days < -7) continue;                       // long expired: stop nagging
        $kind = $days < 0 ? 'ins_expired' : ($days <= 7 ? 'ins_7' : ($days <= 30 ? 'ins_30' : null));
        if (!$kind) continue;
        $st = db()->prepare('SELECT 1 FROM reminders_sent WHERE user_id = ? AND ref_id = ? AND kind = ?');
        $st->execute([$d['user_id'], $d['id'], $kind]);
        if ($st->fetch()) continue;
        $name = strtok($d['full_name'], ' ');
        $link = site_link('/portal/insurance.php');
        if ($kind === 'ins_expired') {
            $subject = 'Your insurance on file has expired';
            $body = "Hi $name,\n\nThe insurance certificate on your Electro Service profile (" . $d['carrier'] . ") expired on " . $d['expires_on']
                  . ". Until you upload your renewal, providers can't offer you work orders and you won't see new jobs.\n\nUpload your renewal: $link\n\nElectro Service";
        } else {
            $subject = $days === 0 ? 'Your insurance expires today' : "Your insurance expires in $days day" . ($days === 1 ? '' : 's');
            $body = "Hi $name,\n\nThe insurance certificate on your Electro Service profile (" . $d['carrier'] . ") expires on " . $d['expires_on']
                  . ". Upload your renewal before then so you can keep getting work orders.\n\nUpload your renewal: $link\n\nElectro Service";
        }
        if (send_mail($d['email'], $subject, $body)) {
            db()->prepare('INSERT INTO reminders_sent (user_id, ref_id, kind, created_at) VALUES (?,?,?,?)')->execute([$d['user_id'], $d['id'], $kind, now()]);
            log_activity((int)$d['user_id'], 'reminder_sent', $kind);
            $sent++;
        }
    }
    return $sent;
}
