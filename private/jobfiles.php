<?php
// Completion proof for work orders: photos of the finished job and the signed work order.
// Files live in private/uploads/jobs/, outside the web root, and are served by portal/jobs/file.php.

const JOB_FILE_MAX_BYTES = 20 * 1024 * 1024;   // before resizing; phone photos can be large
const JOB_FILE_MAX_COUNT = 15;                 // per job
const JOB_PHOTO_MAX_PX = 2000;                 // longest side after resizing

function job_files_dir(): string {
    $d = upload_dir() . '/jobs';
    if (!is_dir($d)) mkdir($d, 0750, true);
    return $d;
}

function job_files(int $woId, ?string $kind = null): array {
    $sql = 'SELECT * FROM job_files WHERE work_order_id = ?' . ($kind ? ' AND kind = ?' : '') . ' ORDER BY id';
    $st = db()->prepare($sql);
    $st->execute($kind ? [$woId, $kind] : [$woId]);
    return $st->fetchAll();
}

// Who may see a job's files and print its work order.
function can_see_job_files(array $u, array $wo): bool {
    return $u['role'] === 'admin'
        || ((int)$wo['provider_id'] === (int)$u['id'])
        || ($wo['tech_id'] && (int)$wo['tech_id'] === (int)$u['id']);
}

// Re-encodes a photo: fixes phone rotation, shrinks it, and drops the hidden data
// (GPS location, camera details) that phone photos carry.
function job_photo_normalize(string $src, string $mime, string $dest): bool {
    if (!function_exists('imagecreatefromjpeg')) return false;
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($src),
        'image/png'  => @imagecreatefrompng($src),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default => false,
    };
    if (!$img) return false;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $rot = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($rot) { $r = imagerotate($img, $rot, 0); if ($r) { imagedestroy($img); $img = $r; } }
    }
    $w = imagesx($img); $h = imagesy($img);
    $scale = min(1, JOB_PHOTO_MAX_PX / max($w, $h));
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // transparent PNGs get a white background
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = imagejpeg($out, $dest, 85);
    imagedestroy($img); imagedestroy($out);
    return $ok;
}

// Saves one uploaded file. Returns an error message, or null on success.
function job_file_save(array $f, int $woId, int $userId, string $kind, ?int $requestId = null): ?string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Choose a file to upload.';
    if ($f['error'] !== UPLOAD_ERR_OK) return 'The upload of ' . basename($f['name']) . ' failed. Try again.';
    if ($f['size'] > JOB_FILE_MAX_BYTES) return basename($f['name']) . ' is larger than 20 MB.';
    if (count(job_files($woId)) >= JOB_FILE_MAX_COUNT) return 'This job already has ' . JOB_FILE_MAX_COUNT . ' files. Remove one first.';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $images = ['image/jpeg', 'image/png', 'image/webp'];
    $okTypes = $kind === 'signoff' ? array_merge($images, ['application/pdf']) : $images;
    if (in_array($mime, ['image/heic', 'image/heif'], true)) {
        return basename($f['name']) . ' is an iPhone HEIC photo. In iPhone Settings, Camera, Formats, choose "Most Compatible", or pick the photo again from the upload button.';
    }
    if (!in_array($mime, $okTypes, true)) {
        return basename($f['name']) . ($kind === 'signoff' ? ' must be a photo (JPG or PNG) or a PDF.' : ' must be a photo (JPG or PNG).');
    }
    $name = bin2hex(random_bytes(16));
    $dir = job_files_dir();
    if ($mime === 'application/pdf') {
        $name .= '.pdf';
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) return 'The upload failed. Try again.';
    } else {
        $name .= '.jpg';
        if (!job_photo_normalize($f['tmp_name'], $mime, "$dir/$name")) {
            // GD can't read it; keep the original only if it's a plain JPEG or PNG.
            $name = substr($name, 0, -4) . ($mime === 'image/png' ? '.png' : '.jpg');
            if (!in_array($mime, ['image/jpeg', 'image/png'], true) || !move_uploaded_file($f['tmp_name'], "$dir/$name")) {
                return basename($f['name']) . ' could not be read as a photo.';
            }
        } else {
            $mime = 'image/jpeg';
        }
    }
    db()->prepare('INSERT INTO job_files (work_order_id, uploaded_by, kind, request_id, file_name, original_name, mime, bytes, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$woId, $userId, $kind, $requestId, $name, mb_substr(basename($f['name']), 0, 190), $mime, (int)filesize("$dir/$name"), now()]);
    log_activity($userId, $kind === 'signoff' ? 'signoff_uploaded' : 'photo_uploaded', "#$woId " . mb_substr(basename($f['name']), 0, 80));
    return null;
}

