<?php
require __DIR__ . '/../private/bootstrap.php';
$u = require_login();
if ($u['role'] === 'admin') redirect('/portal/admin/');
$uid = (int)$u['id'];
$p = sms_prefs($uid);
$errors = [];

function send_code(int $uid, string $phone): bool {
    $code = (string)random_int(100000, 999999);
    db()->prepare('UPDATE sms_prefs SET verify_code = ?, verify_sent_at = ? WHERE user_id = ?')
        ->execute([password_hash($code, PASSWORD_DEFAULT), now(), $uid]);
    $text = "Electro Service: your verification code is $code. Reply STOP to opt out.";
    $r = twilio_send($phone, $text);
    sms_log($uid, 'out', $phone, 'Electro Service: your verification code is ******.', null, null, $r['sid'] ?? null, $r['ok'] ? 'queued' : 'failed: ' . $r['error']);
    return $r['ok'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['action'] ?? '';
    if ($a === 'save') {
        $phone = normalize_us_phone((string)($_POST['phone'] ?? ''));
        $optIn = !empty($_POST['sms_opt_in']);
        if (!$phone) {
            $errors[] = 'Enter a 10-digit US mobile number.';
        } else {
            $changed = !$p || $p['phone'] !== $phone;
            db()->prepare('DELETE FROM sms_prefs WHERE user_id = ?')->execute([$uid]);
            db()->prepare('INSERT INTO sms_prefs (user_id, phone, opted_in_at, opted_out_at, verified_at, verify_code, verify_sent_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$uid, $phone,
                    $optIn ? (($p && !$changed && sms_can_text($p)) ? $p['opted_in_at'] : now()) : null,
                    $optIn ? null : ($p['opted_in_at'] ?? null ? now() : null),
                    $changed ? null : ($p['verified_at'] ?? null),
                    $changed ? null : ($p['verify_code'] ?? null),
                    $changed ? null : ($p['verify_sent_at'] ?? null), now()]);
            log_activity($uid, $optIn ? 'sms_opt_in' : 'sms_opt_out', $optIn ? 'web form' : 'web form');
            $p = sms_prefs($uid);
            if ($optIn && !$p['verified_at']) {
                if (!sms_enabled()) flash('Saved. Texting isn\'t switched on yet; we\'ll ask you to confirm your number when it is.');
                elseif (send_code($uid, $phone)) flash('We texted a 6-digit code to your phone. Enter it below to finish.');
                else flash('Saved, but the code couldn\'t be sent. Check the number and try again.');
            } else {
                flash($optIn ? 'Saved. You\'ll get job messages by text.' : 'Saved. You won\'t get texts from us.');
            }
            redirect('/portal/settings.php');
        }
    } elseif ($a === 'verify' && $p && $p['verify_code']) {
        $fresh = $p['verify_sent_at'] && strtotime($p['verify_sent_at'] . ' UTC') > time() - 15 * 60;
        if ($fresh && password_verify(preg_replace('/\D/', '', (string)($_POST['code'] ?? '')), $p['verify_code'])) {
            db()->prepare('UPDATE sms_prefs SET verified_at = ?, verify_code = NULL WHERE user_id = ?')->execute([now(), $uid]);
            log_activity($uid, 'sms_verified', $p['phone']);
            flash('Number confirmed. Job messages will now reach you by text.');
            redirect('/portal/settings.php');
        }
        $errors[] = $fresh ? 'That code doesn\'t match. Check the text and try again.' : 'That code has expired. Send a new one.';
    } elseif ($a === 'resend' && $p && $p['opted_in_at'] && !$p['verified_at']) {
        $recent = $p['verify_sent_at'] && strtotime($p['verify_sent_at'] . ' UTC') > time() - 60;
        if ($recent) flash('Wait a minute before asking for another code.');
        elseif (send_code($uid, $p['phone'])) flash('New code sent.');
        else flash('The code couldn\'t be sent. Check the number and try again.');
        redirect('/portal/settings.php');
    }
}

$display = $p ? '(' . substr($p['phone'], 2, 3) . ') ' . substr($p['phone'], 5, 3) . '-' . substr($p['phone'], 8) : '';
$optedIn = $p && $p['opted_in_at'] && (!$p['opted_out_at'] || $p['opted_out_at'] < $p['opted_in_at']);

page_start('Settings', $u);
?>
<div style="max-width:40rem">
  <h1>Settings</h1>
  <h2>Job messages by text</h2>
  <p>Get each new chat message about your jobs as a text, and reply by text. Texts come from our business number,
    so the other side never sees your phone number, and you never see theirs.</p>
  <?php if ($errors): ?><div class="error" role="alert"><?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?></div><?php endif; ?>

  <?php if ($optedIn && $p['verified_at']): ?>
    <p class="notice">Texts are on for <?= e($display) ?>.</p>
  <?php elseif ($optedIn && $p['verify_code']): ?>
    <form method="post" class="stack review" style="display:block">
      <?= csrf_field() ?>
      <h3>Confirm your number</h3>
      <p>Enter the 6-digit code we texted to <?= e($display) ?>. Codes expire after 15 minutes.</p>
      <label>Code <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" style="max-width:9rem"></label>
      <div><button class="btn primary" name="action" value="verify">Confirm</button>
        <button class="link" name="action" value="resend" style="margin-left:1rem">Send a new code</button></div>
    </form>
  <?php endif; ?>

  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Mobile number <span class="hint">US numbers only. Never shown to anyone else.</span>
      <input type="tel" name="phone" autocomplete="tel-national" value="<?= e($display) ?>" style="max-width:14rem"></label>
    <label class="check"><input type="checkbox" name="sms_opt_in" value="1" <?= $optedIn ? 'checked' : '' ?>>
      <span>Yes, text me job messages from Electro Service at this number. Message frequency varies with your jobs.
        Message and data rates may apply. Reply STOP to opt out at any time, or HELP for help.
        See our <a href="/sms.html" target="_blank">texting terms</a>, <a href="/terms.html" target="_blank">Terms of Service</a>
        and <a href="/privacy.html" target="_blank">Privacy Policy</a>.</span></label>
    <div><button class="btn primary" name="action" value="save">Save</button></div>
  </form>
</div>
<?php page_end();
