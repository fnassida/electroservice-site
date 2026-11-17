<?php
// Serves an uploaded insurance document to the admin, or to the tech who uploaded it.
require __DIR__ . '/../private/bootstrap.php';
$u = require_login();
$st = db()->prepare('SELECT * FROM insurance_docs WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$doc = $st->fetch();
if (!$doc || ($u['role'] !== 'admin' && (int)$doc['user_id'] !== (int)$u['id'])) {
    http_response_code(404);
    exit('Not found');
}
$path = upload_dir() . '/' . basename($doc['file_name']);
if (!is_file($path)) { http_response_code(404); exit('Not found'); }
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
header('Content-Type: ' . ($types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/', '_', $doc['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
