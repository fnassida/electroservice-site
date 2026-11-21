<?php
require __DIR__ . '/../../private/bootstrap.php';
$u = require_login('tech');
$uid = (int)$u['id'];
$p = tech_profile($uid);
$ins = insurance_state($uid);
$ready = tech_ready($uid);

page_start('Find work', $u);
?>
<h1>Find work</h1>
<?php if (!$ready): ?>
  <p class="error">You can see jobs once your profile has a base address and your insurance is approved.
    <a href="/portal/">Finish setting up</a>.</p>
  <?php if (!$ins['insured']) echo insurance_offer_html('find_work'); ?>
<?php else:
    $mySkills = explode(',', $p['skills']);
    $rows = db()->prepare("SELECT w.*, u.company,
             (SELECT status FROM wo_requests r WHERE r.work_order_id = w.id AND r.tech_id = ? AND r.status = 'pending' ORDER BY r.id DESC LIMIT 1) AS my_pending,
             (SELECT kind FROM wo_requests r WHERE r.work_order_id = w.id AND r.tech_id = ? AND r.status = 'pending' ORDER BY r.id DESC LIMIT 1) AS my_kind
           FROM work_orders w JOIN users u ON u.id = w.provider_id
           WHERE w.status = 'open' AND w.start_at > ? AND w.min_coverage <= ?");
    $rows->execute([$uid, $uid, date('Y-m-d H:i:s'), (int)$ins['doc']['coverage_amount']]);
    $jobs = [];
    foreach ($rows->fetchAll() as $w) {
        $w['miles'] = miles_between((float)$p['lat'], (float)$p['lng'], (float)$w['lat'], (float)$w['lng']);
        if ($w['miles'] > (int)$p['radius_miles']) continue;
        $w['match'] = count(array_intersect($mySkills, explode(',', $w['skills'])));
        $jobs[] = $w;
    }
    usort($jobs, fn($a, $b) => [$b['match'] > 0, $a['miles']] <=> [$a['match'] > 0, $b['miles']]);
?>
  <p class="muted">Open jobs within <?= (int)$p['radius_miles'] ?> miles of <?= e($p['city'] . ', ' . $p['state']) ?>.
    <a href="/portal/profile.php">Change your radius</a>.</p>
  <?php if (!$jobs): ?>
    <p>No open jobs in your area right now. Check back soon.</p>
  <?php else: ?>
  <ul class="jobs">
    <?php foreach ($jobs as $w): ?>
      <li>
        <div class="dist"><b><?= round($w['miles']) ?></b> mi</div>
        <div>
          <h3><a href="/portal/jobs/view.php?id=<?= (int)$w['id'] ?>"><?= e($w['title']) ?></a></h3>
          <p><?= e($w['city'] . ', ' . $w['state']) ?>. <?= e(fmt_start($w['start_at'])) ?><?= $w['est_hours'] ? e(', about ' . (float)$w['est_hours'] . ' hours') : '' ?>. Posted by <?= e($w['company']) ?>.</p>
          <p class="muted"><?= e(skill_labels($w['skills'])) ?><?= $w['match'] ? '' : ' (not in your listed skills)' ?></p>
        </div>
        <div class="pay"><b><?= money((int)$w['pay_cents']) ?></b>
          <?php if ($w['my_pending']): ?><span class="tag tech"><?= $w['my_kind'] === 'offer' ? 'Offered to you' : 'Requested' ?></span><?php endif; ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
<?php endif;
page_end();
