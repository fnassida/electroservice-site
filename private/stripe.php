<?php
// Stripe Connect for the work board.
// Money flow ("separate charges and transfers"):
//   1. The provider pays job price + platform fee (+ sales tax if Stripe Tax is on) through Stripe Checkout.
//      The money sits in the platform's Stripe balance.
//   2. When the provider approves the work, the job price is transferred to the tech's connected account.
//   3. If the job is cancelled before payout, the provider is refunded in full.
// The platform is responsible for fees and losses on the connected accounts.

function stripe_enabled(): bool { return (bool)cfg('stripe_secret_key'); }
function stripe_test_mode(): bool { return str_starts_with((string)cfg('stripe_secret_key'), 'sk_test_') || str_starts_with((string)cfg('stripe_secret_key'), 'rk_test_'); }

// Minimal Stripe API client (no Composer on shared hosting).
function stripe_api(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array {
    if (!stripe_enabled()) return ['error' => ['message' => 'Payments are not set up yet.']];
    $base = cfg('stripe_api_base', 'https://api.stripe.com');
    $url = $base . '/v1/' . ltrim($path, '/');
    $body = http_build_query($params);
    if ($method === 'GET' && $body !== '') { $url .= '?' . $body; $body = ''; }
    $headers = ['Authorization: Bearer ' . cfg('stripe_secret_key'), 'Content-Type: application/x-www-form-urlencoded'];
    if ($idempotencyKey) $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['error' => ['message' => 'Could not reach Stripe: ' . $err]];
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) return ['error' => ['message' => 'Unexpected response from Stripe (HTTP ' . $code . ').']];
    if ($code >= 400 && !isset($j['error'])) $j['error'] = ['message' => 'Stripe returned HTTP ' . $code];
    if (isset($j['error'])) log_activity(null, 'stripe_error', $method . ' ' . $path . ': ' . ($j['error']['message'] ?? '?'));
    return $j;
}

function stripe_tax_on(): bool { return setting('stripe_tax', '0') === '1'; }

