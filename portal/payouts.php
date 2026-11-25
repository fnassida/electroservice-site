<?php
// Tech payout setup. Stripe collects identity and bank details on its own pages; this site never sees them.
require __DIR__ . '/../private/bootstrap.php';
$u = require_login('tech');
$uid = (int)$u['id'];
$self = '/portal/payouts.php';

function onboarding_link(array $u, string $self): ?string {
    $acct = stripe_ensure_account($u);
    if (!$acct) return null;
    $link = stripe_api('POST', 'account_links', [
        'account' => $acct,
        'type' => 'account_onboarding',
        'refresh_url' => site_link($self . '?refresh=1'),
        'return_url' => site_link($self . '?return=1'),
    ]);
    return $link['url'] ?? null;
}

if (stripe_enabled()) {
    if (isset($_GET['refresh'])) {   // Stripe's link expired; make a new one.
        if ($url = onboarding_link($u, $self)) redirect($url);
        flash('Stripe could not start payout setup. Try again in a few minutes.');
        redirect($self);
    }
    if (isset($_GET['return']) && ($r = stripe_account_row($uid))) {
        stripe_refresh_account($r['account_id']);
        $n = tech_can_be_paid($uid) ? stripe_release_waiting($uid) : 0;
        flash(tech_can_be_paid($uid)
            ? 'Payouts are set up.' . ($n ? " $n waiting payment" . ($n > 1 ? 's were' : ' was') . ' sent to you.' : '')
            : 'Stripe still needs a few details before you can be paid. Use Continue setup to finish.');
        redirect($self);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (($_POST['action'] ?? '') === 'dashboard' && ($r = stripe_account_row($uid))) {
            $l = stripe_api('POST', 'accounts/' . rawurlencode($r['account_id']) . '/login_links');
            if (!empty($l['url'])) redirect($l['url']);
            flash('Stripe could not open your dashboard. Finish payout setup first.');
            redirect($self);
        }
        if ($url = onboarding_link($u, $self)) { log_activity($uid, 'payout_setup_started'); redirect($url); }
        flash('Stripe could not start payout setup. Try again in a few minutes.');
        redirect($self);
    }
}

$acct = stripe_account_row($uid);
$ready = tech_can_be_paid($uid);
$st = db()->prepare("SELECT p.*, w.title, w.status AS wo_status FROM payments p JOIN work_orders w ON w.id = p.work_order_id
                     WHERE w.tech_id = ? AND p.status IN ('paid','disputed','refunded') ORDER BY p.id DESC LIMIT 100");
$st->execute([$uid]);
$rows = $st->fetchAll();
$sent = array_sum(array_map(fn($r) => $r['payout_status'] === 'sent' ? (int)$r['amount_cents'] : 0, $rows));
$held = array_sum(array_map(fn($r) => $r['status'] === 'paid' && $r['payout_status'] !== 'sent' ? (int)$r['amount_cents'] : 0, $rows));

page_start('Payouts', $u);
?>
<h1>Payouts</h1>
<?php if (!stripe_enabled()): ?>
  <p>Online payments aren't switched on yet. You'll be asked to set up payouts here once they are.</p>
<?php else: ?>
  <?php if (stripe_test_mode()): ?><p class="notice">Test mode: no real money moves. Use Stripe's test details when it asks.</p><?php endif; ?>
  <?php if ($ready): ?>
    <p>Payouts are set up. When a provider approves your work, the job price goes to your Stripe account and then to your bank on Stripe's normal schedule.</p>
    <form method="post"><?= csrf_field() ?><button class="btn plain" name="action" value="dashboard">Open my Stripe payout dashboard</button></form>
  <?php else: ?>
    <p>Providers pay Electro Service when they book you, and the job price is sent to you when they approve your work.
      To receive it, set up a free Stripe account. Stripe asks for your name, date of birth, the last 4 digits of your SSN
      (or your EIN) and the bank account for deposits. Electro Service never sees those details.</p>
    <form method="post"><?= csrf_field() ?><button class="btn primary" name="action" value="setup"><?= $acct ? 'Continue setup' : 'Set up payouts' ?></button></form>
  <?php endif; ?>
<?php endif; ?>

<ul class="stats" style="margin-top:2rem">
  <li><b><?= money($sent) ?></b> paid to you</li>
  <li><b><?= money($held) ?></b> held for jobs not yet approved</li>
</ul>
<?php if ($rows): ?>
<table>
  <thead><tr><th>Job</th><th>Amount</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="/portal/jobs/view.php?id=<?= (int)$r['work_order_id'] ?>">#<?= (int)$r['work_order_id'] ?> <?= e($r['title']) ?></a></td>
      <td><?= money((int)$r['amount_cents']) ?></td>
      <td><?php
        if ($r['status'] === 'refunded') echo 'Refunded to the provider';
        elseif ($r['status'] === 'disputed') echo 'On hold: the provider\'s bank disputed the payment';
        elseif ($r['payout_status'] === 'sent') echo 'Paid ' . e(substr((string)$r['transferred_at'], 0, 10));
        elseif ($r['wo_status'] === 'approved') echo $ready ? 'Sending' : 'Waiting for your payout setup';
        else echo 'Held until the work is approved';
      ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php page_end();
