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

function ensure_schema(PDO $pdo): void {
    try {
        $pdo->query('SELECT 1 FROM users LIMIT 1');
        return;
    } catch (PDOException $e) {
        // tables missing: create them below
    }
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {   // local testing only
        $sql = str_replace(['AUTO_INCREMENT', ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'], ['AUTOINCREMENT', ''], $sql);
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $lines = array_filter(explode("\n", $stmt), fn($l) => !str_starts_with(trim($l), '--'));
        $stmt = trim(implode("\n", $lines));
        if ($stmt !== '') $pdo->exec($stmt);
    }
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
