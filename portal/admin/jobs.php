<?php
require __DIR__ . '/../../private/bootstrap.php';
$admin = require_login('admin');
$filter = $_GET['status'] ?? '';
$sql = "SELECT w.*, p.company, t.full_name AS tech_name,
          (SELECT COUNT(*) FROM wo_requests r WHERE r.work_order_id = w.id AND r.status = 'pending') n_pending
        FROM work_orders w JOIN users p ON p.id = w.provider_id LEFT JOIN users t ON t.id = w.tech_id";
$args = [];
if (isset(WO_STATUS[$filter])) { $sql .= ' WHERE w.status = ?'; $args[] = $filter; }
$st = db()->prepare($sql . ' ORDER BY w.created_at DESC LIMIT 300');
$st->execute($args);
$jobs = $st->fetchAll();
$counts = db()->query('SELECT status, COUNT(*) n FROM work_orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$gross = (int)db()->query("SELECT COALESCE(SUM(fee_cents),0) FROM work_orders WHERE status = 'approved'")->fetchColumn();

page_start('All jobs', $admin);
?>
<h1>All jobs</h1>
<ul class="stats">
  <?php foreach (WO_STATUS as $k => $label): ?><li><b><?= (int)($counts[$k] ?? 0) ?></b> <?= e(strtolower($label)) ?></li><?php endforeach; ?>
  <li><b><?= money($gross) ?></b> fees on approved jobs</li>
</ul>
<p><a href="/portal/admin/jobs.php">All</a>
  <?php foreach (WO_STATUS as $k => $label): ?> <a href="?status=<?= e($k) ?>" style="margin-left:.8rem"><?= e($label) ?></a><?php endforeach; ?></p>
<?php if (!$jobs): ?>
  <p class="muted">No jobs yet.</p>
<?php else: ?>
<table>
  <thead><tr><th>#</th><th>Job</th><th>Provider</th><th>Tech</th><th>Where</th><th>Starts</th><th>Pay + fee</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($jobs as $j): ?>
    <tr>
      <td><?= (int)$j['id'] ?></td>
      <td><a href="/portal/jobs/view.php?id=<?= (int)$j['id'] ?>"><?= e($j['title']) ?></a><?= $j['n_pending'] ? ' <span class="tag tech">' . (int)$j['n_pending'] . ' pending</span>' : '' ?></td>
      <td><?= e($j['company']) ?></td>
      <td><?= e($j['tech_name'] ?? '') ?></td>
      <td><?= e($j['city'] . ', ' . $j['state']) ?></td>
      <td><?= e(substr($j['start_at'], 0, 16)) ?></td>
      <td><?= money((int)$j['pay_cents']) ?> + <?= money((int)$j['fee_cents']) ?></td>
      <td><?= e(WO_STATUS[$j['status']]) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif;
page_end();
