<?php
// Stripe Checkout sends the provider back here after paying.
require __DIR__ . '/../../private/bootstrap.php';
$u = require_login('provider');
$id = (int)($_GET['id'] ?? 0);
$w = load_wo($id);
if (!$w || (int)$w['provider_id'] !== (int)$u['id']) { http_response_code(404); exit('Not found'); }
$sid = (string)($_GET['session'] ?? '');
$pay = preg_match('/^cs_[A-Za-z0-9_]+$/', $sid) ? stripe_record_session($sid) : null;
if ($pay && (int)$pay['work_order_id'] === $id && $pay['status'] === 'paid') {
    flash('Payment received. The tech has been told and can check in on site.');
} else {
    flash('Stripe is still confirming your payment. This page will show it as paid within a few minutes.');
}
redirect('/portal/jobs/view.php?id=' . $id);
