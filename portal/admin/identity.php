<?php
// Admin: compare each tech's driver's license with their photo and account name, then approve or reject.
require __DIR__ . '/../../private/bootstrap.php';
$admin = require_login('admin');
$self = '/portal/admin/identity.php';

if (isset($_GET['file'])) {   // the license image itself, admin only
    $st = db()->prepare("SELECT * FROM id_checks WHERE id = ? AND file_name IS NOT NULL"); $st->execute([(int)$_GET['file']]);
    $c = $st->fetch();
    $path = $c ? id_dir() . '/' . basename($c['file_name']) : null;
    if (!$path || !is_file($path)) { http_response_code(404); exit('Not found'); }
    header('Content-Type: ' . ($c['mime'] === 'application/pdf' ? 'application/pdf' : 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare("SELECT c.*, u.email, u.full_name FROM id_checks c JOIN users u ON u.id = c.user_id WHERE c.id = ? AND c.status = 'pending'");
    $st->execute([(int)($_POST['check_id'] ?? 0)]);
    if ($c = $st->fetch()) {
        $approve = ($_POST['decision'] ?? '') === 'approve';
        $note = mb_substr(trim($_POST['note'] ?? ''), 0, 500) ?: null;
        if (!$approve && !$note) { flash('Say why it was not approved, so the tech can fix it.'); redirect($self); }
        db()->prepare('UPDATE id_checks SET status = ?, note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?')
            ->execute([$approve ? 'approved' : 'rejected', $note, (int)$admin['id'], now(), (int)$c['id']]);
        id_check_purge((int)$c['id']);   // the image is not kept either way
        log_activity((int)$admin['id'], $approve ? 'id_approved' : 'id_rejected', $c['full_name'] . ($note ? ': ' . $note : ''));
        send_mail($c['email'], $approve ? 'Your identity is verified' : 'Please upload your license again',
            $approve ? "Thanks, {$c['full_name']}. Your identity check is approved and the license image has been deleted.\n\n" . site_link('/portal/')
                     : "We couldn't approve your identity check: $note\n\nPlease upload a new photo of your license:\n" . site_link('/portal/identity.php'));
        flash($approve ? 'Approved. The license image was deleted.' : 'Rejected. The tech has been emailed and the image was deleted.');
    }
    redirect($self);
}

$pending = db()->query("SELECT c.*, u.full_name, u.email, u.created_at AS joined FROM id_checks c JOIN users u ON u.id = c.user_id
                        WHERE c.status = 'pending' ORDER BY c.created_at")->fetchAll();
$done = db()->query("SELECT c.*, u.full_name FROM id_checks c JOIN users u ON u.id = c.user_id
                     WHERE c.status IN ('approved','rejected') ORDER BY c.reviewed_at DESC LIMIT 50")->fetchAll();
page_start('Identity checks', $admin);
?>
<p><a href="/portal/admin/">Back to admin dashboard</a></p>
<h1>Identity checks</h1>
<p>Check that the license is real and current, that the face matches the tech's photo, and that the name matches the account. Approving or rejecting deletes the license image.</p>
<h2>Waiting for review</h2>
<?php if (!$pending): ?><p class="muted">Nothing to review.</p><?php endif; ?>
<?php foreach ($pending as $c): ?>
  <div class="review">
    <div>
      <p><strong><?= e($c['full_name']) ?></strong> <span class="muted"><?= e($c['email']) ?>, joined <?= e(substr($c['joined'], 0, 10)) ?></span></p>
      <?php if ($c['mime'] === 'application/pdf'): ?>
        <p><a class="btn plain" href="?file=<?= (int)$c['id'] ?>" target="_blank" rel="noopener">Open license (PDF)</a></p>
      <?php else: ?>
        <a href="?file=<?= (int)$c['id'] ?>" target="_blank" rel="noopener"><img src="?file=<?= (int)$c['id'] ?>" alt="Driver's license of <?= e($c['full_name']) ?>" style="max-width:100%;border:1px solid var(--line);border-radius:4px"></a>
      <?php endif; ?>
    </div>
    <div>
      <p class="muted">Their photo</p>
      <?= avatar_html((int)$c['user_id'], $c['full_name'], 'avatar lg') ?: '<p class="error">No photo yet. Ask them to add one before approving.</p>' ?>
      <form method="post" class="stack" style="margin-top:1rem"><?= csrf_field() ?><input type="hidden" name="check_id" value="<?= (int)$c['id'] ?>">
        <label>Note <span class="hint">Required if you reject, e.g. "Photo is blurry" or "Name doesn't match".</span><input type="text" name="note" maxlength="500"></label>
        <div style="display:flex;gap:.6rem"><button class="btn primary" name="decision" value="approve">Approve</button>
          <button class="btn plain" name="decision" value="reject">Reject</button></div>
      </form>
    </div>
  </div>
<?php endforeach; ?>
<h2>Reviewed</h2>
<?php if (!$done): ?><p class="muted">None yet.</p><?php else: ?>
<table><thead><tr><th>Tech</th><th>Result</th><th>Note</th><th>Reviewed (UTC)</th></tr></thead><tbody>
<?php foreach ($done as $c): ?>
  <tr><td><?= e($c['full_name']) ?></td><td><?= $c['status'] === 'approved' ? 'Verified' : 'Rejected' ?></td><td><?= e($c['note']) ?></td><td><?= e(substr((string)$c['reviewed_at'], 0, 16)) ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php endif; ?>
<?php page_end();
