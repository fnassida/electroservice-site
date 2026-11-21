<?php
require __DIR__ . '/../../private/bootstrap.php';
$u = require_login('provider');
$w = load_wo((int)($_GET['id'] ?? 0));
if (!$w || (int)$w['provider_id'] !== (int)$u['id']) { http_response_code(404); exit('Not found'); }
$woId = (int)$w['id'];
$back = '/portal/jobs/techs.php?id=' . $woId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!require_verified_action($u)) redirect($back);
    $techId = (int)($_POST['tech_id'] ?? 0);
    $st = db()->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'tech' AND status = 'active'");
    $st->execute([$techId]);
    if ($w['status'] !== 'open') flash('This job is no longer open.');
    elseif (!$st->fetch() || !tech_ready($techId)) flash('That tech can\'t take jobs right now.');
    elseif (pending_request($woId, $techId)) flash('That tech already has an offer or request open for this job.');
    else {
        db()->prepare("INSERT INTO wo_requests (work_order_id, tech_id, kind, note, created_at) VALUES (?,?,?,?,?)")
            ->execute([$woId, $techId, 'offer', mb_substr(trim($_POST['note'] ?? ''), 0, 500) ?: null, now()]);
        log_activity((int)$u['id'], 'offer_sent', "#$woId to tech $techId");
        flash('Offer sent. The tech will see it in their jobs.');
    }
    redirect($back);
}

$needSkills = explode(',', $w['skills']);
$onlyMatch = !isset($_GET['all']);
$rows = db()->query("SELECT u.id, u.full_name, t.* FROM users u JOIN tech_profiles t ON t.user_id = u.id
                     WHERE u.role = 'tech' AND u.status = 'active' AND t.lat IS NOT NULL")->fetchAll();
$techs = [];
foreach ($rows as $t) {
    $ins = insurance_state((int)$t['user_id']);
    if (!$ins['insured'] || (int)$ins['doc']['coverage_amount'] < (int)$w['min_coverage']) continue;
    $t['miles'] = miles_between((float)$t['lat'], (float)$t['lng'], (float)$w['lat'], (float)$w['lng']);
    if ($t['miles'] > (int)$t['radius_miles']) continue;           // outside the distance the tech will travel
    $t['match'] = count(array_intersect($needSkills, explode(',', (string)$t['skills'])));
    if ($onlyMatch && !$t['match']) continue;
    $t['coverage'] = (int)$ins['doc']['coverage_amount'];
    $t['pending'] = pending_request($woId, (int)$t['user_id']);
    $techs[] = $t;
}
usort($techs, fn($a, $b) => [$b['match'], $a['miles']] <=> [$a['match'], $b['miles']]);

page_start('Find techs', $u);
?>
<p><a href="/portal/jobs/view.php?id=<?= $woId ?>">Back to the job</a></p>
<h1>Techs for "<?= e($w['title']) ?>"</h1>
<p class="muted">Insured techs whose travel radius covers <?= e($w['city'] . ', ' . $w['state']) ?><?= $onlyMatch ? ' and who list at least one of the skills this job needs' : '' ?>.
  <?= $onlyMatch ? '<a href="?id=' . $woId . '&all=1">Show all skills</a>' : '<a href="?id=' . $woId . '">Only matching skills</a>' ?></p>
<?php if ($w['status'] !== 'open'): ?>
  <p class="error">This job is no longer open, so you can't send offers.</p>
<?php elseif (!$techs): ?>
  <p>No techs match yet. As more techs join, they'll appear here.</p>
<?php else: ?>
<ul class="jobs">
  <?php foreach ($techs as $t): ?>
    <li>
      <div class="dist"><b><?= round($t['miles']) ?></b> mi</div>
      <div>
        <h3><?= e($t['full_name']) ?></h3>
        <?php if ($t['headline']): ?><p><?= e($t['headline']) ?></p><?php endif; ?>
        <p class="muted"><?= e($t['city'] . ', ' . $t['state']) ?>. Insured to $<?= number_format($t['coverage']) ?>. <?= e(skill_labels($t['skills'])) ?></p>
        <?php if ($t['certifications']): ?><p class="muted">Certifications: <?= e(str_replace("\n", ', ', trim($t['certifications']))) ?></p><?php endif; ?>
        <?php if ($t['bio']): ?><details><summary>About</summary><p><?= nl2br(e($t['bio'])) ?></p></details><?php endif; ?>
      </div>
      <div class="pay">
        <?php if ($t['pending']): ?>
          <span class="tag tech"><?= $t['pending']['kind'] === 'offer' ? 'Offer sent' : 'Requested this job' ?></span>
        <?php else: ?>
          <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="tech_id" value="<?= (int)$t['user_id'] ?>">
            <button class="btn primary">Offer job</button></form>
        <?php endif; ?>
      </div>
    </li>
  <?php endforeach; ?>
</ul>
<?php endif;
page_end();
