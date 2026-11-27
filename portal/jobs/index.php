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
  <div class="head-row"><h1>Your jobs</h1><span><a class="btn plain" href="/portal/jobs/templates.php">Templates</a> <a class="btn primary" href="/portal/jobs/new.php">Post a job</a></span></div>
  <?php
    // Status board: one row per work order, coloured by where it stands.
    $nowLocal = local_now();
    $techNames = [];
    foreach ($all as $r) if ($r['tech_id']) $techNames[(int)$r['tech_id']] = null;
    if ($techNames) {
        $q = db()->query('SELECT id, full_name FROM users WHERE id IN (' . implode(',', array_map('intval', array_keys($techNames))) . ')');
        foreach ($q->fetchAll() as $t) $techNames[(int)$t['id']] = $t['full_name'];
    }
    $showDone = isset($_GET['all']);
    // Finished jobs stay hidden unless asked for, except ones with unread messages.
    $rows = $showDone ? $all : array_values(array_filter($all, fn($r) => !in_array($r['status'], ['approved', 'cancelled'], true) || unread_count($uid, (int)$r['id']) > 0));
    $counts = ['onsite' => 0, 'late' => 0, 'review' => 0];
    foreach ($active as $r) {
        if ($r['status'] === 'in_progress') $counts['onsite']++;
        if ($r['status'] === 'assigned' && $r['start_at'] < $nowLocal) $counts['late']++;
        if ($r['status'] === 'submitted') $counts['review']++;
    }
  ?>
  <ul class="stats">
    <li class="st-onsite"><b><?= $counts['onsite'] ?></b> tech<?= $counts['onsite'] === 1 ? '' : 's' ?> on site now</li>
    <li class="st-late"><b><?= $counts['late'] ?></b> past start time, not checked in</li>
    <li class="st-review"><b><?= $counts['review'] ?></b> waiting for your approval</li>
  </ul>
  <p class="board-tools"><input type="search" id="boardfilter" placeholder="Filter by job, site, tech or status" aria-label="Filter work orders">
    <a href="?<?= $showDone ? '' : 'all=1' ?>"><?= $showDone ? 'Hide finished and cancelled' : 'Show finished and cancelled too' ?></a>
    <span class="muted">Updates every minute. Times are Eastern.</span></p>
  <?php if (!$rows): ?><p class="muted">No work orders yet. <a href="/portal/jobs/new.php">Post your first job.</a></p><?php else: ?>
  <div class="board-wrap">
  <table class="board" id="board">
    <thead><tr><th data-sort="num">WO #</th><th>Job</th><th>Site</th><th data-sort="text">Scheduled</th><th>Tech</th><th>Status</th><th data-sort="text">Checked in</th><th>Completed</th><th>Messages</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $late = $r['status'] === 'assigned' && $r['start_at'] < $nowLocal;
        $cls = match (true) {
            $r['status'] === 'in_progress' => 'row-onsite',
            $late => 'row-late',
            $r['status'] === 'submitted' => 'row-review',
            $r['status'] === 'open' => 'row-open',
            in_array($r['status'], ['approved', 'cancelled'], true) => 'row-done',
            default => '',
        };
        $label = $r['status'] === 'in_progress' ? 'On site' : ($late ? 'Late, not checked in' : (WO_STATUS[$r['status']] ?? $r['status']));
        $loc = $r['checked_in_at'] ? job_location((int)$r['id'], 'check_in') : null;
        $unread = unread_count($uid, (int)$r['id']);
        $tn = $r['tech_id'] ? ($techNames[(int)$r['tech_id']] ?? '') : '';
        $link = '/portal/jobs/view.php?id=' . (int)$r['id'];
    ?>
      <tr class="<?= $cls ?>" data-href="<?= $link ?>">
        <td data-v="<?= (int)$r['id'] ?>"><a href="<?= $link ?>">WO-<?= str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT) ?></a></td>
        <td><a href="<?= $link ?>"><?= e($r['title']) ?></a><?= !empty($r['n_requests']) ? ' <span class="tag tech">' . (int)$r['n_requests'] . ' request' . ($r['n_requests'] > 1 ? 's' : '') . '</span>' : '' ?></td>
        <td><?= e($r['city'] . ', ' . $r['state']) ?></td>
        <td data-v="<?= e($r['start_at']) ?>"><?= e(date('M j, g:i a', strtotime($r['start_at']))) ?></td>
        <td><?php if ($r['tech_id']): ?><div class="tech-cell"><?= avatar_html((int)$r['tech_id'], $tn, 'avatar sm') ?><span><?= e($tn) ?><?php if ($ph = tech_phone_for_job($r, $u)): ?><br><a href="tel:<?= e($ph) ?>"><?= e(phone_display($ph)) ?></a><?php endif; ?></span></div><?php else: ?><span class="muted">Not booked</span><?php endif; ?></td>
        <td><span class="status"><?= e($label) ?></span></td>
        <td data-v="<?= e((string)$r['checked_in_at']) ?>"><?= $r['checked_in_at'] ? e(local_time($r['checked_in_at'])) . ($loc ? '<br><span class="muted">' . e(job_location_text($loc)) . '</span>' : '') : '' ?></td>
        <td><?= $r['submitted_at'] ? e(local_time($r['submitted_at'])) : '' ?></td>
        <td><?= $unread ? '<span class="tag provider">' . $unread . ' new message' . ($unread > 1 ? 's' : '') . '</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="hint">Tech contact details are confidential and for the listed job only. <a href="/terms.html">See our Terms.</a></p>
  <p class="legend"><span class="key row-onsite"></span>On site <span class="key row-late"></span>Late <span class="key row-review"></span>Waiting for approval <span class="key row-open"></span>Not booked yet</p>
  <?php endif; ?>
  <script>
  (function () {
    var t = document.getElementById('board'); if (!t) return;
    // Click anywhere on a row to open the work order.
    t.addEventListener('click', function (e) { var tr = e.target.closest('tr[data-href]'); if (tr && !e.target.closest('a')) location.href = tr.dataset.href; });
    // Filter rows as you type, and keep the filter across the automatic refresh.
    var f = document.getElementById('boardfilter');
    try { f.value = sessionStorage.getItem('boardfilter') || ''; } catch (_) {}
    function apply() { var q = f.value.toLowerCase(); t.querySelectorAll('tbody tr').forEach(function (tr) { tr.hidden = q && tr.textContent.toLowerCase().indexOf(q) < 0; }); try { sessionStorage.setItem('boardfilter', f.value); } catch (_) {} }
    f.addEventListener('input', apply); apply();
    // Sort by clicking a column heading.
    t.querySelectorAll('th').forEach(function (th, i) {
      th.tabIndex = 0; th.setAttribute('aria-sort', 'none'); th.title = 'Sort';
      function sort() {
        var asc = th.getAttribute('aria-sort') !== 'ascending';
        t.querySelectorAll('th').forEach(function (h) { h.setAttribute('aria-sort', 'none'); });
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
        var rows = Array.prototype.slice.call(t.tBodies[0].rows);
        rows.sort(function (a, b) {
          var x = a.cells[i].dataset.v || a.cells[i].textContent.trim(), y = b.cells[i].dataset.v || b.cells[i].textContent.trim();
          var nx = parseFloat(x), ny = parseFloat(y);
          var c = (th.dataset.sort === 'num' && !isNaN(nx) && !isNaN(ny)) ? nx - ny : x.localeCompare(y);
          return asc ? c : -c;
        });
        rows.forEach(function (r) { t.tBodies[0].appendChild(r); });
      }
      th.addEventListener('click', sort); th.addEventListener('keydown', function (e) { if (e.key === 'Enter') sort(); });
    });
    // Refresh every minute so check-ins show up without reloading (skipped while typing in the filter).
    setInterval(function () { if (document.activeElement !== f && !document.hidden) location.reload(); }, 60000);
  })();
  </script>
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
