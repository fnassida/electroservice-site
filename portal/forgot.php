<?php
require __DIR__ . '/../private/bootstrap.php';
if (current_user()) redirect('/portal/');
$done = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim($_POST['email'] ?? ''));
    // At most 5 requests per address or IP per hour.
    $since = gmdate('Y-m-d H:i:s', time() - 3600);
    $st = db()->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'reset_requested' AND created_at > ? AND (ip = ? OR detail = ?)");
    $st->execute([$since, client_ip(), $email]);
    if ((int)$st->fetchColumn() >= 5) {
        $error = 'Too many requests. Wait an hour and try again.';
    } else {
        log_activity(null, 'reset_requested', $email);
        $st = db()->prepare("SELECT * FROM users WHERE email = ? AND status = 'active'");
        $st->execute([$email]);
        if ($u = $st->fetch()) {
            $token = bin2hex(random_bytes(32));
            db()->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([now(), $u['id']]);
            db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?,?,?,?)')
                ->execute([$u['id'], hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 3600), now()]);
            $link = site_link('/portal/reset.php?t=' . $token);
            send_mail($u['email'], 'Reset your Electro Service password',
                "Hi " . strtok($u['full_name'], ' ') . ",\n\nSomeone asked to reset the password for your Electro Service account. "
                . "If it was you, choose a new password here. The link works once and expires in one hour:\n\n$link\n\n"
                . "If you didn't ask for this, ignore this email. Your password won't change.\n\nElectro Service");
        }
        $done = true;   // same answer whether or not the account exists
    }
}

page_start('Reset your password');
?>
<div class="narrow">
  <h1>Reset your password</h1>
  <?php if ($done): ?>
    <p class="notice">If an account uses that email, we've sent a link to reset the password. It expires in one hour. Check your spam folder if it doesn't arrive.</p>
    <p><a href="/portal/login.php">Back to log in</a></p>
  <?php else: ?>
    <p class="muted">Enter the email you signed up with and we'll send you a link.</p>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <label>Email <input type="email" name="email" autocomplete="email" required></label>
      <div><button class="btn primary">Send reset link</button></div>
    </form>
  <?php endif; ?>
</div>
<?php page_end();
