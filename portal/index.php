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
    <li class="<?= $profileDone ? 'done' : '' ?>">
      <div><h3>Your profile</h3>
        <p><?= $profileDone ? e('Skills, certifications and base location set. Travel radius ' . $p['radius_miles'] . ' miles from ' . $p['city'] . ', ' . $p['state'] . '.') : 'Add your skills, certifications, base address and how far you\'ll travel.' ?></p></div>
      <a class="btn <?= $profileDone ? 'plain' : 'primary' ?>" href="/portal/profile.php"><?= $profileDone ? 'Edit profile' : 'Set up profile' ?></a>
    </li>
    <li class="<?= $ins['insured'] ? 'done' : '' ?>">
      <div><h3>Insurance</h3><p><?= e($insText) ?></p></div>
      <a class="btn <?= $insOk || ($doc && $doc['status'] === 'pending') ? 'plain' : 'primary' ?>" href="/portal/insurance.php"><?= $doc ? 'View insurance' : 'Upload certificate' ?></a>
    </li>
    <li>
      <div><h3>Get paid</h3><p>Connect a bank account through Stripe. Coming soon.</p></div>
    </li>
  </ol>
<?php else: ?>
  <p>Your provider account for <strong><?= e($u['company']) ?></strong> is set up. Next, you'll be able to:</p>
  <ul class="steps-todo">
    <li>Post work orders (minimum $<?= number_format((float)cfg('min_job_amount', 50), 2) ?>)</li>
    <li>Search insured techs by skill and distance, and offer them jobs</li>
    <li>Chat and text with techs about each job</li>
    <li>Pay securely through the site when the work is done</li>
  </ul>
  <p class="muted">These features are being built now.</p>
<?php endif; ?>
<?php page_end();
