<?php
// Calendar file (.ics) for a booked job, with reminders the day before and an hour before.
// Opens in Outlook, Apple Calendar and most phone calendars.
require __DIR__ . '/../../private/bootstrap.php';
$u = require_login();
$w = load_wo((int)($_GET['id'] ?? 0));
if (!$w || !can_see_job_files($u, $w) || !$w['tech_id'] || $w['status'] === 'cancelled') { http_response_code(404); exit('Not found'); }

function ics_text(string $s): string {
    return str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\;", "\\,", "\\n", "\\n"], $s);
}
function ics_fold(string $line): string {   // lines over 75 bytes are folded, per the calendar standard
    $out = ''; $max = 75;   // continuation lines start with a space, so they carry 74 bytes
    while (strlen($line) > $max) { $cut = $max; while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--; $out .= substr($line, 0, $cut) . "\r\n "; $line = substr($line, $cut); $max = 74; }
    return $out . $line;
}
$num = 'WO-' . str_pad((string)$w['id'], 6, '0', STR_PAD_LEFT);
$where = $w['street'] . ', ' . $w['city'] . ', ' . $w['state'] . ' ' . $w['zip'];
$link = site_link('/portal/jobs/view.php?id=' . $w['id']);
// Floating local times: the job's start is stored in the job site's local time.
$lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Electro Service//Work board//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:job-' . (int)$w['id'] . '@electroservice.net',
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART:' . date('Ymd\THis', strtotime($w['start_at'])),
    'DTEND:' . date('Ymd\THis', job_end_ts($w)),
    'SUMMARY:' . ics_text("Job #{$w['id']}: {$w['title']}"),
    'LOCATION:' . ics_text($where),
    'DESCRIPTION:' . ics_text("Electro Service work order $num\nProvider: {$w['company']}\n\n" . mb_substr($w['description'], 0, 1500) . "\n\n$link"),
    'URL:' . $link,
    'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics_text("Tomorrow: job #{$w['id']}"), 'TRIGGER:-P1D', 'END:VALARM',
    'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . ics_text("In 1 hour: job #{$w['id']}"), 'TRIGGER:-PT1H', 'END:VALARM',
    'END:VEVENT', 'END:VCALENDAR',
];
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $num . '.ics"');
header('Cache-Control: private, no-store');
echo implode("\r\n", array_map('ics_fold', $lines)) . "\r\n";
