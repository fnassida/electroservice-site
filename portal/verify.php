<?php
// GET: the link from the confirmation email. POST: send a new link.
require __DIR__ . '/../private/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = require_login();
    $st = db()->prepare('SELECT sent_at, verified_at FROM email_verifications WHERE user_id = ?');
    $st->execute([$u['id']]);
    $row = $st->fetch();
    if (is_verified($u)) flash('Your email is already confirmed.');
    elseif ($row && $row['sent_at'] && strtotime($row['sent_at'] . ' UTC') > time() - 60) flash('We just sent one. Wait a minute before asking again.');
    else { send_verification($u); log_activity((int)$u['id'], 'verification_resent'); flash('New link sent to ' . $u['email'] . '.'); }
    redirect($_SERVER['HTTP_REFERER'] ?? '/portal/');
}

$uid = (int)($_GET['u'] ?? 0);
$token = (string)($_GET['t'] ?? '');
$st = db()->prepare('SELECT * FROM email_verifications WHERE user_id = ?');
$st->execute([$uid]);
$row = $st->fetch();
$ok = $row && $row['verified_at'];
if (!$ok && $row && $row['token_hash'] && $token !== '' && hash_equals($row['token_hash'], hash('sha256', $token))) {
    db()->prepare('UPDATE email_verifications SET verified_at = ?, token_hash = NULL WHERE user_id = ?')->execute([now(), $uid]);
    log_activity($uid, 'email_verified');
    $ok = true;
}

page_start('Confirm your email');
?>
<div class="narrow">
  <h1>Confirm your email</h1>
  <?php if ($ok): ?>
    <p class="notice">Your email is confirmed.</p>
    <p><a class="btn primary" href="/portal/">Go to your dashboard</a></p>
  <?php else: ?>
    <p class="error">This link isn't valid any more. Log in and use "Send a new link" on your dashboard.</p>
    <p><a href="/portal/login.php">Log in</a></p>
  <?php endif; ?>
</div>
<?php page_end();
