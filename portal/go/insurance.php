<?php
// Counts the click, then sends the tech to the insurance partner.
require __DIR__ . '/../../private/bootstrap.php';
$u = current_user();
$url = setting('ins_aff_url') ?: INSURANCE_DEFAULT_URL;
if (!insurance_offer_on() || !preg_match('#^https://#i', $url)) $url = INSURANCE_DEFAULT_URL;
$from = preg_replace('/[^a-z_]/', '', (string)($_GET['from'] ?? ''));
log_activity($u ? (int)$u['id'] : null, 'affiliate_click', 'insurance' . ($from ? ' from ' . $from : ''));
header('Referrer-Policy: no-referrer');
redirect($url);
