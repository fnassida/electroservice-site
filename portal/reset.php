<?php
require __DIR__ . '/../private/bootstrap.php';
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$st = db()->prepare('SELECT r.*, u.email, u.status FROM password_resets r JOIN users u ON u.id = r.user_id
                     WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ?');
$st->execute([hash('sha256', $token), now()]);
$r = $token !== '' ? $st->fetch() : false;
$errors = [];

if ($r && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pass = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT role FROM users WHERE id = ?');
    $st->execute([$r['user_id']]);
    $min = $st->fetchColumn() === 'admin' ? 14 : 10;
    if (strlen($pass) < $min) $errors[] = "Use a password of at least $min characters.";
    elseif ($pass !== (string)($_POST['password2'] ?? '')) $errors[] = 'The two passwords don\'t match.';
    else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $r['user_id']]);
        db()->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([now(), $r['user_id']]);
        // Clicking the emailed link proves the address works.
        $st = db()->prepare('SELECT 1 FROM email_verifications WHERE user_id = ?');
        $st->execute([$r['user_id']]);
        if ($st->fetch()) db()->prepare('UPDATE email_verifications SET verified_at = COALESCE(verified_at, ?), token_hash = NULL WHERE user_id = ?')->execute([now(), $r['user_id']]);
        else db()->prepare('INSERT INTO email_verifications (user_id, verified_at) VALUES (?, ?)')->execute([$r['user_id'], now()]);
        log_activity((int)$r['user_id'], 'password_reset');
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Password changed. Log in with your new password.');
        redirect('/portal/login.php');
    }
}

page_start('Choose a new password');
?>
<div class="narrow">
  <h1>Choose a new password</h1>
  <?php if (!$r || $r['status'] !== 'active'): ?>
    <p class="error">This reset link has expired or was already used. <a href="/portal/forgot.php">Ask for a new one</a>.</p>
  <?php else: ?>
    <?php if ($errors): ?><div class="error" role="alert"><?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
      <label>New password <span class="hint">At least 10 characters.</span><input type="password" name="password" autocomplete="new-password" required></label>
      <label>Type it again <input type="password" name="password2" autocomplete="new-password" required></label>
      <div><button class="btn primary">Save new password</button></div>
    </form>
  <?php endif; ?>
</div>
<?php page_end();
