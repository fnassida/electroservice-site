<?php
require __DIR__ . '/../../private/bootstrap.php';
$admin = require_login('admin');
$log = db()->query("SELECT l.*, u.full_name FROM sms_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 200")->fetchAll();
$optedIn = (int)db()->query("SELECT COUNT(*) FROM sms_prefs WHERE verified_at IS NOT NULL AND opted_in_at IS NOT NULL
                             AND (opted_out_at IS NULL OR opted_out_at < opted_in_at)")->fetchColumn();
$failed = count(array_filter($log, fn($r) => str_starts_with((string)$r['status'], 'failed')));

page_start('Texts', $admin);
?>
<p><a href="/portal/admin/">Back to admin dashboard</a></p>
<h1>Texts</h1>
<ul class="stats">
  <li><b><?= sms_enabled() ? 'On' : 'Off' ?></b> texting <?= sms_enabled() ? '' : '(add the Twilio lines to config.php)' ?></li>
  <li><b><?= $optedIn ?></b> users receiving texts</li>
  <li><b><?= $failed ?></b> failed sends (latest 200)</li>
</ul>
<p class="muted">In Twilio, set the number's "A message comes in" webhook to <code>https://electroservice.net/portal/sms/inbound.php</code> (HTTP POST).</p>
<?php if (!$log): ?>
  <p class="muted">No texts yet.</p>
<?php else: ?>
<table>
  <thead><tr><th>When (UTC)</th><th>Direction</th><th>User</th><th>Number</th><th>Job</th><th>Text</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($log as $r): ?>
    <tr>
      <td><?= e(substr($r['created_at'], 0, 16)) ?></td>
      <td><?= $r['direction'] === 'out' ? 'Sent' : 'Received' ?></td>
      <td><?= e($r['full_name'] ?? 'Unknown') ?></td>
      <td><?= e($r['phone']) ?></td>
      <td><?= $r['work_order_id'] ? '<a href="/portal/jobs/view.php?id=' . (int)$r['work_order_id'] . '&thread=' . (int)$r['tech_id'] . '#chat">#' . (int)$r['work_order_id'] . '</a>' : '' ?></td>
      <td><?= e(mb_strimwidth($r['body'], 0, 120, '...')) ?></td>
      <td><?= e($r['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif;
page_end();
