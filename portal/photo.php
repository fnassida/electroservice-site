<?php
// Tech uploads or replaces the photo of themselves.
require __DIR__ . '/../private/bootstrap.php';
$u = require_login('tech');
$uid = (int)$u['id'];
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $error = tech_photo_save($_FILES['photo'] ?? [], $uid);
    if (!$error) { flash('Photo saved.'); redirect('/portal/'); }
}
$url = tech_photo_url($uid);
page_start('Your photo', $u);
?>
<div style="max-width:40rem">
  <h1>Your photo</h1>
  <p>Providers see this photo when they choose a tech, and it's printed on the work order so the site manager can check who has arrived.
    You need one before you can take jobs.</p>
  <ul>
    <li>A clear, recent photo of your face, looking at the camera.</li>
    <li>Good light, no sunglasses or hat, and only you in the picture.</li>
    <li>On a phone, tap the button below and take a selfie, or pick a photo from your library.</li>
  </ul>
  <?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($url): ?><p><img class="avatar lg" src="<?= e($url) ?>" alt="Your current photo"></p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?>
    <label><?= $url ? 'Replace your photo' : 'Your photo' ?>
      <input type="file" name="photo" accept="image/*" required></label>
    <div><button class="btn primary"><?= $url ? 'Save new photo' : 'Save photo' ?></button></div>
  </form>
</div>
<?php page_end();
