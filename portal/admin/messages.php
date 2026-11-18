<?php
// Every conversation on the platform. Reading here never marks anything as seen.
require __DIR__ . '/../../private/bootstrap.php';
$admin = require_login('admin');
$onlyFlagged = isset($_GET['flagged']);
$rows = db()->query("SELECT m.work_order_id, m.tech_id, COUNT(*) n, SUM(m.flagged) n_flagged, MAX(m.id) last_id, MAX(m.created_at) last_at,
                       w.title, p.company, t.full_name AS tech_name
                     FROM messages m JOIN work_orders w ON w.id = m.work_order_id
                     JOIN users p ON p.id = w.provider_id JOIN users t ON t.id = m.tech_id
                     GROUP BY m.work_order_id, m.tech_id, w.title, p.company, t.full_name
                     ORDER BY last_at DESC LIMIT 300")->fetchAll();
if ($onlyFlagged) $rows = array_values(array_filter($rows, fn($r) => (int)$r['n_flagged'] > 0));
$last = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int)$r['last_id'], $rows));
    foreach (db()->query("SELECT m.id, m.body, u.full_name FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.id IN ($ids)") as $m) $last[$m['id']] = $m;
}

page_start('Messages', $admin);
?>
<h1>All conversations</h1>
<p class="muted">Read-only. Opening a conversation doesn't mark anything as seen.
  <?= $onlyFlagged ? '<a href="/portal/admin/messages.php">Show all</a>' : '<a href="?flagged=1">Only conversations sharing phone numbers or emails</a>' ?></p>
<?php if (!$rows): ?>
  <p class="muted">No messages yet.</p>
<?php else: ?>
<table>
  <thead><tr><th>Job</th><th>Provider</th><th>Tech</th><th>Messages</th><th>Latest</th><th>When (UTC)</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $lm = $last[$r['last_id']] ?? null; ?>
    <tr>
      <td><a href="/portal/jobs/view.php?id=<?= (int)$r['work_order_id'] ?>&thread=<?= (int)$r['tech_id'] ?>#chat"><?= e($r['title']) ?></a></td>
      <td><?= e($r['company']) ?></td>
      <td><?= e($r['tech_name']) ?></td>
      <td><?= (int)$r['n'] ?><?= $r['n_flagged'] ? ' <span class="tag suspended">' . (int)$r['n_flagged'] . ' with contact info</span>' : '' ?></td>
      <td><?= $lm ? e($lm['full_name'] . ': ' . mb_strimwidth($lm['body'], 0, 80, '...')) : '' ?></td>
      <td><?= e(substr($r['last_at'], 0, 16)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif;
page_end();
