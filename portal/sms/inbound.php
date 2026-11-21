<?php
// Twilio calls this for every text sent to the business number.
// Set it as the "A message comes in" webhook (HTTP POST) on the number in Twilio:
//   https://electroservice.net/portal/sms/inbound.php
require __DIR__ . '/../../private/bootstrap.php';

function twiml(?string $reply = null): never {
    header('Content-Type: text/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response>' . ($reply !== null ? '<Message>' . htmlspecialchars($reply, ENT_XML1) . '</Message>' : '') . '</Response>';
    exit;
}

if (!sms_enabled() || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(404); exit; }

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$url = cfg('twilio_webhook_url', $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''));
if (!twilio_signature_ok($url, $_POST, (string)($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? ''))) {
    http_response_code(403);
    exit('Bad signature');
}

$from = normalize_us_phone((string)($_POST['From'] ?? '')) ?? (string)($_POST['From'] ?? '');
$body = trim((string)($_POST['Body'] ?? ''));
$word = strtoupper(preg_replace('/[^A-Za-z]/', '', $body));

$st = db()->prepare('SELECT p.*, u.full_name, u.status FROM sms_prefs p JOIN users u ON u.id = p.user_id WHERE p.phone = ? ORDER BY p.updated_at DESC LIMIT 1');
$st->execute([$from]);
$p = $st->fetch() ?: null;
$uid = $p ? (int)$p['user_id'] : null;
sms_log($uid, 'in', $from, $body);

// Twilio already answers STOP, START and HELP itself; we just keep our records in step.
if (in_array($word, SMS_STOP_WORDS, true)) {
    if ($p) {
        db()->prepare('UPDATE sms_prefs SET opted_out_at = ?, updated_at = ? WHERE user_id = ?')->execute([now(), now(), $uid]);
        log_activity($uid, 'sms_opt_out', 'replied ' . $word);
    }
    twiml();
}
if (in_array($word, SMS_START_WORDS, true)) {
    if ($p && $p['verified_at']) {
        db()->prepare('UPDATE sms_prefs SET opted_in_at = ?, opted_out_at = NULL, updated_at = ? WHERE user_id = ?')->execute([now(), now(), $uid]);
        log_activity($uid, 'sms_opt_in', 'replied ' . $word);
    }
    twiml();
}
if ($word === 'HELP' || $word === 'INFO') twiml();

if (!$p || $p['status'] !== 'active' || !sms_can_text($p)) {
    twiml('Electro Service: this number only relays job messages for signed-up users who turned texts on at electroservice.net.');
}

// The reply belongs to the job thread we most recently texted this person about.
$st = db()->prepare("SELECT work_order_id, tech_id FROM sms_log WHERE user_id = ? AND direction = 'out' AND work_order_id IS NOT NULL ORDER BY id DESC LIMIT 1");
$st->execute([$uid]);
$t = $st->fetch();
$wo = $t ? load_wo((int)$t['work_order_id']) : null;
$st = db()->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$uid]);
$user = $st->fetch();
if (!$wo || !can_chat($user, $wo, (int)$t['tech_id']) || $wo['status'] === 'cancelled') {
    twiml('Electro Service: we couldn\'t match your text to an open job conversation. Reply in the job chat at electroservice.net instead.');
}
if ($body === '' && empty($_POST['NumMedia'])) twiml();

post_message($wo, (int)$t['tech_id'], $uid, $body !== '' ? $body : '[Picture or file sent by text. Ask them to upload it in the job chat.]', 'sms');
log_activity($uid, 'message_sent', '#' . $wo['id'] . ' thread ' . $t['tech_id'] . ' by text');
twiml();
