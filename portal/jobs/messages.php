<?php
// Chat endpoint. GET returns new messages as JSON; POST adds one.
require __DIR__ . '/../../private/bootstrap.php';
$u = require_login();
$w = load_wo((int)($_REQUEST['id'] ?? 0));
$techId = (int)($_REQUEST['thread'] ?? 0);
$isAdmin = $u['role'] === 'admin';
if (!$w || !($isAdmin || can_chat($u, $w, $techId))) { http_response_code(404); exit('Not found'); }

function out(array $rows, int $me): array {
    return array_map(fn($m) => [
        'id' => (int)$m['id'], 'mine' => (int)$m['sender_id'] === $me, 'name' => $m['full_name'], 'role' => $m['role'],
        'body' => $m['body'], 'via' => $m['via'], 'flagged' => (bool)$m['flagged'],
        'at' => $m['created_at'] . 'Z', 'read' => $m['read_at'] !== null,
    ], $rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isAdmin) { http_response_code(403); exit; }
    csrf_check();
    if (!is_verified($u)) {
        if (($_POST['ajax'] ?? '') === '1') { http_response_code(403); exit; }
        require_verified_action($u);
        redirect('/portal/jobs/view.php?id=' . (int)$w['id'] . '&thread=' . $techId . '#chat');
    }
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body !== '') {
        post_message($w, $techId, (int)$u['id'], $body);
        log_activity((int)$u['id'], 'message_sent', '#' . $w['id'] . ' thread ' . $techId);
    }
    if (($_POST['ajax'] ?? '') !== '1') redirect('/portal/jobs/view.php?id=' . (int)$w['id'] . '&thread=' . $techId . '#chat');
}

if (!$isAdmin) mark_read($w, $techId, (int)$u['id']);   // admin reading leaves no trace
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['messages' => out(thread_messages((int)$w['id'], $techId, (int)($_REQUEST['after'] ?? 0)), (int)$u['id'])]);
