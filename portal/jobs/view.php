<?php
require __DIR__ . '/../../private/bootstrap.php';
$u = require_login();
$uid = (int)$u['id'];
$role = $u['role'];
$w = load_wo((int)($_GET['id'] ?? 0));

function not_found(): never { http_response_code(404); exit('Not found'); }
if (!$w) not_found();

$isOwner = $role === 'provider' && (int)$w['provider_id'] === $uid;
$isAssigned = $role === 'tech' && (int)$w['tech_id'] === $uid;
$myPending = $role === 'tech' ? pending_request((int)$w['id'], $uid) : null;
$miles = null;
$p = null;
if ($role === 'tech') {
    $p = tech_profile($uid);
    if ($p && $p['lat'] !== null) $miles = miles_between((float)$p['lat'], (float)$p['lng'], (float)$w['lat'], (float)$w['lng']);
}
$inRange = $miles !== null && $miles <= (int)$p['radius_miles'];
$canSee = $role === 'admin' || $isOwner || $isAssigned || $myPending
    || ($role === 'tech' && $w['status'] === 'open' && $inRange && tech_ready($uid));
if (!$canSee) not_found();

$back = '/portal/jobs/view.php?id=' . (int)$w['id'];

// ---------------------------------------------------------------- actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role !== 'admin') {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $woId = (int)$w['id'];
    $note = mb_substr(trim($_POST['note'] ?? ''), 0, 500);

    if ($isOwner) {
        if (in_array($a, ['accept_request', 'decline_request', 'withdraw_offer'], true)) {
            $st = db()->prepare("SELECT * FROM wo_requests WHERE id = ? AND work_order_id = ? AND status = 'pending'");
            $st->execute([(int)($_POST['req_id'] ?? 0), $woId]);
            $r = $st->fetch();
            if (!$r) { flash('That request is no longer open.'); redirect($back); }
            if ($a === 'accept_request') {
                if (!tech_ready((int)$r['tech_id'])) { flash('That tech\'s insurance or profile is no longer current, so they can\'t be booked.'); redirect($back); }
                if (assign_wo($woId, (int)$r['tech_id'])) { log_activity($uid, 'job_assigned', "#$woId to tech " . $r['tech_id']); flash('Tech booked for this job.'); }
                else flash('This job is no longer open.');
            } else {
                db()->prepare("UPDATE wo_requests SET status = 'declined', decided_at = ? WHERE id = ?")->execute([now(), $r['id']]);
                log_activity($uid, $a === 'withdraw_offer' ? 'offer_withdrawn' : 'request_declined', "#$woId tech " . $r['tech_id']);
                flash($a === 'withdraw_offer' ? 'Offer withdrawn.' : 'Request declined.');
            }
        } elseif ($a === 'cancel' && in_array($w['status'], ['open', 'assigned'], true)) {
            db()->prepare("UPDATE work_orders SET status = 'cancelled', cancelled_at = ?, updated_at = ? WHERE id = ?")->execute([now(), now(), $woId]);
            db()->prepare("UPDATE wo_requests SET status = 'closed', decided_at = ? WHERE work_order_id = ? AND status = 'pending'")->execute([now(), $woId]);
            log_activity($uid, 'job_cancelled', "#$woId");
            flash('Job cancelled.');
        } elseif ($a === 'approve' && $w['status'] === 'submitted') {
            db()->prepare("UPDATE work_orders SET status = 'approved', approved_at = ?, updated_at = ? WHERE id = ?")->execute([now(), now(), $woId]);
            log_activity($uid, 'job_approved', "#$woId");
            flash('Work approved.');
        } elseif ($a === 'reopen_work' && $w['status'] === 'submitted') {
            db()->prepare("UPDATE work_orders SET status = 'in_progress', updated_at = ? WHERE id = ?")->execute([now(), $woId]);
            log_activity($uid, 'job_sent_back', "#$woId " . $note);
            flash('Sent back to the tech. Tell them what still needs doing in the chat.');
        }
    } elseif ($role === 'tech') {
        if ($a === 'request' && $w['status'] === 'open' && !$myPending && $inRange && tech_ready($uid)) {
            db()->prepare("INSERT INTO wo_requests (work_order_id, tech_id, kind, note, created_at) VALUES (?,?,?,?,?)")
                ->execute([$woId, $uid, 'request', $note ?: null, now()]);
            log_activity($uid, 'job_requested', "#$woId");
            flash('Request sent. The provider will see it with your profile.');
        } elseif ($a === 'withdraw' && $myPending && $myPending['kind'] === 'request') {
            db()->prepare("UPDATE wo_requests SET status = 'closed', decided_at = ? WHERE id = ?")->execute([now(), $myPending['id']]);
            log_activity($uid, 'request_withdrawn', "#$woId");
            flash('Request withdrawn.');
        } elseif ($a === 'accept_offer' && $myPending && $myPending['kind'] === 'offer' && tech_ready($uid)) {
            if (assign_wo($woId, $uid)) { log_activity($uid, 'offer_accepted', "#$woId"); flash('You\'re booked for this job.'); }
            else flash('This job is no longer available.');
        } elseif ($a === 'decline_offer' && $myPending && $myPending['kind'] === 'offer') {
            db()->prepare("UPDATE wo_requests SET status = 'declined', decided_at = ? WHERE id = ?")->execute([now(), $myPending['id']]);
            log_activity($uid, 'offer_declined', "#$woId");
            flash('Offer declined.');
        } elseif ($a === 'check_in' && $isAssigned && $w['status'] === 'assigned') {
            db()->prepare("UPDATE work_orders SET status = 'in_progress', checked_in_at = ?, updated_at = ? WHERE id = ?")->execute([now(), now(), $woId]);
            log_activity($uid, 'checked_in', "#$woId");
            flash('Checked in.');
        } elseif ($a === 'submit' && $isAssigned && $w['status'] === 'in_progress') {
            if (mb_strlen(trim($_POST['notes'] ?? '')) < 10) { flash('Add completion notes describing what you did.'); redirect($back); }
            db()->prepare("UPDATE work_orders SET status = 'submitted', submitted_at = ?, completion_notes = ?, updated_at = ? WHERE id = ?")
                ->execute([now(), mb_substr(trim($_POST['notes']), 0, 5000), now(), $woId]);
            log_activity($uid, 'work_submitted', "#$woId");
            flash('Work submitted. The provider will review and approve it.');
        }
    }
    redirect($back);
}

