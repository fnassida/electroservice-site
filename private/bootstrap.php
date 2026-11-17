<?php
declare(strict_types=1);

// ---------------------------------------------------------------- config
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('The work board is being set up. Please check back soon.');
}
$CONFIG = require $configFile;

function cfg(string $key, $default = null) {
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
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
    if ($stub = cfg('geocode_stub')) return $stub;   // local testing only
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
    <a class="brand" href="/">
      <span class="wires" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
      Electro Service
    </a>
    <nav aria-label="Account">
      <?php if ($u): ?>
        <?php if ($u['role'] === 'admin'): ?><a href="/portal/admin/">Admin</a><?php endif; ?>
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
}

function page_end(): void {
    ?>
</main>
<footer class="foot"><div class="wrap"><span>© <?= date('Y') ?> Electro Service, Inc.</span>
<span class="links"><a href="/terms.html">Terms</a> <a href="/privacy.html">Privacy</a></span></div></footer>
</body>
</html>
<?php
}