// ---------------------------------------------------------------- tech payout accounts
function stripe_account_row(int $userId): ?array {
    $st = db()->prepare('SELECT * FROM stripe_accounts WHERE user_id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

function tech_can_be_paid(int $userId): bool {
    $r = stripe_account_row($userId);
    return $r && (int)$r['transfers_active'] === 1 && (int)$r['payouts_enabled'] === 1;
}

// Creates the tech's connected account the first time. The platform controls fees and losses,
// Stripe collects the identity and bank details, and the tech gets the Express dashboard.
function stripe_ensure_account(array $user): ?string {
    $r = stripe_account_row((int)$user['id']);
    if ($r) return $r['account_id'];
    $acct = stripe_api('POST', 'accounts', [
        'country' => 'US',
        'email'   => $user['email'],
        'controller' => [
            'fees'                   => ['payer' => 'application'],
            'losses'                 => ['payments' => 'application'],
            'stripe_dashboard'       => ['type' => 'express'],
            'requirement_collection' => 'stripe',
        ],
        'capabilities' => ['transfers' => ['requested' => 'true']],
        'business_profile' => ['product_description' => 'Independent IT and low-voltage field technician'],
        'metadata' => ['user_id' => (string)$user['id']],
    ], 'acct-user-' . $user['id']);
    if (empty($acct['id'])) return null;
    db()->prepare('INSERT INTO stripe_accounts (user_id, account_id, transfers_active, payouts_enabled, details_submitted, updated_at) VALUES (?,?,0,0,0,?)')
        ->execute([(int)$user['id'], $acct['id'], now()]);
    log_activity((int)$user['id'], 'stripe_account_created', $acct['id']);
    return $acct['id'];
}

function stripe_save_account_status(array $acct): void {
    if (empty($acct['id'])) return;
    $transfers = ($acct['capabilities']['transfers'] ?? '') === 'active' ? 1 : 0;
    db()->prepare('UPDATE stripe_accounts SET transfers_active = ?, payouts_enabled = ?, details_submitted = ?, updated_at = ? WHERE account_id = ?')
        ->execute([$transfers, !empty($acct['payouts_enabled']) ? 1 : 0, !empty($acct['details_submitted']) ? 1 : 0, now(), $acct['id']]);
}

function stripe_refresh_account(string $accountId): void {
    $acct = stripe_api('GET', 'accounts/' . rawurlencode($accountId));
    stripe_save_account_status($acct);
}

// ---------------------------------------------------------------- job payments
function job_payment(int $woId): ?array {
    $st = db()->prepare("SELECT * FROM payments WHERE work_order_id = ? AND status <> 'expired' ORDER BY id DESC LIMIT 1");
    $st->execute([$woId]);
    return $st->fetch() ?: null;
}

function job_is_paid(int $woId): bool {
    $p = job_payment($woId);
    return $p && in_array($p['status'], ['paid', 'disputed'], true);
}

// Starts a Checkout session for the provider and returns the URL to send them to.
function stripe_start_checkout(array $wo, array $provider): array {
    $existing = job_payment((int)$wo['id']);
    if ($existing && $existing['status'] !== 'pending') return ['error' => 'This job is already paid.'];
    $woId = (int)$wo['id'];
    $line = fn(string $name, int $cents) => [
        'quantity' => 1,
        'price_data' => array_filter([
            'currency' => 'usd',
            'unit_amount' => $cents,
            'product_data' => ['name' => $name],
            'tax_behavior' => stripe_tax_on() ? 'exclusive' : null,
        ], fn($v) => $v !== null),
    ];
    $params = [
        'mode' => 'payment',
        'customer_email' => $provider['email'],
        'client_reference_id' => (string)$woId,
        'line_items' => [
            $line('Job #' . $woId . ': ' . mb_substr($wo['title'], 0, 120), (int)$wo['pay_cents']),
            $line('Electro Service platform fee', (int)$wo['fee_cents']),
        ],
        'payment_intent_data' => [
            'transfer_group' => 'WO-' . $woId,
            'description' => 'Work order #' . $woId,
            'metadata' => ['work_order_id' => (string)$woId],
        ],
        'metadata' => ['work_order_id' => (string)$woId],
        'success_url' => site_link('/portal/jobs/pay.php?id=' . $woId . '&session={CHECKOUT_SESSION_ID}'),
        'cancel_url'  => site_link('/portal/jobs/view.php?id=' . $woId),
    ];
    if (stripe_tax_on()) {
        $params['automatic_tax'] = ['enabled' => 'true'];
        $params['billing_address_collection'] = 'required';
    }
    $s = stripe_api('POST', 'checkout/sessions', $params);
    if (empty($s['id'])) return ['error' => 'Stripe could not start the payment: ' . ($s['error']['message'] ?? 'unknown error')];
    if ($existing) {
        db()->prepare("UPDATE payments SET status = 'expired', updated_at = ? WHERE id = ?")->execute([now(), $existing['id']]);
    }
    db()->prepare("INSERT INTO payments (work_order_id, provider_id, tech_id, checkout_session_id, amount_cents, fee_cents, tax_cents, total_cents, status, payout_status, created_at, updated_at)
                   VALUES (?,?,?,?,?,?,0,?,'pending','none',?,?)")
        ->execute([$woId, (int)$wo['provider_id'], $wo['tech_id'] ? (int)$wo['tech_id'] : null, $s['id'],
                   (int)$wo['pay_cents'], (int)$wo['fee_cents'], (int)$wo['pay_cents'] + (int)$wo['fee_cents'], now(), now()]);
    log_activity((int)$provider['id'], 'checkout_started', "#$woId " . $s['id']);
    return ['url' => $s['url']];
}

// Records a completed Checkout session. Safe to call more than once (return page and webhook both call it).
function stripe_record_session(string $sessionId): ?array {
    $s = stripe_api('GET', 'checkout/sessions/' . rawurlencode($sessionId), ['expand' => ['payment_intent']]);
    if (empty($s['id'])) return null;
    $st = db()->prepare('SELECT * FROM payments WHERE checkout_session_id = ?');
    $st->execute([$s['id']]);
    $pay = $st->fetch();
    if (!$pay) return null;
    if ($pay['status'] !== 'pending' && $pay['status'] !== 'expired') return $pay;
    if (($s['payment_status'] ?? '') !== 'paid') return $pay;
    $pi = $s['payment_intent'] ?? null;
    $piId = is_array($pi) ? $pi['id'] : $pi;
    $chargeId = is_array($pi) ? ($pi['latest_charge'] ?? null) : null;
    if (is_array($chargeId)) $chargeId = $chargeId['id'];
    $tax = (int)($s['total_details']['amount_tax'] ?? 0);
    $upd = db()->prepare("UPDATE payments SET status = 'paid', payment_intent_id = ?, charge_id = ?, tax_cents = ?, total_cents = ?, paid_at = ?, updated_at = ?
                          WHERE id = ? AND status IN ('pending','expired')");
    $upd->execute([$piId, $chargeId, $tax, (int)($s['amount_total'] ?? $pay['total_cents']), now(), now(), $pay['id']]);
    if ($upd->rowCount()) {
        log_activity((int)$pay['provider_id'], 'job_paid', '#' . $pay['work_order_id'] . ' ' . money((int)($s['amount_total'] ?? 0)));
        $wo = load_wo((int)$pay['work_order_id']);
        if ($wo && $wo['tech_id']) {
            $t = db()->prepare('SELECT email FROM users WHERE id = ?'); $t->execute([$wo['tech_id']]);
            send_mail((string)$t->fetchColumn(), 'Payment secured for job #' . $wo['id'],
                $wo['company'] . " has paid for \"" . $wo['title'] . "\". The money is held by Electro Service and is sent to you when the provider approves your work.\n\n"
                . site_link('/portal/jobs/view.php?id=' . $wo['id']));
        }
    }
    $st->execute([$s['id']]);
    return $st->fetch() ?: null;
}

// Sends the job price to the tech after approval. Returns a short status message.
function stripe_release_payout(int $woId): string {
    $pay = job_payment($woId);
    $wo = load_wo($woId);
    if (!$pay || !$wo) return 'No payment found for this job.';
    if ($pay['status'] !== 'paid') return 'The payment is ' . $pay['status'] . ', so nothing was sent.';
    if ($pay['payout_status'] === 'sent') return 'Already paid out.';
    if ($wo['status'] !== 'approved') return 'The work has not been approved yet.';
    $techId = (int)$wo['tech_id'];
    if (!tech_can_be_paid($techId)) {
        db()->prepare("UPDATE payments SET payout_status = 'waiting', updated_at = ? WHERE id = ?")->execute([now(), $pay['id']]);
        return 'Waiting for the tech to finish payout setup.';
    }
    $acct = stripe_account_row($techId);
    $params = [
        'amount' => (int)$pay['amount_cents'],
        'currency' => 'usd',
        'destination' => $acct['account_id'],
        'transfer_group' => 'WO-' . $woId,
        'description' => 'Work order #' . $woId,
        'metadata' => ['work_order_id' => (string)$woId],
    ];
    if ($pay['charge_id']) $params['source_transaction'] = $pay['charge_id'];
    $tr = stripe_api('POST', 'transfers', $params, 'wo-' . $woId . '-payout-' . $pay['id']);
    if (empty($tr['id'])) {
        db()->prepare("UPDATE payments SET payout_status = 'failed', updated_at = ? WHERE id = ?")->execute([now(), $pay['id']]);
        return 'Stripe refused the payout: ' . ($tr['error']['message'] ?? 'unknown error');
    }
    db()->prepare("UPDATE payments SET payout_status = 'sent', transfer_id = ?, transferred_at = ?, updated_at = ? WHERE id = ?")
        ->execute([$tr['id'], now(), now(), $pay['id']]);
    log_activity($techId, 'payout_sent', "#$woId " . money((int)$pay['amount_cents']) . ' ' . $tr['id']);
    $t = db()->prepare('SELECT email FROM users WHERE id = ?'); $t->execute([$techId]);
    send_mail((string)$t->fetchColumn(), 'You were paid ' . money((int)$pay['amount_cents']) . ' for job #' . $woId,
        "The provider approved your work on \"" . $wo['title'] . "\". " . money((int)$pay['amount_cents'])
        . " is on its way to your bank on your normal Stripe payout schedule.\n\n" . site_link('/portal/payouts.php'));
    return 'Paid out ' . money((int)$pay['amount_cents']) . ' to the tech.';
}

// Pays out every approved job that was waiting on this tech's payout setup.
function stripe_release_waiting(?int $techId = null): int {
    $sql = "SELECT p.work_order_id FROM payments p JOIN work_orders w ON w.id = p.work_order_id
            WHERE p.status = 'paid' AND p.payout_status IN ('waiting','none') AND w.status = 'approved'";
    $args = [];
    if ($techId) { $sql .= ' AND w.tech_id = ?'; $args[] = $techId; }
    $st = db()->prepare($sql); $st->execute($args);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $woId) {
        if (str_starts_with(stripe_release_payout((int)$woId), 'Paid out')) $n++;
    }
    return $n;
}

// Full refund to the provider. Only before the tech has been paid.
function stripe_refund(int $woId, string $reason, ?int $byUser = null): string {
    $pay = job_payment($woId);
    if (!$pay || $pay['status'] !== 'paid') return 'Nothing to refund.';
    if ($pay['payout_status'] === 'sent') return 'The tech has already been paid, so this needs to be handled in the Stripe Dashboard.';
    $r = stripe_api('POST', 'refunds', ['payment_intent' => $pay['payment_intent_id'], 'metadata' => ['work_order_id' => (string)$woId, 'reason' => mb_substr($reason, 0, 200)]],
                    'wo-' . $woId . '-refund-' . $pay['id']);
    if (empty($r['id'])) return 'Stripe refused the refund: ' . ($r['error']['message'] ?? 'unknown error');
    db()->prepare("UPDATE payments SET status = 'refunded', refunded_at = ?, updated_at = ? WHERE id = ?")->execute([now(), now(), $pay['id']]);
    log_activity($byUser, 'job_refunded', "#$woId " . money((int)$pay['total_cents']) . ' ' . $reason);
    return 'Refunded ' . money((int)$pay['total_cents']) . ' to the provider.';
}

// ---------------------------------------------------------------- webhooks
function stripe_webhook_secret(bool $connect = false): ?string {
    return $connect ? (cfg('stripe_connect_webhook_secret') ?: setting('stripe_connect_webhook_secret'))
                    : (cfg('stripe_webhook_secret') ?: setting('stripe_webhook_secret'));
}

function stripe_signature_ok(string $payload, string $header, string $secret, int $tolerance = 300): bool {
    $t = null; $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') $t = $v;
        if ($k === 'v1') $sigs[] = $v;
    }
    if (!$t || !ctype_digit($t) || !$sigs || abs(time() - (int)$t) > $tolerance) return false;
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($sigs as $s) if (hash_equals($expected, $s)) return true;
    return false;
}

const STRIPE_EVENTS = [
    'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.expired',
    'charge.refunded', 'charge.dispute.created', 'charge.dispute.closed', 'account.updated',
];

function stripe_handle_event(array $ev): void {
    $type = $ev['type'] ?? '';
    $obj = $ev['data']['object'] ?? [];
    switch ($type) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            if (!empty($obj['id'])) stripe_record_session($obj['id']);
            break;
        case 'checkout.session.expired':
            db()->prepare("UPDATE payments SET status = 'expired', updated_at = ? WHERE checkout_session_id = ? AND status = 'pending'")->execute([now(), $obj['id'] ?? '']);
            break;
        case 'charge.refunded':
            if (!empty($obj['refunded'])) {
                db()->prepare("UPDATE payments SET status = 'refunded', refunded_at = COALESCE(refunded_at, ?), updated_at = ? WHERE payment_intent_id = ? AND status <> 'refunded'")
                    ->execute([now(), now(), $obj['payment_intent'] ?? '']);
            }
            break;
        case 'charge.dispute.created':
            db()->prepare("UPDATE payments SET status = 'disputed', updated_at = ? WHERE payment_intent_id = ? OR charge_id = ?")
                ->execute([now(), $obj['payment_intent'] ?? '', $obj['charge'] ?? '']);
            log_activity(null, 'payment_disputed', ($obj['charge'] ?? '') . ' ' . money((int)($obj['amount'] ?? 0)) . ' reason: ' . ($obj['reason'] ?? '?'));
            send_mail((string)cfg('mail_from', 'info@electroservice.net'), 'A provider disputed a payment', "Stripe reported a dispute on charge " . ($obj['charge'] ?? '') . " for " . money((int)($obj['amount'] ?? 0)) . ". Respond in the Stripe Dashboard before the deadline.");
            break;
        case 'charge.dispute.closed':
            $status = ($obj['status'] ?? '') === 'won' ? 'paid' : 'refunded';
            db()->prepare("UPDATE payments SET status = ?, updated_at = ? WHERE (payment_intent_id = ? OR charge_id = ?) AND status = 'disputed'")
                ->execute([$status, now(), $obj['payment_intent'] ?? '', $obj['charge'] ?? '']);
            break;
        case 'account.updated':
            stripe_save_account_status($obj);
            $st = db()->prepare('SELECT user_id FROM stripe_accounts WHERE account_id = ?'); $st->execute([$obj['id'] ?? '']);
            if ($uid = $st->fetchColumn()) stripe_release_waiting((int)$uid);
            break;
    }
}
