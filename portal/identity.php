<?php
// Tech uploads a photo of their driver's license for the identity check.
require __DIR__ . '/../private/bootstrap.php';
$u = require_login('tech');
$uid = (int)$u['id'];
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (identity_verified($uid)) redirect('/portal/');
    $error = id_check_save($_FILES['license'] ?? [], $uid);
    if (!$error) { flash('License uploaded. We\'ll check it against your photo and let you know by email.'); redirect('/portal/'); }
}
$c = latest_id_check($uid);
$verified = identity_verified($uid);
page_start('Identity check', $u);
?>
<div style="max-width:40rem">
  <h1>Identity check</h1>
  <?php if ($verified): ?>
    <p class="notice">Your identity is verified. Thank you.</p>
  <?php else: ?>
    <p>Before you can take jobs, we check that you are who you say you are. Upload a photo of the front of your driver's license.
      Electro Service compares it with your photo and the name on your account.</p>
    <ul>
      <li>Only the Electro Service admin sees it. Providers never do.</li>
      <li>The image is deleted as soon as the check is approved. We keep only the fact that you were verified, and the date.</li>
      <li>Lay the license flat in good light, with all four corners in the picture and the text readable.</li>
    </ul>
    <?php if (!tech_photo($uid)): ?><p class="notice">Add <a href="/portal/photo.php">your photo</a> too. We need it to compare against your license.</p><?php endif; ?>
    <?php if ($c && $c['status'] === 'pending'): ?><p class="notice">Your license is waiting for review. You can upload a clearer photo below if needed.</p><?php endif; ?>
    <?php if ($c && $c['status'] === 'rejected'): ?><p class="error">Not approved<?= $c['note'] ? ': ' . e($c['note']) : '.' ?> Please upload it again.</p><?php endif; ?>
    <?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?>
      <label>Front of your driver's license <span class="hint">On a phone, tap the button and take a photo. A PDF scan also works.</span>
        <input type="file" name="license" accept="image/*,application/pdf" required></label>
      <div><button class="btn primary">Upload license</button></div>
    </form>
  <?php endif; ?>
</div>
<?php page_end();
