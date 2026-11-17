<?php
require __DIR__ . '/../private/bootstrap.php';
if (current_user()) redirect('/portal/');

$error = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    if (too_many_attempts($email)) {
        $error = 'Too many failed attempts. Wait 15 minutes and try again.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $user = $st->fetch();
        $ok = $user && password_verify($pass, $user['password_hash']) && $user['status'] === 'active';
        db()->prepare('INSERT INTO login_attempts (email, ip, success, created_at) VALUES (?,?,?,?)')
            ->execute([$email, client_ip(), $ok ? 1 : 0, now()]);
        if ($ok) {
            login_user($user);
            log_activity((int)$user['id'], 'login');
            redirect('/portal/');
        }
        $error = ($user && $user['status'] !== 'active')
            ? 'This account is suspended. Email info@electroservice.net for help.'
            : 'That email and password don\'t match an account.';
    }
}

page_start('Log in');
?>
<div class="narrow">
  <h1>Log in</h1>
  <p class="muted">New here? <a href="/portal/register.php">Create an account</a>.</p>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Email <input type="email" name="email" autocomplete="email" required value="<?= e($email) ?>"></label>
    <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
    <div><button class="btn primary">Log in</button></div>
  </form>
</div>
<?php page_end();
