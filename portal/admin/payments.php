<?php
// Admin: payments, payouts, refunds and Stripe settings.
require __DIR__ . '/../../private/bootstrap.php';
$admin = require_login('admin');
$aid = (int)$admin['id'];
$self = '/portal/admin/payments.php';
$mode = stripe_test_mode() ? 'test' : 'live';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['action'] ?? '';
    $woId = (int)($_POST['wo_id'] ?? 0);
    if ($a === 'webhook' && stripe_enabled()) {
        if ($old = setting('stripe_webhook_id')) stripe_api('DELETE', 'webhook_endpoints/' . rawurlencode($old));
        $wh = stripe_api('POST', 'webhook_endpoints', [
            'url' => site_link('/portal/stripe/webhook.php'),
            'enabled_events' => STRIPE_EVENTS,
            'description' => 'Electro Service work board',
            'connect' => 'false',
        ]);
        if (!empty($wh['secret'])) {
            set_setting('stripe_webhook_id', $wh['id']);
            set_setting('stripe_webhook_secret', $wh['secret']);
            set_setting('stripe_webhook_mode', $mode);
            log_activity($aid, 'stripe_webhook_created', $wh['id'] . ' ' . $mode);
            flash('Stripe will now notify the site about payments (' . $mode . ' mode).');
        } else {
            flash('Stripe refused: ' . ($wh['error']['message'] ?? 'unknown error'));
        }
    } elseif ($a === 'connected_webhook' && stripe_enabled()) {
        // account.updated for connected accounts arrives on a separate Connect endpoint.
        if ($old = setting('stripe_connect_webhook_id')) stripe_api('DELETE', 'webhook_endpoints/' . rawurlencode($old));
        $wh = stripe_api('POST', 'webhook_endpoints', [
            'url' => site_link('/portal/stripe/webhook.php?connect=1'),
            'enabled_events' => ['account.updated'],
            'description' => 'Electro Service tech payout accounts',
            'connect' => 'true',
        ]);
        if (!empty($wh['secret'])) {
            set_setting('stripe_connect_webhook_id', $wh['id']);
            set_setting('stripe_connect_webhook_secret', $wh['secret']);
            flash('Stripe will now tell the site when techs finish payout setup.');
        } else {
            flash('Stripe refused: ' . ($wh['error']['message'] ?? 'unknown error'));
        }
    } elseif ($a === 'tax') {
        set_setting('stripe_tax', !empty($_POST['on']) ? '1' : '0');
        log_activity($aid, 'stripe_tax_' . (!empty($_POST['on']) ? 'on' : 'off'));
        flash(!empty($_POST['on']) ? 'Sales tax will be added at checkout.' : 'Sales tax is off.');
    } elseif ($a === 'refund' && $woId) {
        $msg = stripe_refund($woId, mb_substr(trim($_POST['reason'] ?? 'Refunded by admin'), 0, 200) ?: 'Refunded by admin', $aid);
        flash($msg);
    } elseif ($a === 'payout' && $woId) {
        $w = load_wo($woId);
        if ($w && $w['tech_id'] && ($r = stripe_account_row((int)$w['tech_id']))) stripe_refresh_account($r['account_id']);
        flash(stripe_release_payout($woId));
    }
    redirect($self);
}

