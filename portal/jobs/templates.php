<?php
// Provider's saved job templates.
require __DIR__ . '/../../private/bootstrap.php';
$u = require_login('provider');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete' && ($t = job_template((int)$u['id'], (int)($_POST['id'] ?? 0)))) {
        db()->prepare('DELETE FROM job_templates WHERE id = ?')->execute([(int)$t['id']]);
        flash('Template "' . $t['name'] . '" deleted.');
    }
    redirect('/portal/jobs/templates.php');
}
$templates = job_templates((int)$u['id']);
page_start('Job templates', $u);
?>
<div class="head-row"><h1>Job templates</h1><a class="btn primary" href="/portal/jobs/new.php">Post a job</a></div>
<p>Save the parts of a job you repeat, such as the scope of work, skills, pay, requested photos and detailed instructions, then start each new work order from them.
  To make one, fill in the job form and choose <strong>Save as template only</strong>, or tick <strong>Save as a template</strong> when you post.</p>
<?php if (!$templates): ?>
  <p class="muted">No templates yet.</p>
<?php else: ?>
<table>
  <thead><tr><th>Name</th><th>Job title</th><th>Pay</th><th>Updated</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($templates as $t): $f = json_decode($t['data'], true) ?: []; ?>
    <tr>
      <td><strong><?= e($t['name']) ?></strong></td>
      <td><?= e($f['title'] ?? '') ?></td>
      <td><?= ($f['pay'] ?? '') !== '' ? '$' . e($f['pay']) : '' ?></td>
      <td><?= e(substr($t['updated_at'], 0, 10)) ?></td>
      <td><a class="btn plain" href="/portal/jobs/new.php?template=<?= (int)$t['id'] ?>">Use</a>
        <form method="post" class="inline" onsubmit="return confirm('Delete this template?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="link" name="action" value="delete">Delete</button></form></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php page_end();
