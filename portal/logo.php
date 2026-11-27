<?php
// Provider uploads their company logo for their work orders. ?show=1 serves it to the provider and admin.
require __DIR__ . '/../private/bootstrap.php';
$u = require_login('provider');
$uid = (int)$u['id'];
if (isset($_GET['show'])) {
    $l = provider_logo($uid);
    if (!$l) { http_response_code(404); exit('Not found'); }
    header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=300'); header('X-Content-Type-Options: nosniff');
    readfile($l['path']); exit;
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!empty($_POST['remove'])) {
        if ($l = provider_logo($uid)) @unlink($l['path']);
        db()->prepare('DELETE FROM provider_logos WHERE user_id = ?')->execute([$uid]);
        flash('Logo removed. Your work orders will show your company name instead.');
        redirect('/portal/logo.php');
    }
    $error = provider_logo_save($_FILES['logo'] ?? [], $uid);
    if (!$error) { flash('Logo saved. It now appears on your work orders.'); redirect('/portal/logo.php'); }
}
$l = provider_logo($uid);
page_start('Company logo', $u);
?>
<div style="max-width:40rem">
  <h1>Company logo</h1>
  <p>Your logo is printed large at the top of every work order for your jobs, so site managers see your brand. Electro Service appears small, as the platform that provides the work order.</p>
  <?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($l): ?>
    <p><img src="/portal/logo.php?show=1&amp;v=<?= strtotime($l['updated_at']) ?>" alt="Your current logo" style="max-width:100%;max-height:8rem;border:1px solid var(--line);background:#fff;padding:.5rem"></p>
  <?php else: ?>
    <p class="muted">No logo yet. Work orders show "<?= e($u['company']) ?>" in large type instead.</p>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?>
    <label><?= $l ? 'Replace your logo' : 'Upload your logo' ?> <span class="hint">PNG or JPG, at least 300 pixels wide. A wide logo on a white or transparent background prints best.</span>
      <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required></label>
    <div style="display:flex;gap:.6rem;flex-wrap:wrap"><button class="btn primary">Save logo</button>
      <?php if ($l): ?><button class="btn plain" name="remove" value="1" formnovalidate>Remove logo</button><?php endif; ?></div>
  </form>
</div>
<?php page_end();