$rows = db()->query("SELECT p.*, w.title, w.status AS wo_status, pu.company, tu.full_name AS tech_name
                     FROM payments p JOIN work_orders w ON w.id = p.work_order_id
                     LEFT JOIN users pu ON pu.id = p.provider_id LEFT JOIN users tu ON tu.id = w.tech_id
                     WHERE p.status <> 'expired' ORDER BY p.id DESC LIMIT 300")->fetchAll();
$sum = fn(string $col, array $statuses) => array_sum(array_map(fn($r) => in_array($r['status'], $statuses, true) ? (int)$r[$col] : 0, $rows));
$fees = $sum('fee_cents', ['paid', 'disputed']);
$tax = $sum('tax_cents', ['paid', 'disputed']);
$held = array_sum(array_map(fn($r) => $r['status'] === 'paid' && $r['payout_status'] !== 'sent' ? (int)$r['amount_cents'] : 0, $rows));
$whMode = setting('stripe_webhook_mode');

page_start('Payments', $admin);
?>
<h1>Payments</h1>
<?php if (!stripe_enabled()): ?>
  <p class="error">Stripe isn't connected. Add <code>stripe_secret_key</code> to private/config.php.</p>
<?php else: ?>
  <p>Stripe is connected in <strong><?= $mode ?> mode</strong>.<?= $mode === 'test' ? ' No real money moves.' : '' ?></p>
  <?php if (!stripe_webhook_secret() || $whMode !== $mode): ?>
    <p class="error">Stripe can't notify the site about payments yet<?= $whMode && $whMode !== $mode ? ' in ' . $mode . ' mode' : '' ?>. Payments still record when providers return from checkout, but refunds and disputes made in Stripe won't show here until you turn this on.</p>
  <?php endif; ?>
  <form method="post" class="inline"><?= csrf_field() ?>
    <button class="btn <?= stripe_webhook_secret() && $whMode === $mode ? 'plain' : 'primary' ?>" name="action" value="webhook"><?= stripe_webhook_secret() && $whMode === $mode ? 'Reconnect payment notifications' : 'Turn on payment notifications' ?></button></form>
  <form method="post" class="inline"><?= csrf_field() ?>
    <button class="btn plain" name="action" value="connected_webhook"><?= setting('stripe_connect_webhook_id') ? 'Reconnect tech payout notifications' : 'Turn on tech payout notifications' ?></button></form>

  <h2>Sales tax</h2>
  <form method="post" class="stack" style="max-width:40rem"><?= csrf_field() ?><input type="hidden" name="action" value="tax">
    <label class="check"><input type="checkbox" name="on" value="1" <?= stripe_tax_on() ? 'checked' : '' ?>> Add sales tax at checkout with Stripe Tax</label>
    <p class="hint">Turn this on only after your head office address, a default product tax code and your tax registrations are saved in Stripe under Settings, Tax. Checkout fails if they're missing. Ask your accountant which of your services are taxable.</p>
    <div><button class="btn plain">Save</button></div>
  </form>
<?php endif; ?>

<ul class="stats" style="margin-top:2rem">
  <li><b><?= money($fees) ?></b> platform fees collected</li>
  <li><b><?= money($held) ?></b> held for techs</li>
  <li><b><?= money($tax) ?></b> sales tax collected</li>
</ul>

<?php if (!$rows): ?>
  <p class="muted">No payments yet.</p>
<?php else: ?>
<table>
  <thead><tr><th>Job</th><th>Provider</th><th>Tech</th><th>Charged</th><th>Fee</th><th>Status</th><th>Payout</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="/portal/jobs/view.php?id=<?= (int)$r['work_order_id'] ?>">#<?= (int)$r['work_order_id'] ?> <?= e($r['title']) ?></a></td>
      <td><?= e($r['company']) ?></td>
      <td><?= e($r['tech_name']) ?></td>
      <td><?= money((int)$r['total_cents']) ?><?= (int)$r['tax_cents'] ? '<br><span class="muted">' . money((int)$r['tax_cents']) . ' tax</span>' : '' ?></td>
      <td><?= money((int)$r['fee_cents']) ?></td>
      <td><?= e(ucfirst($r['status'])) ?></td>
      <td><?= e(['none' => '', 'waiting' => 'Waiting on tech setup', 'sent' => 'Sent ' . substr((string)$r['transferred_at'], 0, 10), 'failed' => 'Failed'][$r['payout_status']] ?? $r['payout_status']) ?></td>
      <td>
        <?php if ($r['status'] === 'paid' && $r['payout_status'] !== 'sent'): ?>
          <?php if ($r['wo_status'] === 'approved'): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="wo_id" value="<?= (int)$r['work_order_id'] ?>">
              <button class="link" name="action" value="payout">Send payout</button></form>
          <?php endif; ?>
          <form method="post" class="inline" onsubmit="return confirm('Refund <?= money((int)$r['total_cents']) ?> to the provider?')"><?= csrf_field() ?>
            <input type="hidden" name="wo_id" value="<?= (int)$r['work_order_id'] ?>">
            <button class="link" name="action" value="refund">Refund</button></form>
        <?php endif; ?>
        <?php if ($r['payment_intent_id']): ?>
          <a href="https://dashboard.stripe.com/<?= $mode === 'test' ? 'test/' : '' ?>payments/<?= e($r['payment_intent_id']) ?>" target="_blank" rel="noopener">Stripe</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php page_end();
