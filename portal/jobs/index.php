<?php
require __DIR__ . '/../../private/bootstrap.php';
$u = require_login();
if ($u['role'] === 'admin') redirect('/portal/admin/jobs.php');
$uid = (int)$u['id'];

function job_rows(array $rows, string $role): void {
    if (!$rows) { echo '<p class="muted">None.</p>'; return; }
    echo '<table><thead><tr><th>Job</th><th>Where</th><th>Starts</th><th>' . ($role === 'provider' ? 'Tech pay' : 'Your pay') . '</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $extra = '';
        global $uid;
        if ($n = unread_count($uid, (int)$r['id'])) $extra .= ' <span class="tag provider">' . $n . ' new message' . ($n > 1 ? 's' : '') . '</span>';
        if ($role === 'provider' && !empty($r['n_requests'])) $extra = ' <span class="tag tech">' . (int)$r['n_requests'] . ' request' . ($r['n_requests'] > 1 ? 's' : '') . '</span>';
        echo '<tr><td><a href="/portal/jobs/view.php?id=' . (int)$r['id'] . '">' . e($r['title']) . '</a>' . $extra . '</td>'
           . '<td>' . e($r['city'] . ', ' . $r['state']) . '</td><td>' . e(fmt_start($r['start_at'])) . '</td>'
           . '<td>' . money((int)$r['pay_cents']) . '</td><td>' . e(WO_STATUS[$r['status']] ?? $r['status']) . '</td></tr>';
    }
    echo '</tbody></table>';
}

page_start('My jobs', $u);
if ($u['role'] === 'provider'):
    $st = db()->prepare("SELECT w.*, (SELECT COUNT(*) FROM wo_requests r WHERE r.work_order_id = w.id AND r.kind = 'request' AND r.status = 'pending') n_requests
                         FROM work_orders w WHERE w.provider_id = ? ORDER BY w.start_at");
    $st->execute([$uid]);
    $all = $st->fetchAll();
    $active = array_filter($all, fn($r) => !in_array($r['status'], ['approved', 'cancelled'], true));
    $done = array_filter($all, fn($r) => in_array($r['status'], ['approved', 'cancelled'], true));
?>
  <div class="head-row"><h1>Your jobs</h1><a class="btn primary" href="/portal/jobs/new.php">Post a job</a></div>
  <h2>Active</h2><?php job_rows(array_values($active), 'provider'); ?>
  <h2 style="margin-top:2rem">Finished and cancelled</h2><?php job_rows(array_values($done), 'provider'); ?>
<?php else:
    $offers = db()->prepare("SELECT w.* FROM wo_requests r JOIN work_orders w ON w.id = r.work_order_id
                             WHERE r.tech_id = ? AND r.kind = 'offer' AND r.status = 'pending' AND w.status = 'open' ORDER BY w.start_at");
    $offers->execute([$uid]);
    $asked = db()->prepare("SELECT w.* FROM wo_requests r JOIN work_orders w ON w.id = r.work_order_id
                            WHERE r.tech_id = ? AND r.kind = 'request' AND r.status = 'pending' AND w.status = 'open' ORDER BY w.start_at");
    $asked->execute([$uid]);
    $mine = db()->prepare("SELECT * FROM work_orders WHERE tech_id = ? ORDER BY start_at DESC");
    $mine->execute([$uid]);
    $mine = $mine->fetchAll();
    $current = array_filter($mine, fn($r) => in_array($r['status'], ['assigned', 'in_progress', 'submitted'], true));
    $past = array_filter($mine, fn($r) => !in_array($r['status'], ['assigned', 'in_progress', 'submitted'], true));
?>
  <div class="head-row"><h1>Your jobs</h1><a class="btn primary" href="/portal/jobs/browse.php">Find work</a></div>
  <h2>Offers waiting for your answer</h2><?php job_rows($offers->fetchAll(), 'tech'); ?>
  <h2 style="margin-top:2rem">Current jobs</h2><?php job_rows(array_values($current), 'tech'); ?>
  <h2 style="margin-top:2rem">Jobs you've requested</h2><?php job_rows($asked->fetchAll(), 'tech'); ?>
  <h2 style="margin-top:2rem">Past jobs</h2><?php job_rows(array_values($past), 'tech'); ?>
<?php endif;
page_end();
