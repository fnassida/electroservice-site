<?php
require __DIR__ . '/../../private/bootstrap.php';
$admin = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['doc_id'] ?? 0);
    $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approved' : 'rejected';
    $note = trim($_POST['note'] ?? '');
    if ($decision === 'rejected' && $note === '') {
        flash('Add a short reason so the tech knows what to fix.');
        redirect('/portal/admin/insurance.php');
    }
    db()->prepare("UPDATE insurance_docs SET status = ?, admin_note = ?, reviewed_at = ? WHERE id = ? AND status = 'pending'")
        ->execute([$decision, $note !== '' ? mb_substr($note, 0, 300) : null, now(), $id]);
    log_activity((int)$admin['id'], 'insurance_' . $decision, 'document ' . $id);
    flash($decision === 'approved' ? 'Insurance approved.' : 'Insurance rejected.');
    redirect('/portal/admin/insurance.php');
}

$pending = db()->query("SELECT d.*, u.full_name, u.email FROM insurance_docs d JOIN users u ON u.id = d.user_id
                        WHERE d.status = 'pending' ORDER BY d.created_at")->fetchAll();
$recent = db()->query("SELECT d.*, u.full_name FROM insurance_docs d JOIN users u ON u.id = d.user_id
                       WHERE d.status <> 'pending' ORDER BY d.reviewed_at DESC LIMIT 50")->fetchAll();
$soon = gmdate('Y-m-d', time() + 30 * 86400);

page_start('Insurance review', $admin);
?>
<p><a href="/portal/admin/">Back to admin dashboard</a></p>
<h1>Insurance review</h1>

<h2>Waiting for review</h2>
<?php if (!$pending): ?>
  <p class="muted">Nothing to review.</p>
<?php else: foreach ($pending as $d): ?>
  <div class="review">
    <div>
      <h3><?= e($d['full_name']) ?> <span class="muted" style="font-weight:400"><?= e($d['email']) ?></span></h3>
      <p><?= e($d['carrier']) ?>, $<?= number_format((int)$d['coverage_amount']) ?> per occurrence, expires <?= e($d['expires_on']) ?>.
        Uploaded <?= e(substr($d['created_at'], 0, 16)) ?> UTC.</p>
      <p><a href="/portal/file.php?id=<?= (int)$d['id'] ?>" target="_blank">Open certificate (<?= e($d['original_name']) ?>)</a></p>
    </div>
    <form method="post" class="stack" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
      <label>Note <span class="hint">Required if you reject. The tech sees it.</span><input type="text" name="note" maxlength="300"></label>
      <div><button class="btn primary" name="decision" value="approve">Approve</button>
        <button class="btn plain" name="decision" value="reject">Reject</button></div>
    </form>
  </div>
<?php endforeach; endif; ?>

<h2 style="margin-top:2.5rem">Reviewed</h2>
<?php if (!$recent): ?>
  <p class="muted">None yet.</p>
<?php else: ?>
<table>
  <thead><tr><th>Tech</th><th>Carrier</th><th>Coverage</th><th>Expires</th><th>Decision</th><th>Note</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($recent as $d): ?>
    <tr>
      <td><?= e($d['full_name']) ?></td>
      <td><?= e($d['carrier']) ?></td>
      <td>$<?= number_format((int)$d['coverage_amount']) ?></td>
      <td><?= e($d['expires_on']) ?>
        <?php if ($d['status'] === 'approved' && $d['expires_on'] < gmdate('Y-m-d')): ?><span class="tag suspended">Expired</span>
        <?php elseif ($d['status'] === 'approved' && $d['expires_on'] <= $soon): ?><span class="tag tech">Expires soon</span><?php endif; ?></td>
      <td><?= e(ucfirst($d['status'])) ?></td>
      <td><?= e($d['admin_note']) ?></td>
      <td><a href="/portal/file.php?id=<?= (int)$d['id'] ?>" target="_blank">Open</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php page_end();
