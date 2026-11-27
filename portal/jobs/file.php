<?php
// Serves a job's completion photo or signed work order to the provider, the assigned tech, or the admin.
require __DIR__ . '/../../private/bootstrap.php';
$u = require_login();
$st = db()->prepare('SELECT * FROM job_files WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$f = $st->fetch();
$w = $f ? load_wo((int)$f['work_order_id']) : null;
if (!$f || !$w || !can_see_job_files($u, $w)) { http_response_code(404); exit('Not found'); }
$path = job_files_dir() . '/' . basename($f['file_name']);
if (!is_file($path)) { http_response_code(404); exit('Not found'); }
$mime = in_array($f['mime'], ['image/jpeg', 'image/png', 'application/pdf'], true) ? $f['mime'] : 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="job' . (int)$w['id'] . '-' . preg_replace('/[^\w.\-]/', '_', $f['original_name']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
