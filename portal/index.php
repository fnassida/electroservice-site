<?php
require __DIR__ . '/../private/bootstrap.php';
$u = require_login();
if ($u['role'] === 'admin') redirect('/portal/admin/');

page_start('Dashboard', $u);
?>
<h1>Welcome, <?= e(strtok($u['full_name'], ' ')) ?></h1>
<?php if ($u['role'] === 'tech'): ?>
  <p>Your tech account is set up. Next, you'll be able to:</p>
  <ul class="steps-todo">
    <li>Add your skills, certifications and the area you cover</li>
    <li>Upload your certificate of insurance for approval</li>
    <li>Connect a bank account through Stripe so you can be paid</li>
    <li>Browse and request work orders near you</li>
  </ul>
<?php else: ?>
  <p>Your provider account for <strong><?= e($u['company']) ?></strong> is set up. Next, you'll be able to:</p>
  <ul class="steps-todo">
    <li>Post work orders (minimum $<?= number_format((float)cfg('min_job_amount', 50), 2) ?>)</li>
    <li>Search insured techs by skill and distance, and offer them jobs</li>
    <li>Chat and text with techs about each job</li>
    <li>Pay securely through the site when the work is done</li>
  </ul>
<?php endif; ?>
<p class="muted">These features are being built now.</p>
<?php page_end();