// ---------------------------------------------------------------- page
$tech = null;
if ($w['tech_id']) {
    $st = db()->prepare('SELECT id, full_name, email FROM users WHERE id = ?');
    $st->execute([$w['tech_id']]);
    $tech = $st->fetch();
}
$showAddress = $role === 'admin' || $isOwner || $isAssigned;

page_start($w['title'], $u);
?>
<p><a href="<?= $role === 'admin' ? '/portal/admin/jobs.php' : ($role === 'tech' && !$isAssigned && !$myPending ? '/portal/jobs/browse.php' : '/portal/jobs/') ?>">Back</a></p>
<div class="head-row">
  <h1><?= e($w['title']) ?></h1>
  <span class="tag status-<?= e($w['status']) ?>"><?= e(WO_STATUS[$w['status']]) ?></span>
</div>

<div class="job">
  <div>
    <h2>Scope of work</h2>
    <div class="prose"><?= nl2br(e($w['description'])) ?></div>
    <?php if ($w['completion_notes']): ?>
      <h2 style="margin-top:1.6rem">Completion notes</h2>
      <div class="prose"><?= nl2br(e($w['completion_notes'])) ?></div>
    <?php endif; ?>
  </div>
  <dl class="facts-list">
    <dt>Pay to tech</dt><dd><?= money((int)$w['pay_cents']) ?></dd>
    <?php if ($isOwner || $role === 'admin'): ?>
      <dt>Platform fee</dt><dd><?= money((int)$w['fee_cents']) ?></dd>
      <dt>Provider total</dt><dd><strong><?= money((int)$w['pay_cents'] + (int)$w['fee_cents']) ?></strong></dd>
    <?php endif; ?>
    <dt>Starts</dt><dd><?= e(fmt_start($w['start_at'])) ?> (local time)</dd>
    <?php if ($w['est_hours']): ?><dt>Estimated</dt><dd><?= (float)$w['est_hours'] ?> hours</dd><?php endif; ?>
    <dt>Location</dt><dd><?= $showAddress ? e($w['street']) . '<br>' : '' ?><?= e($w['city'] . ', ' . $w['state'] . ' ' . ($showAddress ? $w['zip'] : '')) ?>
      <?= $miles !== null ? '<br><span class="muted">' . round($miles) . ' miles from you</span>' : '' ?></dd>
    <dt>Skills</dt><dd><?= e(skill_labels($w['skills'])) ?></dd>
    <?php if ($w['min_coverage']): ?><dt>Insurance</dt><dd>At least $<?= number_format((int)$w['min_coverage']) ?> per occurrence</dd><?php endif; ?>
    <dt>Provider</dt><dd><?= e($w['company']) ?></dd>
    <?php if ($tech && ($isOwner || $role === 'admin')): ?><dt>Tech</dt><dd><?= e($tech['full_name']) ?></dd><?php endif; ?>
    <?php if ($w['checked_in_at']): ?><dt>Checked in</dt><dd><?= e(substr($w['checked_in_at'], 0, 16)) ?> UTC</dd><?php endif; ?>
  </dl>
