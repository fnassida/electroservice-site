<?php
// Tech chooses whether providers can call them on jobs they're booked for.
require __DIR__ . '/../private/bootstrap.php';
$u = require_login('tech');
$uid = (int)$u['id'];
$c = tech_contact($uid);
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $raw = trim($_POST['phone'] ?? '');
    $show = !empty($_POST['show']) ? 1 : 0;
    if ($raw === '') {
        db()->prepare('DELETE FROM tech_contact WHERE user_id = ?')->execute([$uid]);
        flash('Phone number removed. Providers can still reach you in the job chat.');
        redirect('/portal/');
    }
    $phone = normalize_us_phone($raw);
    if (!$phone) $error = 'Enter a 10-digit US phone number.';
    else {
        db()->prepare('DELETE FROM tech_contact WHERE user_id = ?')->execute([$uid]);
        db()->prepare('INSERT INTO tech_contact (user_id, phone, show_to_providers, updated_at) VALUES (?,?,?,?)')->execute([$uid, $phone, $show, now()]);
        log_activity($uid, 'contact_phone_saved', $show ? 'shown to providers' : 'hidden');
        flash($show ? 'Saved. Providers can call you on jobs you\'re booked for.' : 'Saved. Your number stays hidden from providers.');
        redirect('/portal/');
    }
    $c = ['phone' => $raw, 'show_to_providers' => $show];
}
page_start('Phone for calls', $u);
?>
<div style="max-width:40rem">
  <h1>Phone for calls</h1>
  <p>Let providers call you about a job you're booked for, for example to say you're running late or to ask where to park.
    Your number is shown only to the provider of a job you're booked for, only until that job is done, and never on your public profile.</p>
  <?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="stack"><?= csrf_field() ?>
    <label>Mobile number <span class="hint">Leave blank to remove it.</span>
      <input type="tel" name="phone" autocomplete="tel" value="<?= e($c ? phone_display($c['phone']) : '') ?>"></label>
    <label class="check"><input type="checkbox" name="show" value="1" <?= !$c || (int)$c['show_to_providers'] === 1 ? 'checked' : '' ?>>
      <span>Show my number to the provider on jobs I'm booked for</span></label>
    <div><button class="btn primary">Save</button></div>
  </form>
</div>
<?php page_end();
