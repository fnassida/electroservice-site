<?php
// One-time admin account creation. Stops working once any admin exists.
require __DIR__ . '/../private/bootstrap.php';

$hasAdmin = (bool)db()->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetch();
if ($hasAdmin || cfg('setup_key', 'CHANGE_ME') === 'CHANGE_ME') {
    http_response_code(404);
    exit('Not found');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $name  = trim($_POST['full_name'] ?? '');
    $pass  = (string)($_POST['password'] ?? '');
    if (!hash_equals((string)cfg('setup_key'), (string)($_POST['setup_key'] ?? ''))) $errors[] = 'The setup key is wrong.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if ($name === '') $errors[] = 'Enter your name.';
    if (strlen($pass) < 14) $errors[] = 'Use an admin password of at least 14 characters.';
    if (!$errors) {
        db()->prepare('INSERT INTO users (role, email, password_hash, full_name, terms_accepted_at, created_at) VALUES (?,?,?,?,?,?)')
            ->execute(['admin', $email, password_hash($pass, PASSWORD_DEFAULT), $name, now(), now()]);
        $id = (int)db()->lastInsertId();
        login_user(['id' => $id]);
        flash('Admin account created. This setup page is now switched off.');
        redirect('/portal/admin/');
    }
}

page_start('Set up admin');
?>
<div class="narrow">
  <h1>Create the admin account</h1>
  <p class="muted">This page works once. After the admin account exists it disappears.</p>
  <?php if ($errors): ?><div class="error" role="alert"><?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Setup key <span class="hint">From config.php on the server.</span><input type="password" name="setup_key" required></label>
    <label>Your name <input type="text" name="full_name" required></label>
    <label>Admin email <input type="email" name="email" required></label>
    <label>Password <span class="hint">At least 14 characters.</span><input type="password" name="password" required minlength="14"></label>
    <div><button class="btn primary">Create admin account</button></div>
  </form>
</div>
<?php page_end();
