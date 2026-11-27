<?php
// A tech's photo of themselves. Visible to the tech, the admin, and providers (who choose techs by it).
require __DIR__ . '/../private/bootstrap.php';
$u = require_login();
$id = (int)($_GET['id'] ?? 0);
if (!in_array($u['role'], ['admin', 'provider'], true) && (int)$u['id'] !== $id) { http_response_code(404); exit('Not found'); }
$path = tech_photo_path($id);
if (!$path) { http_response_code(404); exit('Not found'); }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($path);
