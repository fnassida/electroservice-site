<?php
// Stripe webhook. Stripe signs every request; anything without a valid signature is refused.
require __DIR__ . '/../../private/bootstrap.php';
$payload = file_get_contents('php://input') ?: '';
$secret = stripe_webhook_secret(isset($_GET['connect']));
if (!$secret || !stripe_signature_ok($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $secret)) {
    http_response_code(400); exit('Bad signature');
}
$ev = json_decode($payload, true);
if (!is_array($ev) || empty($ev['id'])) { http_response_code(400); exit('Bad payload'); }
// Process each event once, even if Stripe retries it.
try {
    db()->prepare('INSERT INTO stripe_events (id, type, received_at) VALUES (?,?,?)')->execute([$ev['id'], (string)($ev['type'] ?? ''), now()]);
} catch (PDOException $e) {
    http_response_code(200); exit('Already processed');
}
try {
    stripe_handle_event($ev);
} catch (Throwable $e) {
    // Let Stripe retry later.
    db()->prepare('DELETE FROM stripe_events WHERE id = ?')->execute([$ev['id']]);
    error_log('stripe webhook: ' . $e->getMessage());
    http_response_code(500); exit('Error');
}
http_response_code(200);
echo 'ok';
