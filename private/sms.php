<?php
// Texting through Twilio. Everyone texts the one business number; the site relays
// messages into the job chat, so nobody ever sees anyone else's phone number.

function sms_enabled(): bool {
    return cfg('twilio_sid') && cfg('twilio_token') && cfg('twilio_number');
}

// Accepts US numbers in any common format. Returns +1XXXXXXXXXX or null.
function normalize_us_phone(string $raw): ?string {
    $d = preg_replace('/\D/', '', $raw);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    if (strlen($d) !== 10 || in_array($d[0], ['0', '1'], true)) return null;
    return '+1' . $d;
}

function sms_prefs(int $userId): ?array {
    $st = db()->prepare('SELECT * FROM sms_prefs WHERE user_id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

// Texts go only to verified numbers that opted in and haven't opted out since.
function sms_can_text(?array $p): bool {
    return $p && $p['verified_at'] && $p['opted_in_at']
        && (!$p['opted_out_at'] || $p['opted_out_at'] < $p['opted_in_at']);
}

function twilio_send(string $to, string $body): array {
    if (!sms_enabled()) return ['ok' => false, 'error' => 'Texting is not configured'];
    $base = cfg('twilio_api_base', 'https://api.twilio.com');
    $url = $base . '/2010-04-01/Accounts/' . rawurlencode((string)cfg('twilio_sid')) . '/Messages.json';
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
        'header' => "Authorization: Basic " . base64_encode(cfg('twilio_sid') . ':' . cfg('twilio_token')) . "\r\n"
                  . "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['From' => cfg('twilio_number'), 'To' => $to, 'Body' => $body]),
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $j = json_decode((string)$raw, true) ?: [];
    $ok = !empty($j['sid']);
    return ['ok' => $ok, 'sid' => $j['sid'] ?? null, 'status' => $j['status'] ?? null,
            'error' => $ok ? null : ($j['message'] ?? 'No response from Twilio')];
}

function sms_log(?int $userId, string $dir, string $phone, string $body, ?int $woId = null, ?int $techId = null, ?string $sid = null, ?string $status = null): void {
    db()->prepare('INSERT INTO sms_log (user_id, direction, phone, work_order_id, tech_id, body, twilio_sid, status, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$userId, $dir, $phone, $woId, $techId, $body, $sid, $status, now()]);
}

// Text the other side of a job chat about a new message, if they've opted in.
function sms_relay_message(array $wo, int $techId, int $senderId, string $body): void {
    if (!sms_enabled()) return;
    $recipientId = $senderId === (int)$wo['provider_id'] ? $techId : (int)$wo['provider_id'];
    $p = sms_prefs($recipientId);
    if (!sms_can_text($p)) return;
    $st = db()->prepare('SELECT full_name, company, role FROM users WHERE id = ?');
    $st->execute([$senderId]);
    $s = $st->fetch();
    $from = $s['role'] === 'provider' && $s['company'] ? $s['company'] : strtok((string)$s['full_name'], ' ');
    $preview = mb_strlen($body) > 300 ? mb_substr($body, 0, 297) . '...' : $body;
    $text = "Electro Service: $from re job #{$wo['id']}: $preview\nReply to answer. Reply STOP to opt out.";
    $r = twilio_send($p['phone'], $text);
    sms_log($recipientId, 'out', $p['phone'], $text, (int)$wo['id'], $techId, $r['sid'] ?? null, $r['ok'] ? ($r['status'] ?? 'queued') : 'failed: ' . $r['error']);
}

// Twilio signs every webhook. Reject anything that isn't really from Twilio.
function twilio_signature_ok(string $url, array $params, string $sig): bool {
    ksort($params);
    $data = $url;
    foreach ($params as $k => $v) $data .= $k . $v;
    $expected = base64_encode(hash_hmac('sha1', $data, (string)cfg('twilio_token'), true));
    return hash_equals($expected, $sig);
}

const SMS_STOP_WORDS  = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT', 'REVOKE'];
const SMS_START_WORDS = ['START', 'YES', 'UNSTOP', 'OPTIN'];
