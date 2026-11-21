<?php
// Admin setting: where info@electroservice.net forwards to, or turn forwarding off.
// Talks to the Hostinger API with the token in private/config.php.
require __DIR__ . '/../../private/bootstrap.php';
$admin = require_login('admin');

$token     = (string)cfg('hostinger_api_token', '');
$mailboxId = (string)cfg('mail_mailbox_id', '');
$orderId   = (string)cfg('mail_order_id', '');
$address   = (string)cfg('mail_from', 'info@electroservice.net');
$ready     = $token !== '' && $mailboxId !== '' && $orderId !== '';

function hapi(string $method, string $path, ?array $body = null): array {
    $opts = ['http' => [
        'method' => $method, 'timeout' => 15, 'ignore_errors' => true,
        'header' => "Authorization: Bearer " . cfg('hostinger_api_token') . "\r\nAccept: application/json\r\n"
                  . ($body !== null ? "Content-Type: application/json\r\n" : ''),
        'content' => $body !== null ? json_encode($body) : null,
    ]];
    $raw = @file_get_contents(cfg('hostinger_api_base', 'https://developers.hostinger.com') . $path, false, stream_context_create($opts));
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) $code = (int)$m[1];
    return ['code' => $code, 'json' => json_decode((string)$raw, true)];
}

// This mailbox's forwarders.
function my_forwarders(string $orderId, string $mailboxId): ?array {
    $r = hapi('GET', '/api/mail/v1/orders/' . rawurlencode($orderId) . '/forwarders?per_page=100');
    if ($r['code'] !== 200) return null;
    $list = $r['json']['data'] ?? [];
    return array_values(array_filter($list, function ($f) use ($mailboxId) {
        $mb = $f['mailbox']['id'] ?? $f['mailbox_id'] ?? null;
        return $mb === null || (string)$mb === $mailboxId;
    }));
}

$error = null;
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $current = my_forwarders($orderId, $mailboxId);
    if ($current === null) {
        $error = 'Hostinger didn\'t answer. Check the API token in config.php and try again.';
    } elseif ($action === 'save') {
        $dest = strtolower(trim($_POST['destination'] ?? ''));
        $keep = !empty($_POST['keep_copy']);
        if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address to forward to.';
        } elseif (strcasecmp($dest, $address) === 0) {
            $error = 'A mailbox can\'t forward to itself.';
        } else {
            foreach ($current as $f) hapi('DELETE', '/api/mail/v1/forwarders/' . rawurlencode((string)$f['id']));
            $r = hapi('POST', '/api/mail/v1/mailboxes/' . rawurlencode($mailboxId) . '/forwarders',
                      ['destination' => $dest, 'is_keep_copy_enabled' => $keep]);
            if ($r['code'] >= 200 && $r['code'] < 300) {
                log_activity((int)$admin['id'], 'mail_forward_set', $dest . ($keep ? ' (keep copy)' : ''));
                flash("Forwarding to $dest. Hostinger has sent a confirmation email there: forwarding starts once you click the link in it.");
                redirect('/portal/admin/email.php');
            }
            $error = 'Hostinger refused the change' . (!empty($r['json']['message']) ? ': ' . $r['json']['message'] : '.');
        }
    } elseif ($action === 'off') {
        foreach ($current as $f) hapi('DELETE', '/api/mail/v1/forwarders/' . rawurlencode((string)$f['id']));
        log_activity((int)$admin['id'], 'mail_forward_off');
        flash("Forwarding is off. Mail to $address now stays in that mailbox only.");
        redirect('/portal/admin/email.php');
    } elseif ($action === 'resend' && !empty($_POST['fid'])) {
        hapi('POST', '/api/mail/v1/forwarders/' . rawurlencode((string)$_POST['fid']) . '/resend-confirmation');
        flash('Confirmation email sent again.');
        redirect('/portal/admin/email.php');
    }
}

$forwarders = $ready ? my_forwarders($orderId, $mailboxId) : null;

page_start('Email forwarding', $admin);
?>
<p><a href="/portal/admin/">Back to admin dashboard</a></p>
<h1>Email forwarding</h1>
<p>Mail sent to <strong><?= e($address) ?></strong> can be forwarded to another address you already check.</p>

<?php if (!$ready): ?>
  <p class="error">Not connected to Hostinger yet. Add <code>hostinger_api_token</code>, <code>mail_order_id</code> and
    <code>mail_mailbox_id</code> to <code>private/config.php</code>.</p>
<?php else: ?>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?php if ($forwarders === null): ?>
    <p class="error">Couldn't load the current setting from Hostinger. Check the API token in config.php.</p>
  <?php else:
      $cur = $forwarders[0] ?? null;
      $confirmed = $cur ? (bool)($cur['is_confirmed'] ?? $cur['confirmed'] ?? (($cur['status'] ?? '') === 'active')) : false;
  ?>
    <h2>Right now</h2>
    <?php if (!$cur): ?>
      <p>Forwarding is <strong>off</strong>. Mail stays in the <?= e($address) ?> mailbox (read it in Hostinger webmail).</p>
    <?php else: ?>
      <p>Forwarding to <strong><?= e($cur['destination'] ?? '') ?></strong>
        <?= !empty($cur['is_keep_copy_enabled']) ? ', keeping a copy in the mailbox' : '' ?>.
        <?php if (!$confirmed): ?><br><span class="tag tech">Waiting for confirmation</span>
          Click the link Hostinger emailed to that address.
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="fid" value="<?= e((string)$cur['id']) ?>">
            <button class="link" name="action" value="resend">Send it again</button></form>
        <?php endif; ?></p>
    <?php endif; ?>

    <h2 style="margin-top:2rem"><?= $cur ? 'Change it' : 'Turn it on' ?></h2>
    <form method="post" class="stack" style="max-width:30rem">
      <?= csrf_field() ?>
      <label>Forward to <input type="email" name="destination" required value="<?= e($cur['destination'] ?? '') ?>"></label>
      <label class="check"><input type="checkbox" name="keep_copy" value="1" <?= !$cur || !empty($cur['is_keep_copy_enabled']) ? 'checked' : '' ?>>
        <span>Also keep a copy in the <?= e($address) ?> mailbox</span></label>
      <div><button class="btn primary" name="action" value="save">Save forwarding</button></div>
    </form>
    <?php if ($cur): ?>
      <form method="post" style="margin-top:1.2rem"><?= csrf_field() ?>
        <button class="btn plain" name="action" value="off">Turn forwarding off</button></form>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<?php page_end();