// Normalizes PHP's multi-file upload array into a list of single-file arrays.
function uploaded_list(string $field): array {
    $f = $_FILES[$field] ?? null;
    if (!$f) return [];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) {
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

function job_file_delete(int $fileId, int $woId): void {
    $st = db()->prepare('SELECT * FROM job_files WHERE id = ? AND work_order_id = ?');
    $st->execute([$fileId, $woId]);
    if ($f = $st->fetch()) {
        @unlink(job_files_dir() . '/' . basename($f['file_name']));
        db()->prepare('DELETE FROM job_files WHERE id = ?')->execute([$fileId]);
    }
}

// ---------------------------------------------------------------- photos the provider asks for
function photo_request_lines(string $text): array {
    $out = [];
    foreach (preg_split('/\R/', $text) as $l) {
        $l = trim(preg_replace('/^[\s\-*\x{2022}\d.)]+/u', '', $l));
        if ($l !== '') $out[] = mb_substr($l, 0, 200);
    }
    return array_values(array_unique($out));
}

function add_photo_request(int $woId, string $label): void {
    db()->prepare('INSERT INTO job_photo_requests (work_order_id, label, created_at) VALUES (?,?,?)')->execute([$woId, $label, now()]);
}

function photo_requests(int $woId): array {
    $st = db()->prepare('SELECT * FROM job_photo_requests WHERE work_order_id = ? ORDER BY id');
    $st->execute([$woId]);
    return $st->fetchAll();
}

// Requested photos that have no upload yet.
function missing_photo_requests(int $woId): array {
    $have = array_flip(array_filter(array_map(fn($f) => (int)$f['request_id'], job_files($woId, 'photo'))));
    return array_values(array_filter(photo_requests($woId), fn($r) => !isset($have[(int)$r['id']])));
}

// ---------------------------------------------------------------- tech photos
function tech_photo(int $userId): ?array {
    $st = db()->prepare('SELECT * FROM tech_photos WHERE user_id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

function tech_photo_url(int $userId): ?string {
    $p = tech_photo($userId);
    return $p ? '/portal/techphoto.php?id=' . $userId . '&v=' . strtotime($p['updated_at']) : null;
}

function tech_photo_path(int $userId): ?string {
    $p = tech_photo($userId);
    $path = $p ? upload_dir() . '/techs/' . basename($p['file_name']) : null;
    return $path && is_file($path) ? $path : null;
}

function avatar_html(int $userId, string $name, string $class = 'avatar'): string {
    $url = tech_photo_url($userId);
    return $url ? '<img class="' . $class . '" src="' . e($url) . '" alt="Photo of ' . e($name) . '">' : '';
}

function tech_photo_save(array $f, int $userId): ?string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Choose a photo.';
    if ($f['error'] !== UPLOAD_ERR_OK) return 'The upload failed. Try again.';
    if ($f['size'] > JOB_FILE_MAX_BYTES) return 'The photo is larger than 20 MB.';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (in_array($mime, ['image/heic', 'image/heif'], true)) return 'That is an iPhone HEIC photo. In iPhone Settings, Camera, Formats, choose "Most Compatible", then take the photo again.';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return 'Upload a photo (JPG or PNG).';
    $dir = upload_dir() . '/techs';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $name = bin2hex(random_bytes(16)) . '.jpg';
    if (!job_photo_normalize($f['tmp_name'], $mime, "$dir/$name")) return 'That file could not be read as a photo.';
    [$w, $h] = getimagesize("$dir/$name") ?: [0, 0];
    if (min($w, $h) < 200) { @unlink("$dir/$name"); return 'The photo is too small. Use a photo at least 200 pixels wide.'; }
    // Square crop from the center (a little above it, where faces usually are) so it fits round thumbnails and the work order.
    $src = imagecreatefromjpeg("$dir/$name");
    $side = min($w, $h); $sx = (int)(($w - $side) / 2); $sy = (int)max(0, ($h - $side) * 0.35);
    $sq = imagecreatetruecolor(600, 600);
    imagecopyresampled($sq, $src, 0, 0, $sx, $sy, 600, 600, $side, $side);
    imagejpeg($sq, "$dir/$name", 88);
    imagedestroy($src); imagedestroy($sq);
    if ($old = tech_photo($userId)) {
        @unlink("$dir/" . basename($old['file_name']));
        db()->prepare('UPDATE tech_photos SET file_name = ?, updated_at = ? WHERE user_id = ?')->execute([$name, now(), $userId]);
    } else {
        db()->prepare('INSERT INTO tech_photos (user_id, file_name, updated_at) VALUES (?,?,?)')->execute([$userId, $name, now()]);
    }
    log_activity($userId, 'tech_photo_uploaded');
    return null;
}

// ---------------------------------------------------------------- identity check (driver's license)
function id_dir(): string {
    $d = upload_dir() . '/ids';
    if (!is_dir($d)) { mkdir($d, 0700, true); @file_put_contents($d . '/.htaccess', "Require all denied\n"); }
    return $d;
}

function latest_id_check(int $userId): ?array {
    $st = db()->prepare('SELECT * FROM id_checks WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

function identity_verified(int $userId): bool {
    $st = db()->prepare("SELECT 1 FROM id_checks WHERE user_id = ? AND status = 'approved' LIMIT 1");
    $st->execute([$userId]);
    return (bool)$st->fetchColumn();
}

function id_check_save(array $f, int $userId): ?string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Choose a photo of your license.';
    if ($f['error'] !== UPLOAD_ERR_OK) return 'The upload failed. Try again.';
    if ($f['size'] > JOB_FILE_MAX_BYTES) return 'The file is larger than 20 MB.';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (in_array($mime, ['image/heic', 'image/heif'], true)) return 'That is an iPhone HEIC photo. In iPhone Settings, Camera, Formats, choose "Most Compatible", then take the photo again.';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) return 'Upload a photo (JPG or PNG) or a PDF of your license.';
    $dir = id_dir();
    $name = bin2hex(random_bytes(16));
    if ($mime === 'application/pdf') {
        $name .= '.pdf';
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) return 'The upload failed. Try again.';
    } else {
        $name .= '.jpg'; $mime = 'image/jpeg';
        if (!job_photo_normalize($f['tmp_name'], (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']), "$dir/$name")) return 'That file could not be read as a photo.';
    }
    // A new upload replaces any earlier one still waiting for review.
    $st = db()->prepare("SELECT * FROM id_checks WHERE user_id = ? AND status = 'pending'");
    $st->execute([$userId]);
    foreach ($st->fetchAll() as $old) id_check_purge((int)$old['id'], 'replaced');
    db()->prepare("INSERT INTO id_checks (user_id, file_name, mime, status, created_at) VALUES (?,?,?,'pending',?)")->execute([$userId, $name, $mime, now()]);
    log_activity($userId, 'id_uploaded');
    return null;
}

// Deletes the license image and keeps the record. Used on approval, replacement and rejection.
function id_check_purge(int $checkId, ?string $statusIfPending = null): void {
    $st = db()->prepare('SELECT * FROM id_checks WHERE id = ?'); $st->execute([$checkId]);
    if (!$c = $st->fetch()) return;
    if ($c['file_name']) @unlink(id_dir() . '/' . basename($c['file_name']));
    db()->prepare('UPDATE id_checks SET file_name = NULL, mime = NULL' . ($statusIfPending && $c['status'] === 'pending' ? ', status = ?' : '') . ' WHERE id = ?')
        ->execute($statusIfPending && $c['status'] === 'pending' ? [$statusIfPending, $checkId] : [$checkId]);
}

// ---------------------------------------------------------------- optional GPS at check-in and submit
// Saves the location only if the tech ticked "share my location" and the browser supplied one.
function record_job_location(array $wo, int $userId, string $event): ?array {
    if (empty($_POST['share_gps'])) return null;
    $lat = filter_var($_POST['gps_lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_POST['gps_lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $acc = filter_var($_POST['gps_acc'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
    $miles = miles_between($lat, $lng, (float)$wo['lat'], (float)$wo['lng']);
    db()->prepare('INSERT INTO job_locations (work_order_id, user_id, event, lat, lng, accuracy_m, miles_from_site, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([(int)$wo['id'], $userId, $event, round($lat, 6), round($lng, 6), $acc === false ? null : (int)round($acc), round($miles, 3), now()]);
    log_activity($userId, 'gps_' . $event, '#' . $wo['id'] . ' ' . round($miles, 2) . ' mi from site');
    return ['miles' => $miles];
}

function job_location(int $woId, string $event): ?array {
    $st = db()->prepare('SELECT * FROM job_locations WHERE work_order_id = ? AND event = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$woId, $event]);
    return $st->fetch() ?: null;
}

// "Verified by GPS: 0.1 miles from the job site (accurate to about 20 m)".
function job_location_text(?array $loc): string {
    if (!$loc) return 'Location not shared';
    $m = (float)$loc['miles_from_site'];
    $dist = $m < 0.1 ? 'at the job site' : ($m < 10 ? number_format($m, 1) : number_format($m, 0)) . ' miles from the job site';
    $acc = $loc['accuracy_m'] !== null ? ' (accurate to about ' . ($loc['accuracy_m'] >= 1000 ? round($loc['accuracy_m'] / 1609, 1) . ' miles' : (int)$loc['accuracy_m'] . ' m') . ')' : '';
    return 'GPS: ' . $dist . $acc;
}

// Google Maps directions to the job site. Opens the Maps app on phones, starting from the device's GPS location.
function maps_directions_url(array $wo): string {
    $dest = $wo['street'] . ', ' . $wo['city'] . ', ' . $wo['state'] . ' ' . $wo['zip'];
    return 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' . rawurlencode($dest);
}

// Shown to techs when they take a job, and printed on the work order above the tech's signature.
const NO_SHOW_POLICY = 'If the technician does not show up for this booked job without the buyer\'s approval, a cancellation fee may be charged to the technician.';

// ---------------------------------------------------------------- calendar
function job_end_ts(array $wo): int {
    $hours = $wo['est_hours'] ? (float)$wo['est_hours'] : 2.0;
    return strtotime($wo['start_at']) + (int)round($hours * 3600);
}

// Google Calendar "add event" link. Times are the job site's local time.
function google_calendar_url(array $wo): string {
    $fmt = fn(int $ts) => date('Ymd\THis', $ts);
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        . '&text=' . rawurlencode('Job #' . $wo['id'] . ': ' . $wo['title'])
        . '&dates=' . $fmt(strtotime($wo['start_at'])) . '/' . $fmt(job_end_ts($wo))
        . '&location=' . rawurlencode($wo['street'] . ', ' . $wo['city'] . ', ' . $wo['state'] . ' ' . $wo['zip'])
        . '&details=' . rawurlencode("Electro Service work order WO-" . str_pad((string)$wo['id'], 6, '0', STR_PAD_LEFT) . "\n" . site_link('/portal/jobs/view.php?id=' . $wo['id']));
}

// ---------------------------------------------------------------- detailed instructions page
const INSTRUCTIONS_MAX = 20000;

function job_instructions(int $woId): ?string {
    $st = db()->prepare('SELECT body FROM job_instructions WHERE work_order_id = ?');
    $st->execute([$woId]);
    $b = $st->fetchColumn();
    return $b === false ? null : $b;
}

function save_job_instructions(int $woId, string $body): void {
    $body = trim(mb_substr($body, 0, INSTRUCTIONS_MAX));
    db()->prepare('DELETE FROM job_instructions WHERE work_order_id = ?')->execute([$woId]);
    if ($body !== '') db()->prepare('INSERT INTO job_instructions (work_order_id, body, updated_at) VALUES (?,?,?)')->execute([$woId, $body, now()]);
}

// ---------------------------------------------------------------- job templates (boilerplate)
// The site address and date are left out: those change from job to job.
const TEMPLATE_FIELDS = ['title', 'description', 'skills', 'est_hours', 'pay', 'min_coverage', 'photos', 'instructions'];

function job_templates(int $providerId): array {
    $st = db()->prepare('SELECT * FROM job_templates WHERE provider_id = ? ORDER BY name');
    $st->execute([$providerId]);
    return $st->fetchAll();
}

function job_template(int $providerId, int $id): ?array {
    $st = db()->prepare('SELECT * FROM job_templates WHERE id = ? AND provider_id = ?');
    $st->execute([$id, $providerId]);
    $t = $st->fetch();
    if (!$t) return null;
    $t['fields'] = json_decode($t['data'], true) ?: [];
    return $t;
}

// Saves (or overwrites, by name) a template from the job form input. Returns its id.
function save_job_template(int $providerId, string $name, array $in): int {
    $name = mb_substr(trim($name), 0, 120);
    $data = json_encode(array_intersect_key($in, array_flip(TEMPLATE_FIELDS)));
    $st = db()->prepare('SELECT id FROM job_templates WHERE provider_id = ? AND name = ?');
    $st->execute([$providerId, $name]);
    if ($id = $st->fetchColumn()) {
        db()->prepare('UPDATE job_templates SET data = ?, updated_at = ? WHERE id = ?')->execute([$data, now(), $id]);
        return (int)$id;
    }
    db()->prepare('INSERT INTO job_templates (provider_id, name, data, created_at, updated_at) VALUES (?,?,?,?,?)')->execute([$providerId, $name, $data, now(), now()]);
    return (int)db()->lastInsertId();
}

// The form fields of one of the provider's past jobs, for "Post a similar job".
function job_as_form(array $wo): array {
    return [
        'title' => $wo['title'], 'description' => $wo['description'], 'skills' => array_filter(explode(',', $wo['skills'])),
        'street' => $wo['street'], 'city' => $wo['city'], 'state' => $wo['state'], 'zip' => $wo['zip'],
        'est_hours' => $wo['est_hours'] ? rtrim(rtrim((string)$wo['est_hours'], '0'), '.') : '',
        'pay' => number_format($wo['pay_cents'] / 100, 2, '.', ''), 'min_coverage' => $wo['min_coverage'] ? number_format((int)$wo['min_coverage']) : '',
        'photos' => implode("\n", array_map(fn($r) => $r['label'], photo_requests((int)$wo['id']))),
        'instructions' => (string)job_instructions((int)$wo['id']),
    ];
}

// Pin on Google Maps for a shared GPS location (shown to the provider and admin only).
function gps_map_url(array $loc): string {
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(round((float)$loc['lat'], 6) . ',' . round((float)$loc['lng'], 6));
}
function gps_detail_html(?array $loc): string {
    if (!$loc) return '<span class="muted">Location not shared</span>';
    return '<span class="muted">' . e(job_location_text($loc)) . '</span><br><span class="muted">' . e(number_format((float)$loc['lat'], 5) . ', ' . number_format((float)$loc['lng'], 5))
        . ' at ' . e(local_time($loc['created_at'])) . ' ET</span> <a href="' . e(gps_map_url($loc)) . '" target="_blank" rel="noopener">View on map</a>';
}

// ---------------------------------------------------------------- tech voice contact
function tech_contact(int $userId): ?array {
    $st = db()->prepare('SELECT * FROM tech_contact WHERE user_id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

// The tech's number for a provider to call, or null if hidden. Only while the job is booked and active.
function tech_phone_for_job(array $wo, array $viewer): ?string {
    if (!$wo['tech_id'] || !in_array($wo['status'], ['assigned', 'in_progress', 'submitted'], true)) return null;
    if (!($viewer['role'] === 'admin' || (int)$wo['provider_id'] === (int)$viewer['id'])) return null;
    $c = tech_contact((int)$wo['tech_id']);
    return $c && (int)$c['show_to_providers'] === 1 ? $c['phone'] : null;
}

function phone_display(string $e164): string {
    $d = preg_replace('/\D/', '', $e164);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return strlen($d) === 10 ? sprintf('(%s) %s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6)) : $e164;
}

function phone_call_html(string $e164): string {
    return '<a class="btn plain" href="tel:' . e($e164) . '">Call ' . e(phone_display($e164)) . '</a>';
}

const CONFIDENTIAL_NOTE = 'Confidential. Use this contact information only for this job. Hiring this tech outside Electro Service is against our Terms.';