</div>

<section class="actions-panel">
<?php if ($isOwner): ?>
  <?php if ($w['status'] === 'open'):
      $reqs = db()->prepare("SELECT r.*, u.full_name, t.headline, t.city, t.state, t.lat, t.lng, t.certifications
                             FROM wo_requests r JOIN users u ON u.id = r.tech_id LEFT JOIN tech_profiles t ON t.user_id = r.tech_id
                             WHERE r.work_order_id = ? AND r.status = 'pending' ORDER BY r.kind, r.created_at");
      $reqs->execute([$w['id']]);
      $reqs = $reqs->fetchAll();
      $requests = array_filter($reqs, fn($r) => $r['kind'] === 'request');
      $offers = array_filter($reqs, fn($r) => $r['kind'] === 'offer');
  ?>
    <div class="head-row"><h2>Techs who want this job</h2><a class="btn primary" href="/portal/jobs/techs.php?id=<?= (int)$w['id'] ?>">Find techs and send offers</a></div>
    <?php if (!$requests): ?><p class="muted">No requests yet. Insured techs within range can see this job, or you can offer it to a tech directly.</p><?php endif; ?>
    <?php foreach ($requests as $r): $ri = insurance_state((int)$r['tech_id']); ?>
      <div class="review">
        <div>
          <h3><?= e($r['full_name']) ?></h3>
          <p><?= e($r['headline']) ?></p>
          <p class="muted"><?= e($r['city'] . ', ' . $r['state']) ?>, <?= round(miles_between((float)$r['lat'], (float)$r['lng'], (float)$w['lat'], (float)$w['lng'])) ?> miles from the site.
            <?= $ri['insured'] ? 'Insured, $' . number_format((int)$ri['doc']['coverage_amount']) . '.' : 'Insurance not current.' ?></p>
          <?php if ($r['certifications']): ?><p class="muted">Certifications: <?= e(str_replace("\n", ', ', trim($r['certifications']))) ?></p><?php endif; ?>
          <?php if ($r['note']): ?><p>"<?= e($r['note']) ?>"</p><?php endif; ?>
        </div>
        <form method="post" style="margin:0;display:flex;gap:.6rem;align-items:start;flex-wrap:wrap">
          <?= csrf_field() ?><input type="hidden" name="req_id" value="<?= (int)$r['id'] ?>">
          <button class="btn primary" name="action" value="accept_request">Book this tech</button>
          <button class="btn plain" name="action" value="decline_request">Decline</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if ($offers): ?>
      <h3 style="margin-top:1.5rem">Offers you've sent</h3>
      <ul class="plain-list">
      <?php foreach ($offers as $r): ?>
        <li><?= e($r['full_name']) ?>, sent <?= e(substr($r['created_at'], 0, 10)) ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="req_id" value="<?= (int)$r['id'] ?>">
            <button class="link" name="action" value="withdraw_offer">Withdraw</button></form></li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php elseif ($w['status'] === 'submitted'): ?>
    <h2>Review the completed work</h2>
    <p>The tech has marked this job complete. Read the completion notes, then approve it or send it back.</p>
    <form method="post" style="display:flex;gap:.6rem;flex-wrap:wrap"><?= csrf_field() ?>
      <button class="btn primary" name="action" value="approve">Approve work</button>
      <button class="btn plain" name="action" value="reopen_work">Send back to the tech</button>
    </form>
  <?php elseif (in_array($w['status'], ['assigned', 'in_progress'], true)): ?>
    <p><?= $w['status'] === 'assigned' ? 'Booked. Waiting for the tech to check in on site.' : 'The tech is on site and working.' ?></p>
  <?php endif; ?>
  <?php if (in_array($w['status'], ['open', 'assigned'], true)): ?>
    <form method="post" style="margin-top:1.5rem" onsubmit="return confirm('Cancel this job?')"><?= csrf_field() ?>
      <button class="link" name="action" value="cancel">Cancel this job</button></form>
  <?php endif; ?>

<?php elseif ($role === 'tech'): ?>
  <?php if ($myPending && $myPending['kind'] === 'offer' && $w['status'] === 'open'): ?>
    <h2><?= e($w['company']) ?> has offered you this job</h2>
    <form method="post" style="display:flex;gap:.6rem;flex-wrap:wrap"><?= csrf_field() ?>
      <button class="btn primary" name="action" value="accept_offer">Accept job</button>
      <button class="btn plain" name="action" value="decline_offer">Decline</button>
    </form>
  <?php elseif ($myPending && $w['status'] === 'open'): ?>
    <p>You've requested this job. The provider will decide.</p>
    <form method="post"><?= csrf_field() ?><button class="link" name="action" value="withdraw">Withdraw my request</button></form>
  <?php elseif ($w['status'] === 'open'): ?>
    <h2>Request this job</h2>
    <form method="post" class="stack" style="max-width:36rem"><?= csrf_field() ?>
      <label>Note to the provider <span class="hint">Optional. Relevant experience, availability.</span><textarea name="note" rows="3" maxlength="500"></textarea></label>
      <div><button class="btn primary" name="action" value="request">Request job</button></div>
    </form>
  <?php elseif ($isAssigned && $w['status'] === 'assigned'): ?>
    <h2>You're booked</h2>
    <p>Check in when you arrive on site.</p>
    <form method="post"><?= csrf_field() ?><button class="btn primary" name="action" value="check_in">Check in</button></form>
  <?php elseif ($isAssigned && $w['status'] === 'in_progress'): ?>
    <h2>Mark the job complete</h2>
    <form method="post" class="stack" style="max-width:40rem"><?= csrf_field() ?>
      <label>Completion notes <span class="hint">What you did, anything left outstanding, who signed off on site.</span>
        <textarea name="notes" rows="5" required></textarea></label>
      <div><button class="btn primary" name="action" value="submit">Submit for approval</button></div>
    </form>
  <?php elseif ($isAssigned && $w['status'] === 'submitted'): ?>
    <p>Submitted. Waiting for the provider to approve.</p>
  <?php elseif ($isAssigned && $w['status'] === 'approved'): ?>
    <p>Approved. Thank you.</p>
  <?php endif; ?>
<?php endif; ?>
</section>
<?php page_end();
