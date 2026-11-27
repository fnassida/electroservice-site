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
$payOn = stripe_enabled();
$payment = job_payment((int)$w['id']);
$paid = $payment && in_array($payment['status'], ['paid', 'disputed'], true);
$seeProof = can_see_job_files($u, $w);
$photoReqs = photo_requests((int)$w['id']);
$files = $seeProof ? job_files((int)$w['id']) : [];
$canEditRequests = $isOwner && in_array($w['status'], ['open', 'assigned', 'in_progress'], true);
$canUpload = $isAssigned && $w['status'] === 'in_progress';

// ---------------------------------------------------------------- actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role !== 'admin') {
    csrf_check();
    if (!require_verified_action($u)) redirect($back);
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
        } elseif ($a === 'pay' && $payOn && $w['status'] === 'assigned' && !$paid) {
            $res = stripe_start_checkout($w, $u);
            if (isset($res['url'])) redirect($res['url']);
            flash($res['error']);
        } elseif ($a === 'cancel' && in_array($w['status'], ['open', 'assigned'], true)) {
            $refundMsg = $paid ? stripe_refund($woId, 'Cancelled by provider', $uid) : null;
            if ($paid && !str_starts_with($refundMsg, 'Refunded')) { flash('The job was not cancelled. ' . $refundMsg); redirect($back); }
            db()->prepare("UPDATE work_orders SET status = 'cancelled', cancelled_at = ?, updated_at = ? WHERE id = ?")->execute([now(), now(), $woId]);
            db()->prepare("UPDATE wo_requests SET status = 'closed', decided_at = ? WHERE work_order_id = ? AND status = 'pending'")->execute([now(), $woId]);
            log_activity($uid, 'job_cancelled', "#$woId");
            flash('Job cancelled.' . ($refundMsg ? ' ' . $refundMsg : ''));
        } elseif ($a === 'add_photo_request' && $canEditRequests) {
            $added = 0;
            foreach (photo_request_lines((string)($_POST['label'] ?? '')) as $l) {
                if (count(photo_requests($woId)) >= 10) { flash('You can ask for at most 10 photos.'); break; }
                add_photo_request($woId, $l); $added++;
            }
            if ($added) { log_activity($uid, 'photo_requested', "#$woId"); flash($added === 1 ? 'Photo added to the list.' : "$added photos added to the list."); }
        } elseif ($a === 'save_instructions' && $canEditRequests) {
            save_job_instructions($woId, (string)($_POST['instructions'] ?? ''));
            log_activity($uid, 'instructions_saved', "#$woId");
            flash('Detailed instructions saved.' . ($w['tech_id'] ? ' The tech can see the update now.' : ''));
            redirect($back . '#instructions');
        } elseif ($a === 'remove_photo_request' && $canEditRequests) {
            $rid = (int)($_POST['request_id'] ?? 0);
            $used = array_filter($files, fn($f) => (int)$f['request_id'] === $rid);
            if ($used) flash('The tech has already uploaded that photo, so it stays on the list.');
            else { db()->prepare('DELETE FROM job_photo_requests WHERE id = ? AND work_order_id = ?')->execute([$rid, $woId]); flash('Removed from the list.'); }
        } elseif ($a === 'approve' && $w['status'] === 'submitted') {
            db()->prepare("UPDATE work_orders SET status = 'approved', approved_at = ?, updated_at = ? WHERE id = ?")->execute([now(), now(), $woId]);
            log_activity($uid, 'job_approved', "#$woId");
            $payMsg = $paid ? ' ' . stripe_release_payout($woId) : '';
            flash('Work approved.' . $payMsg);
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
            if ($payOn && !$paid) { flash('The provider hasn\'t paid for this job yet. You can check in once the payment is secured.'); redirect($back); }
            db()->prepare("UPDATE work_orders SET status = 'in_progress', checked_in_at = ?, updated_at = ? WHERE id = ?")->execute([now(), now(), $woId]);
            log_activity($uid, 'checked_in', "#$woId");
            $loc = record_job_location($w, $uid, 'check_in');
            flash('Checked in.' . ($loc ? ' Your location was shared with the provider.' : ''));
        } elseif ($a === 'upload' && $canUpload) {
            $kind = ($_POST['kind'] ?? '') === 'signoff' ? 'signoff' : 'photo';
            $rid = (int)($_POST['request_id'] ?? 0);
            $rid = $rid && array_filter($photoReqs, fn($r) => (int)$r['id'] === $rid) ? $rid : null;
            $errs = []; $n = 0;
            foreach (uploaded_list('files') as $f) {
                if (($f['error'] ?? 0) === UPLOAD_ERR_NO_FILE) continue;
                $err = job_file_save($f, $woId, $uid, $kind, $rid);
                if ($err) $errs[] = $err; else $n++;
            }
            if ($errs) flash(implode(' ', $errs) . ($n ? " $n other file" . ($n > 1 ? 's were' : ' was') . ' uploaded.' : ''));
            elseif ($n) flash($kind === 'signoff' ? 'Signed work order uploaded.' : ($n === 1 ? 'Photo uploaded.' : "$n photos uploaded."));
            else flash('Choose a photo to upload.');
            redirect($back . '#proof');
        } elseif ($a === 'delete_file' && $canUpload) {
            job_file_delete((int)($_POST['file_id'] ?? 0), $woId);
            flash('Removed.');
            redirect($back . '#proof');
        } elseif ($a === 'submit' && $isAssigned && $w['status'] === 'in_progress') {
            if (mb_strlen(trim($_POST['notes'] ?? '')) < 10) { flash('Add completion notes describing what you did.'); redirect($back); }
            if (!job_files($woId, 'photo')) { flash('Upload at least one photo of the finished work before submitting.'); redirect($back . '#proof'); }
            if ($miss = missing_photo_requests($woId)) { flash('The provider asked for photos you haven\'t uploaded yet: ' . implode('; ', array_map(fn($r) => $r['label'], $miss)) . '.'); redirect($back . '#proof'); }
            db()->prepare("UPDATE work_orders SET status = 'submitted', submitted_at = ?, completion_notes = ?, updated_at = ? WHERE id = ?")
                ->execute([now(), mb_substr(trim($_POST['notes']), 0, 5000), now(), $woId]);
            log_activity($uid, 'work_submitted', "#$woId");
            record_job_location($w, $uid, 'submit');
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

// Chat threads this user can see on this job.
$threads = [];   // techId => name
if ($role === 'tech') {
    if (can_chat($u, $w, $uid)) $threads[$uid] = $w['company'];
} else {
    $st = db()->prepare("SELECT DISTINCT u.id, u.full_name FROM users u WHERE u.id IN (
            SELECT tech_id FROM wo_requests WHERE work_order_id = ? UNION SELECT tech_id FROM messages WHERE work_order_id = ?
            UNION SELECT tech_id FROM work_orders WHERE id = ? AND tech_id IS NOT NULL)");
    $st->execute([$w['id'], $w['id'], $w['id']]);
    foreach ($st->fetchAll() as $t) $threads[(int)$t['id']] = $t['full_name'];
}
$thread = (int)($_GET['thread'] ?? 0);
if (!isset($threads[$thread])) $thread = $w['tech_id'] && isset($threads[(int)$w['tech_id']]) ? (int)$w['tech_id'] : (int)(array_key_first($threads) ?? 0);
$chatMsgs = $thread ? thread_messages((int)$w['id'], $thread) : [];
$canPost = $thread && $role !== 'admin' && !in_array($w['status'], ['cancelled'], true);
if ($thread && $role !== 'admin') mark_read($w, $thread, $uid);
$unreadBy = [];
if ($role === 'provider') {
    foreach (array_keys($threads) as $t) {
        $st = db()->prepare('SELECT COUNT(*) FROM messages WHERE work_order_id = ? AND tech_id = ? AND sender_id <> ? AND read_at IS NULL');
        $st->execute([$w['id'], $t, $uid]);
        $unreadBy[$t] = (int)$st->fetchColumn();
    }
}

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
    <?php $instr = $showAddress ? job_instructions((int)$w['id']) : null; ?>
    <?php if ($instr !== null || $canEditRequests): ?>
      <section id="instructions">
      <h2 style="margin-top:1.6rem">Detailed instructions</h2>
      <?php if ($canEditRequests): ?>
        <form method="post" class="stack"><?= csrf_field() ?>
          <label><span class="hint">Optional. Only the booked tech sees this, and it prints as a separate page after the work order.</span>
            <textarea name="instructions" rows="<?= $instr ? 10 : 4 ?>" maxlength="20000"><?= e((string)$instr) ?></textarea></label>
          <div><button class="btn plain" name="action" value="save_instructions"><?= $instr ? 'Save instructions' : 'Add instructions' ?></button></div>
        </form>
      <?php else: ?>
        <div class="prose"><?= nl2br(e($instr)) ?></div>
      <?php endif; ?>
      </section>
    <?php endif; ?>
    <?php if ($seeProof && $w['status'] !== 'cancelled'): ?>
      <p style="margin-top:1.4rem;display:flex;gap:.5rem;flex-wrap:wrap"><a class="btn plain" href="/portal/jobs/workorder.php?id=<?= (int)$w['id'] ?>" target="_blank" rel="noopener">Print work order (PDF)</a>
      <?php if ($isOwner): ?><a class="btn plain" href="/portal/jobs/new.php?copy=<?= (int)$w['id'] ?>">Post a similar job</a><?php endif; ?>
      <?php if ($w['tech_id'] && in_array($w['status'], ['assigned', 'in_progress'], true)): ?>
        <a class="btn plain" href="<?= e(google_calendar_url($w)) ?>" target="_blank" rel="noopener">Add to Google Calendar</a>
        <a class="btn plain" href="/portal/jobs/calendar.php?id=<?= (int)$w['id'] ?>">Add to Outlook or Apple Calendar</a>
      <?php endif; ?></p>
      <?php if ($w['tech_id'] && in_array($w['status'], ['assigned', 'in_progress'], true)): ?><p class="hint">The Outlook and Apple calendar file reminds you the day before and an hour before. Google Calendar uses your usual reminder settings.</p><?php endif; ?>
    <?php endif; ?>
    <?php if ($seeProof && ($photoReqs || $files || $canEditRequests || $canUpload)):
      $byReq = []; $extra = []; $signoffs = [];
      foreach ($files as $f) {
          if ($f['kind'] === 'signoff') $signoffs[] = $f;
          elseif ($f['request_id']) $byReq[(int)$f['request_id']][] = $f;
          else $extra[] = $f;
      }
      $thumb = function (array $f) use ($canUpload): string {
          $url = '/portal/jobs/file.php?id=' . (int)$f['id'];
          $h = $f['mime'] === 'application/pdf'
              ? '<a class="filecard" href="' . $url . '" target="_blank" rel="noopener">PDF: ' . e($f['original_name']) . '</a>'
              : '<a href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="' . e($f['original_name']) . '" loading="lazy"></a>';
          if ($canUpload) $h .= '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="file_id" value="' . (int)$f['id'] . '"><button class="link" name="action" value="delete_file">Remove</button></form>';
          return '<figure class="proof">' . $h . '</figure>';
      };
      $uploadForm = function (string $kind, ?int $rid, string $label) use ($canUpload): string {
          if (!$canUpload) return '';
          $accept = $kind === 'signoff' ? 'image/*,application/pdf' : 'image/*';
          return '<form method="post" enctype="multipart/form-data" class="upload">' . csrf_field()
              . '<input type="hidden" name="kind" value="' . $kind . '">' . ($rid ? '<input type="hidden" name="request_id" value="' . $rid . '">' : '')
              . '<input type="file" name="files[]" accept="' . $accept . '"' . ($kind === 'photo' && !$rid ? ' multiple' : '') . ' required aria-label="' . e($label) . '">'
              . '<button class="btn plain" name="action" value="upload">Upload</button></form>';
      };
    ?>
    <section id="proof" class="proof-wrap">
      <h2 style="margin-top:1.8rem">Completion photos</h2>
      <?php if ($canUpload): ?><p class="hint">Use your phone: tap Choose file, then take a photo or pick one from your library.</p><?php endif; ?>
      <?php if ($photoReqs): ?>
        <ul class="photo-requests">
        <?php foreach ($photoReqs as $r): $got = $byReq[(int)$r['id']] ?? []; ?>
          <li class="<?= $got ? 'done' : '' ?>">
            <div class="req-head"><strong><?= e($r['label']) ?></strong>
              <span class="tag <?= $got ? 'ok' : '' ?>"><?= $got ? 'Uploaded' : 'Needed' ?></span>
              <?php if ($canEditRequests && !$got): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><button class="link" name="action" value="remove_photo_request">Remove</button></form><?php endif; ?>
            </div>
            <?php if ($got): ?><div class="gallery"><?php foreach ($got as $f) echo $thumb($f); ?></div><?php endif; ?>
            <?= $uploadForm('photo', (int)$r['id'], 'Photo: ' . $r['label']) ?>
          </li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($canEditRequests): ?>
        <form method="post" class="inline-add"><?= csrf_field() ?>
          <label>Ask the tech for a photo <input type="text" name="label" maxlength="200" placeholder="e.g. Serial number label on the new device"></label>
          <button class="btn plain" name="action" value="add_photo_request">Add</button></form>
      <?php endif; ?>
      <h3><?= $photoReqs ? 'Other photos of the finished work' : 'Photos of the finished work' ?></h3>
      <?php if ($extra): ?><div class="gallery"><?php foreach ($extra as $f) echo $thumb($f); ?></div>
      <?php elseif (!$canUpload): ?><p class="muted">None yet.</p><?php endif; ?>
      <?= $uploadForm('photo', null, 'Photos of the finished work') ?>
      <h3>Signed work order</h3>
      <?php if ($signoffs): ?><div class="gallery"><?php foreach ($signoffs as $f) echo $thumb($f); ?></div>
      <?php elseif (!$canUpload): ?><p class="muted">Not uploaded.</p>
      <?php else: ?><p class="hint">Print the work order, have the site manager sign it, then photograph the page.</p><?php endif; ?>
      <?= $uploadForm('signoff', null, 'Signed work order') ?>
    </section>
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
      <?= $miles !== null ? '<br><span class="muted">' . round($miles) . ' miles from you</span>' : '' ?>
      <?php if ($showAddress && $w['status'] !== 'cancelled'): ?><br><a class="btn plain" style="margin-top:.4rem" target="_blank" rel="noopener"
        href="<?= e(maps_directions_url($w)) ?>">Get directions</a><?php endif; ?></dd>
    <dt>Skills</dt><dd><?= e(skill_labels($w['skills'])) ?></dd>
    <?php if ($w['min_coverage']): ?><dt>Insurance</dt><dd>At least $<?= number_format((int)$w['min_coverage']) ?> per occurrence</dd><?php endif; ?>
    <dt>Provider</dt><dd><?= e($w['company']) ?></dd>
    <?php if ($tech && ($isOwner || $role === 'admin')): ?><dt>Tech</dt><dd><?= avatar_html((int)$w['tech_id'], $tech['full_name']) ?><?= e($tech['full_name']) ?></dd><?php endif; ?>
    <?php if ($payOn && ($isOwner || $isAssigned || $role === 'admin') && $w['status'] !== 'open'): ?>
      <dt>Payment</dt><dd><?php
        if (!$payment || $payment['status'] === 'pending') echo 'Not paid yet';
        elseif ($payment['status'] === 'paid') echo $payment['payout_status'] === 'sent' ? 'Paid out to the tech' : 'Paid, held until the work is approved';
        elseif ($payment['status'] === 'refunded') echo 'Refunded to the provider';
        elseif ($payment['status'] === 'disputed') echo 'Disputed by the provider\'s bank';
        if ($payment && ($isOwner || $role === 'admin') && (int)$payment['tax_cents'] > 0) echo '<br><span class="muted">Includes ' . money((int)$payment['tax_cents']) . ' sales tax</span>';
      ?></dd>
    <?php endif; ?>
    <?php if ($w['checked_in_at']): ?><dt>Checked in</dt><dd><?= e(substr($w['checked_in_at'], 0, 16)) ?> UTC
      <?php if ($seeProof): ?><br><span class="muted"><?= e(job_location_text(job_location((int)$w['id'], 'check_in'))) ?></span><?php endif; ?></dd><?php endif; ?>
    <?php if ($w['submitted_at']): ?><dt>Completed</dt><dd><?= e(substr($w['submitted_at'], 0, 16)) ?> UTC
      <?php if ($seeProof): ?><br><span class="muted"><?= e(job_location_text(job_location((int)$w['id'], 'submit'))) ?></span><?php endif; ?></dd><?php endif; ?>
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
          <?= avatar_html((int)$r['tech_id'], $r['full_name']) ?>
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
    <p>The tech has marked this job complete. Check the completion notes, photos and signed work order, then approve it or send it back.
      <?= $paid ? 'Approving releases the tech\'s payment.' : '' ?></p>
    <?php if (!array_filter($files, fn($f) => $f['kind'] === 'signoff')): ?><p class="notice">The tech didn't upload a signed work order. Ask for one in the chat, or send the job back, if you need it.</p><?php endif; ?>
    <form method="post" style="display:flex;gap:.6rem;flex-wrap:wrap"><?= csrf_field() ?>
      <button class="btn primary" name="action" value="approve">Approve work</button>
      <button class="btn plain" name="action" value="reopen_work">Send back to the tech</button>
    </form>
  <?php elseif ($w['status'] === 'assigned' && $payOn && !$paid): ?>
    <h2>Pay to confirm the booking</h2>
    <p>Pay <?= money((int)$w['pay_cents'] + (int)$w['fee_cents']) ?><?= stripe_tax_on() ? ' plus any sales tax' : '' ?> by card or bank through Stripe.
      Electro Service holds the money and releases <?= money((int)$w['pay_cents']) ?> to the tech only after you approve the work. Cancel before then and you get a full refund.</p>
    <form method="post"><?= csrf_field() ?><button class="btn primary" name="action" value="pay">Pay <?= money((int)$w['pay_cents'] + (int)$w['fee_cents']) ?></button></form>
  <?php elseif (in_array($w['status'], ['assigned', 'in_progress'], true)): ?>
    <p><?= $w['status'] === 'assigned' ? ($payOn ? 'Booked and paid.' : 'Booked.') . ' Waiting for the tech to check in on site.' : 'The tech is on site and working.' ?></p>
  <?php endif; ?>
  <?php if (in_array($w['status'], ['open', 'assigned'], true)): ?>
    <form method="post" style="margin-top:1.5rem" onsubmit="return confirm('<?= $paid ? 'Cancel this job and refund your payment?' : 'Cancel this job?' ?>')"><?= csrf_field() ?>
      <button class="link" name="action" value="cancel">Cancel this job</button></form>
  <?php endif; ?>

<?php elseif ($role === 'tech'): ?>
  <?php if ($myPending && $myPending['kind'] === 'offer' && $w['status'] === 'open'): ?>
    <h2><?= e($w['company']) ?> has offered you this job</h2>
    <form method="post" style="display:flex;gap:.6rem;flex-wrap:wrap"><?= csrf_field() ?>
      <p class="hint" style="flex-basis:100%"><?= e(NO_SHOW_POLICY) ?></p>
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
      <p class="hint"><?= e(NO_SHOW_POLICY) ?></p>
      <div><button class="btn primary" name="action" value="request">Request job</button></div>
    </form>
  <?php elseif ($isAssigned && $w['status'] === 'assigned' && $payOn && !$paid): ?>
    <h2>You're booked</h2>
    <p>Waiting for the provider to pay. You'll get an email when the payment is secured, and then you can check in.</p>
    <?php if (!tech_can_be_paid($uid)): ?><p><a href="/portal/payouts.php">Set up payouts</a> so you can be paid when the work is approved.</p><?php endif; ?>
  <?php elseif ($isAssigned && $w['status'] === 'assigned'): ?>
    <h2>You're booked</h2>
    <p><?= $payOn ? 'Payment is secured. ' : '' ?>Check in when you arrive on site. <?= e(NO_SHOW_POLICY) ?></p>
    <form method="post" data-gps class="stack" style="max-width:40rem"><?= csrf_field() ?>
      <label class="check"><input type="checkbox" name="share_gps" value="1" checked> <span>Share my location with the provider to confirm I'm on site</span></label>
      <input type="hidden" name="gps_lat"><input type="hidden" name="gps_lng"><input type="hidden" name="gps_acc">
      <p class="hint gps-status" aria-live="polite"></p>
      <div><button class="btn primary" name="action" value="check_in">Check in</button></div></form>
  <?php elseif ($isAssigned && $w['status'] === 'in_progress'): ?>
    <h2>Mark the job complete</h2>
    <form method="post" data-gps class="stack" style="max-width:40rem"><?= csrf_field() ?>
      <?php $miss = missing_photo_requests((int)$w['id']); $havePhoto = (bool)array_filter($files, fn($f) => $f['kind'] === 'photo'); ?>
      <?php if ($miss || !$havePhoto): ?><p class="notice">Before you submit: <?= $miss ? 'upload the ' . count($miss) . ' requested photo' . (count($miss) > 1 ? 's' : '') . ' marked Needed above.' : 'upload at least one photo of the finished work above.' ?></p><?php endif; ?>
      <label>Completion notes <span class="hint">What you did, anything left outstanding, who signed off on site.</span>
        <textarea name="notes" rows="5" required></textarea></label>
      <label class="check"><input type="checkbox" name="share_gps" value="1" checked> <span>Share my location with the provider to confirm I'm on site</span></label>
      <input type="hidden" name="gps_lat"><input type="hidden" name="gps_lng"><input type="hidden" name="gps_acc">
      <p class="hint gps-status" aria-live="polite"></p>
      <div><button class="btn primary" name="action" value="submit">Submit for approval</button></div>
    </form>
  <?php elseif ($isAssigned && $w['status'] === 'submitted'): ?>
    <p>Submitted. Waiting for the provider to approve.</p>
  <?php elseif ($isAssigned && $w['status'] === 'approved'): ?>
    <p>Approved. Thank you.<?php if ($payOn && $payment && $payment['status'] === 'paid'):
        echo $payment['payout_status'] === 'sent' ? ' ' . money((int)$payment['amount_cents']) . ' has been sent to your Stripe account.'
           : ' Your ' . money((int)$payment['amount_cents']) . ' is waiting. <a href="/portal/payouts.php">Finish payout setup</a> to receive it.';
      endif; ?></p>
  <?php endif; ?>
<?php endif; ?>
</section>

<?php if ($threads): ?>
<section id="chat" class="chat-wrap">
  <h2>Messages</h2>
  <?php if ($role === 'admin'): ?><p class="muted">Read-only. Participants can't see that you've viewed this.</p><?php endif; ?>
  <?php if (count($threads) > 1 || $role !== 'tech'): ?>
    <nav class="threads" aria-label="Conversations">
      <?php foreach ($threads as $tid => $name): ?>
        <a href="?id=<?= (int)$w['id'] ?>&thread=<?= $tid ?>#chat" <?= $tid === $thread ? 'aria-current="true"' : '' ?>><?= e($name) ?><?= !empty($unreadBy[$tid]) ? ' <span class="tag tech">' . $unreadBy[$tid] . ' new</span>' : '' ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>
  <div class="chat" id="chatlog" data-job="<?= (int)$w['id'] ?>" data-thread="<?= $thread ?>" aria-live="polite">
    <?php if (!$chatMsgs): ?><p class="muted empty">No messages yet.</p><?php endif; ?>
    <?php foreach ($chatMsgs as $m): $mine = (int)$m['sender_id'] === $uid; ?>
      <div class="msg <?= $mine ? 'mine' : '' ?>" data-id="<?= (int)$m['id'] ?>">
        <div class="meta"><?= e($m['full_name']) ?><?= $m['via'] === 'sms' ? ' by text' : '' ?>, <time datetime="<?= e($m['created_at']) ?>Z"><?= e(substr($m['created_at'], 0, 16)) ?> UTC</time>
          <?php if ($role === 'admin' && $m['flagged']): ?><span class="tag suspended">Contact info</span><?php endif; ?></div>
        <div class="body"><?= nl2br(e($m['body'])) ?></div>
        <?php if ($mine && $m['read_at']): ?><div class="seen">Seen</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($canPost): ?>
    <form method="post" action="/portal/jobs/messages.php" class="chat-form" id="chatform">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="thread" value="<?= $thread ?>">
      <label class="sr" for="chatbody">Message</label>
      <textarea id="chatbody" name="body" rows="2" maxlength="4000" placeholder="Write a message" required></textarea>
      <button class="btn primary">Send</button>
    </form>
  <?php endif; ?>
</section>
<script src="/assets/chat.js" defer></script>
<?php endif; ?>
<script src="/assets/gps.js" defer></script>
<?php page_end();
