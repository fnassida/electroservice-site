<?php
require __DIR__ . '/../private/bootstrap.php';
$u = require_login();
if ($u['role'] === 'admin') redirect('/portal/admin/');

page_start('Dashboard', $u);
?>
<h1>Welcome, <?= e(strtok($u['full_name'], ' ')) ?></h1>
<?php if ($u['role'] === 'tech'):
    $p = tech_profile((int)$u['id']);
    $ins = insurance_state((int)$u['id']);
    $doc = $ins['doc'];
    $profileDone = $p && $p['lat'] !== null && $p['skills'];
    if ($ins['insured'])                                   { $insText = 'Approved, expires ' . $doc['expires_on'] . ($ins['expiring_soon'] ? '. Renew soon.' : '.'); $insOk = !$ins['expiring_soon']; }
    elseif ($doc && $doc['status'] === 'pending')          { $insText = 'Uploaded, waiting for review.'; $insOk = false; }
    elseif ($doc && $doc['status'] === 'rejected')         { $insText = 'Not approved. Upload a corrected certificate.'; $insOk = false; }
    elseif ($ins['expired'])                               { $insText = 'Expired. Upload your renewal.'; $insOk = false; }
    else                                                   { $insText = 'Not uploaded yet.'; $insOk = false; }
?>
  <p>Finish these to start getting work orders.</p>
  <ol class="checklist">
    <?php $photoUrl = tech_photo_url((int)$u['id']); ?>
    <li class="<?= $photoUrl ? 'done' : '' ?>">
      <div style="display:flex;gap:1rem;align-items:center"><?php if ($photoUrl): ?><img class="avatar" src="<?= e($photoUrl) ?>" alt="Your photo"><?php endif; ?>
        <div><h3>Your photo</h3><p><?= $photoUrl ? 'Providers see this and it prints on your work orders.' : 'Take a clear selfie. Providers see it, and site managers use it to check who has arrived.' ?></p></div></div>
      <a class="btn <?= $photoUrl ? 'plain' : 'primary' ?>" href="/portal/photo.php"><?= $photoUrl ? 'Change photo' : 'Add your photo' ?></a>
    </li>
    <?php $idc = latest_id_check((int)$u['id']); $idOk = identity_verified((int)$u['id']); ?>
    <li class="<?= $idOk ? 'done' : '' ?>">
      <div><h3>Identity check</h3><p><?= $idOk ? 'Verified.'
          : ($idc && $idc['status'] === 'pending' ? 'License uploaded, waiting for review.'
          : ($idc && $idc['status'] === 'rejected' ? 'Not approved' . ($idc['note'] ? ': ' . e($idc['note']) : '') . '. Upload it again.'
          : 'Upload a photo of your driver\'s license. Only the admin sees it, and it\'s deleted once you\'re verified.')) ?></p></div>
      <?php if (!$idOk): ?><a class="btn <?= $idc && $idc['status'] === 'pending' ? 'plain' : 'primary' ?>" href="/portal/identity.php"><?= $idc && $idc['status'] === 'pending' ? 'View' : 'Upload license' ?></a><?php endif; ?>
    </li>
    <li class="<?= $profileDone ? 'done' : '' ?>">
      <div><h3>Your profile</h3>
        <p><?= $profileDone ? e('Skills, certifications and base location set. Travel radius ' . $p['radius_miles'] . ' miles from ' . $p['city'] . ', ' . $p['state'] . '.') : 'Add your skills, certifications, base address and how far you\'ll travel.' ?></p></div>
      <a class="btn <?= $profileDone ? 'plain' : 'primary' ?>" href="/portal/profile.php"><?= $profileDone ? 'Edit profile' : 'Set up profile' ?></a>
    </li>
    <li class="<?= $ins['insured'] ? 'done' : '' ?>">
      <div><h3>Insurance</h3><p><?= e($insText) ?></p></div>
      <a class="btn <?= $insOk || ($doc && $doc['status'] === 'pending') ? 'plain' : 'primary' ?>" href="/portal/insurance.php"><?= $doc ? 'View insurance' : 'Upload certificate' ?></a>
    </li>
    <?php $tc = tech_contact((int)$u['id']); ?>
    <li class="<?= $tc ? 'done' : '' ?>">
      <div><h3>Phone for calls <span class="muted" style="font-weight:400">(optional)</span></h3><p><?= $tc ? ((int)$tc['show_to_providers'] ? 'Providers can call you at ' . e(phone_display($tc['phone'])) . ' on jobs you\'re booked for.' : 'Saved, but hidden from providers.') : 'Let providers call you about jobs you\'re booked for. Shown only to that job\'s provider.' ?></p></div>
      <a class="btn plain" href="/portal/contact.php"><?= $tc ? 'Change' : 'Add phone' ?></a>
    </li>
    <?php $canPay = tech_can_be_paid((int)$u['id']); ?>
    <li class="<?= $canPay ? 'done' : '' ?>">
      <div><h3>Get paid</h3><p><?= $canPay ? 'Payouts set up. Approved jobs are paid to your bank through Stripe.'
          : (stripe_enabled() ? 'Connect your bank through Stripe so you can be paid when providers approve your work.' : 'Bank payouts through Stripe are coming soon.') ?></p></div>
      <?php if (stripe_enabled()): ?><a class="btn <?= $canPay ? 'plain' : 'primary' ?>" href="/portal/payouts.php"><?= $canPay ? 'View payouts' : 'Set up payouts' ?></a><?php endif; ?>
    </li>
  </ol>
  <?php if (!$ins['insured'] || $ins['expiring_soon']) echo insurance_offer_html('dashboard'); ?>
  <?php if ($profileDone && $ins['insured'] && $photoUrl && $idOk): ?>
    <p><a class="btn primary" href="/portal/jobs/browse.php">Find work near you</a> <a class="btn plain" href="/portal/jobs/">Your jobs and offers</a></p>
  <?php endif; ?>
<?php else:
    $st = db()->prepare("SELECT status, COUNT(*) n FROM work_orders WHERE provider_id = ? GROUP BY status");
    $st->execute([$u['id']]);
    $c = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $st = db()->prepare("SELECT COUNT(*) FROM wo_requests r JOIN work_orders w ON w.id = r.work_order_id
                         WHERE w.provider_id = ? AND w.status = 'open' AND r.kind = 'request' AND r.status = 'pending'");
    $st->execute([$u['id']]);
    $nReq = (int)$st->fetchColumn();
?>
  <p>Provider account for <strong><?= e($u['company']) ?></strong>.</p>
  <ul class="stats">
    <li><b><?= (int)($c['open'] ?? 0) ?></b> open jobs</li>
    <li><b><?= $nReq ?></b> tech requests to review</li>
    <li><b><?= (int)($c['assigned'] ?? 0) + (int)($c['in_progress'] ?? 0) ?></b> booked or in progress</li>
    <li><b><?= (int)($c['submitted'] ?? 0) ?></b> waiting for your approval</li>
  </ul>
  <p><a class="btn primary" href="/portal/jobs/new.php">Post a job</a> <a class="btn plain" href="/portal/jobs/">View your jobs</a>
    <a class="btn plain" href="/portal/logo.php"><?= provider_logo((int)$u['id']) ? 'Company logo' : 'Add your logo to work orders' ?></a></p>
  <p class="muted">Jobs must pay the tech at least $<?= number_format((float)cfg('min_job_amount', 50), 2) ?>. A <?= round((float)cfg('platform_fee_rate', 0.10) * 100) ?>% platform fee is added on top.</p>
<?php endif; ?>
<?php page_end();
