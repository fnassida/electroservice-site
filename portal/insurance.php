<?php
require __DIR__ . '/../private/bootstrap.php';
$u = require_login('tech');
$errors = [];
const MAX_UPLOAD = 10 * 1024 * 1024;
const ALLOWED = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $carrier  = trim($_POST['carrier'] ?? '');
    $coverage = (int)preg_replace('/[^\d]/', '', (string)($_POST['coverage'] ?? ''));
    $expires  = (string)($_POST['expires_on'] ?? '');
    $f = $_FILES['certificate'] ?? null;

    if ($carrier === '' || mb_strlen($carrier) > 120) $errors[] = 'Enter the insurance company name.';
    if ($coverage < 100000) $errors[] = 'Enter your general liability coverage per occurrence, for example 1,000,000.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) || $expires <= gmdate('Y-m-d')) $errors[] = 'Enter an expiry date in the future.';

    $ext = null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Attach your certificate of insurance.';
    } elseif ($f['size'] > MAX_UPLOAD) {
        $errors[] = 'The file is larger than 10 MB.';
    } else {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = ALLOWED[$mime] ?? null;
        if (!$ext) $errors[] = 'Upload a PDF, JPG or PNG file.';
    }

    if (!$errors) {
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], upload_dir() . '/' . $name)) {
            $errors[] = 'The upload failed. Try again.';
        } else {
            db()->prepare('INSERT INTO insurance_docs (user_id, carrier, coverage_amount, expires_on, file_name, original_name, created_at)
                           VALUES (?,?,?,?,?,?,?)')
                ->execute([$u['id'], $carrier, $coverage, $expires, $name, mb_substr(basename($f['name']), 0, 190), now()]);
            log_activity((int)$u['id'], 'insurance_uploaded', $carrier . ', $' . number_format($coverage) . ', expires ' . $expires);
            flash('Certificate uploaded. You\'ll be able to take jobs once it\'s approved.');
            redirect('/portal/');
        }
    }
}

$ins = insurance_state((int)$u['id']);
$doc = $ins['doc'];
page_start('Insurance', $u);
?>
<div style="max-width:40rem">
  <h1>Insurance</h1>
  <p>You need current general liability insurance, approved by Electro Service, before providers can offer you work.</p>

  <?php if ($doc): ?>
    <p class="<?= $ins['insured'] ? 'notice' : 'error' ?>">
      <?php if ($ins['insured']): ?>
        Approved: <?= e($doc['carrier']) ?>, $<?= number_format((int)$doc['coverage_amount']) ?>, expires <?= e($doc['expires_on']) ?>.
        <?= $ins['expiring_soon'] ? 'It expires within 30 days, so upload your renewal.' : '' ?>
      <?php elseif ($doc['status'] === 'pending'): ?>
        Waiting for review: <?= e($doc['carrier']) ?>, uploaded <?= e(substr($doc['created_at'], 0, 10)) ?>.
      <?php elseif ($doc['status'] === 'rejected'): ?>
        Not approved<?= $doc['admin_note'] ? ': ' . e($doc['admin_note']) : '' ?>. Upload a corrected certificate.
      <?php else: ?>
        Your certificate expired on <?= e($doc['expires_on']) ?>. Upload your renewal.
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <?php if (!$ins['insured'] || $ins['expiring_soon']) echo insurance_offer_html('insurance_page'); ?>
  <h2 style="margin-top:2rem"><?= $doc ? 'Upload a new certificate' : 'Upload your certificate' ?></h2>
  <?php if ($errors): ?><div class="error" role="alert"><?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="stack">
    <?= csrf_field() ?>
    <label>Insurance company <input type="text" name="carrier" value="<?= e($_POST['carrier'] ?? '') ?>"></label>
    <label>General liability per occurrence <span class="hint">In dollars, e.g. 1,000,000.</span>
      <input type="text" name="coverage" inputmode="numeric" value="<?= e($_POST['coverage'] ?? '') ?>" style="max-width:14rem"></label>
    <label>Policy expiry date <input type="date" name="expires_on" value="<?= e($_POST['expires_on'] ?? '') ?>" style="max-width:14rem"></label>
    <label>Certificate of insurance <span class="hint">PDF, JPG or PNG, up to 10 MB.</span>
      <input type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></label>
    <div><button class="btn primary">Upload for review</button> <a class="btn plain" href="/portal/">Cancel</a></div>
  </form>
</div>
<?php page_end();
