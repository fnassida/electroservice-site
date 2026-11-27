<?php
// Printable work order (PDF) with the statement of work and an on-site sign-off section.
// Pricing is left off on purpose: the tech hands this to the site manager.
require __DIR__ . '/../../private/bootstrap.php';
define('FPDF_FONTPATH', __DIR__ . '/../../private/lib/fpdf/font/');
require __DIR__ . '/../../private/lib/fpdf/fpdf.php';

$u = require_login();
$w = load_wo((int)($_GET['id'] ?? 0));
if (!$w || !can_see_job_files($u, $w) || $w['status'] === 'cancelled') { http_response_code(404); exit('Not found'); }

$tech = null;
if ($w['tech_id']) {
    $st = db()->prepare('SELECT full_name FROM users WHERE id = ?'); $st->execute([$w['tech_id']]);
    $tech = $st->fetchColumn() ?: null;
}
log_activity((int)$u['id'], 'work_order_printed', '#' . $w['id']);

// FPDF's built-in fonts use Windows-1252.
function t(?string $s): string {
    $s = str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"], ["'", "'", '"', '"', '-', '-'], (string)$s);
    $r = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    return $r === false ? preg_replace('/[^\x20-\x7E\n]/', '?', $s) : $r;
}

class WorkOrderPdf extends FPDF {
    public string $num = '';
    function Footer(): void {
        $this->SetY(-14);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(110);
        $this->Cell(0, 5, t('Work order ' . $this->num . '   |   Confidential: for this job only   |   electroservice.net   |   Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'C');
    }
}

$num = 'WO-' . str_pad((string)$w['id'], 6, '0', STR_PAD_LEFT);
$pdf = new WorkOrderPdf('P', 'mm', 'Letter');
$pdf->num = $num;
$pdf->AliasNbPages();
$pdf->SetMargins(16, 14, 16);
$pdf->SetAutoPageBreak(true, 20);
$pdf->SetTitle(t("Work order $num"));
$pdf->SetAuthor('Electro Service, Inc.');
$pdf->AddPage();
$W = $pdf->GetPageWidth() - 32;
$navy = [19, 5, 59]; $red = [128, 0, 25]; $rule = [190, 196, 205];

// Header: the buyer's brand large, Electro Service small as the platform providing the work order.
$blogo = provider_logo((int)$w['provider_id']);
$boxW = 100; $boxH = 20;
if ($blogo) {
    $ratio = $blogo['width'] / max(1, $blogo['height']);
    $lw = min($boxW, $boxH * $ratio); $lh = $lw / $ratio;
    $pdf->Image($blogo['path'], 16, 12 + ($boxH - $lh) / 2, $lw, $lh, 'JPG');
} else {
    $pdf->SetXY(16, 12);
    $pdf->SetFont('Helvetica', 'B', 22); $pdf->SetTextColor(20);
    $size = 22; while ($size > 12 && $pdf->GetStringWidth(t($w['company'])) > $boxW) { $size--; $pdf->SetFontSize($size); }
    $pdf->Cell($boxW, $boxH, t($w['company']), 0, 0, 'L');
}
$pdf->SetXY(16, 33.5);
$pdf->SetFont('Helvetica', '', 7.5); $pdf->SetTextColor(110);
$pdf->Cell(36, 4.4, 'Work order provided through', 0, 0, 'L');
$pdf->Image(__DIR__ . '/../../private/lib/logo-print.png', 16 + 36.5, 33.1, 19);
$pdf->SetXY(16, 13);
$pdf->SetFont('Helvetica', 'B', 18); $pdf->SetTextColor(...$navy);
$pdf->Cell($W, 8, 'WORK ORDER', 0, 2, 'R');
$pdf->SetFont('Helvetica', '', 11); $pdf->SetTextColor(40);
$pdf->Cell($W, 6, $num, 0, 2, 'R');
$pdf->SetFont('Helvetica', '', 9); $pdf->SetTextColor(110);
$pdf->Cell($W, 5, t('Printed ' . date('M j, Y')), 0, 2, 'R');
$pdf->SetY(40);
$pdf->SetDrawColor(...$red); $pdf->SetLineWidth(0.8);
$pdf->Line(16, 40, 16 + $W, 40);
$pdf->SetLineWidth(0.2); $pdf->SetDrawColor(...$rule);
$pdf->Ln(5);

// Facts in two columns
function fact(FPDF $pdf, float $x, float $w, string $label, string $value): float {
    $pdf->SetX($x);
    $pdf->SetFont('Helvetica', 'B', 7); $pdf->SetTextColor(110);
    $pdf->Cell($w, 4, t(strtoupper($label)), 0, 2);
    $pdf->SetX($x);
    $pdf->SetFont('Helvetica', '', 9.5); $pdf->SetTextColor(20);
    $pdf->MultiCell($w, 4.4, t($value));
    return $pdf->GetY() + 2.5;
}
$colW = ($W - 8) / 2; $x1 = 16; $x2 = 16 + $colW + 8;
$top = $pdf->GetY();
$start = strtotime($w['start_at']);
$y1 = fact($pdf, $x1, $colW, 'Provider contact', $w['provider_name'] . ', ' . $w['company']);
$pdf->SetY($y1); $y1 = fact($pdf, $x1, $colW, 'Job site', $w['street'] . "\n" . $w['city'] . ', ' . $w['state'] . ' ' . $w['zip']);
$pdf->SetXY($x1, $y1 - 2); $pdf->SetFont('Helvetica', 'U', 8.5); $pdf->SetTextColor(...$red);
$pdf->Cell($colW, 5, 'Directions in Google Maps', 0, 2, 'L', false, maps_directions_url($w));
$y1 = $pdf->GetY() + 2;
$pdf->SetY($top);
$techPhoto = $w['tech_id'] ? tech_photo_path((int)$w['tech_id']) : null;
if ($techPhoto) {
    $pdf->Image($techPhoto, $x2 + $colW - 22, $top, 22, 22, 'JPG');
    $y2 = fact($pdf, $x2, $colW - 26, 'Technician', ($tech ?: '') . "\nPhoto at right. Check ID on arrival.");
    $y2 = max($y2, $top + 24);
} else {
    $y2 = fact($pdf, $x2, $colW, 'Technician', $tech ?: 'Not assigned yet');
}
$pdf->SetY($y2);
$y2 = fact($pdf, $x2, $colW, 'Scheduled', date('l, M j, Y \a\t g:i a', $start) . ($w['est_hours'] ? "\nEstimated " . rtrim(rtrim((string)$w['est_hours'], '0'), '.') . ' hours' : ''));
$pdf->SetY($y1);
$y1 = fact($pdf, $x1, $colW, 'Skills', skill_labels($w['skills']));
$pdf->SetY(max($y1, $y2) + 1);

// Statement of work
$pdf->Line(16, $pdf->GetY(), 16 + $W, $pdf->GetY()); $pdf->Ln(4);
$pdf->SetFont('Helvetica', 'B', 12); $pdf->SetTextColor(...$navy);
$pdf->MultiCell($W, 6, t($w['title']));
$pdf->Ln(1.5);
$pdf->SetFont('Helvetica', 'B', 8.5); $pdf->SetTextColor(...$red);
$pdf->Cell($W, 5, 'STATEMENT OF WORK', 0, 1);
$pdf->SetFont('Helvetica', '', 9.5); $pdf->SetTextColor(20);
$pdf->MultiCell($W, 4.6, t(trim($w['description'])));
$pdf->Ln(3);

$reqs = photo_requests((int)$w['id']);
$pdf->SetFont('Helvetica', 'B', 8.5); $pdf->SetTextColor(...$red);
$pdf->Cell($W, 5, 'PHOTOS TO UPLOAD WHEN DONE', 0, 1);
$pdf->SetFont('Helvetica', '', 9.5); $pdf->SetTextColor(20);
foreach ($reqs as $r) { $pdf->SetX(18); $pdf->MultiCell($W - 2, 4.5, t('[  ]  ' . $r['label'])); }
$pdf->SetX(18); $pdf->MultiCell($W - 2, 4.5, t('[  ]  ' . ($reqs ? 'Any other photos of the finished work' : 'At least one photo of the finished work')));
$pdf->SetX(18); $pdf->MultiCell($W - 2, 4.5, t('[  ]  This work order, signed by the site manager'));
$pdf->Ln(3);

if ($w['completion_notes']) {
    $pdf->SetFont('Helvetica', 'B', 8.5); $pdf->SetTextColor(...$red);
    $pdf->Cell($W, 5, "TECHNICIAN'S COMPLETION NOTES", 0, 1);
    $pdf->SetFont('Helvetica', '', 9.5); $pdf->SetTextColor(20);
    $pdf->MultiCell($W, 4.6, t(trim($w['completion_notes'])));
    $pdf->Ln(3);
}

// ---------------------------------------------------------------- sign-off
function blank(FPDF $pdf, float $x, float $w, string $label, string $prefill = ''): void {
    $y = $pdf->GetY();
    $pdf->SetXY($x, $y);
    $pdf->SetFont('Helvetica', '', 10); $pdf->SetTextColor(20);
    if ($prefill !== '') $pdf->Cell($w, 6, t($prefill), 0, 0);
    $pdf->Line($x, $y + 7, $x + $w, $y + 7);
    $pdf->SetXY($x, $y + 7.5);
    $pdf->SetFont('Helvetica', '', 7); $pdf->SetTextColor(110);
    $pdf->MultiCell($w, 3.3, t($label));
    $pdf->SetY($y);   // fields on the same row share one baseline
}
function heading(FPDF $pdf, float $W, string $text, array $red): void {
    $pdf->SetFont('Helvetica', 'B', 8.5); $pdf->SetTextColor(...$red);
    $pdf->Cell($W, 5, $text, 0, 1);
}

// Draws the whole sign-off section from the current position.
function signoff(FPDF $pdf, float $W, ?string $tech, array $red): void {
    $pdf->Line(16, $pdf->GetY(), 16 + $W, $pdf->GetY()); $pdf->Ln(3);
    heading($pdf, $W, 'ON-SITE TIMES', $red);
    $third = ($W - 12) / 3;
    $pdf->Ln(1);
    blank($pdf, 16, $third, 'Date');
    blank($pdf, 16 + $third + 6, $third, 'Time arrived');
    blank($pdf, 16 + 2 * ($third + 6), $third, 'Time departed');
    $pdf->Ln(12);
    heading($pdf, $W, 'NOTES OR EXCEPTIONS', $red);
    for ($i = 0; $i < 2; $i++) { $pdf->Ln(6.5); $pdf->Line(16, $pdf->GetY(), 16 + $W, $pdf->GetY()); }
    $pdf->Ln(3);

    heading($pdf, $W, 'ON-SITE MANAGER SIGN-OFF', $red);
    $pdf->Ln(1);
    $big = ($W - 8) * 0.62; $small = ($W - 8) - $big;
    blank($pdf, 16, $big, 'Manager printed name');
    blank($pdf, 16 + $big + 8, $small, 'Title');
    $pdf->Ln(12);
    blank($pdf, 16, $big, "Manager signature. By signing, the manager attests that the technician's work was completed satisfactorily, except as noted above.");
    blank($pdf, 16 + $big + 8, $small, 'Date');
    $pdf->Ln(15);

    heading($pdf, $W, 'TECHNICIAN ACKNOWLEDGMENT', $red);
    $pdf->SetFont('Helvetica', '', 8.5); $pdf->SetTextColor(20);
    $pdf->MultiCell($W, 4, t(NO_SHOW_POLICY . ' By signing, I confirm I performed the work described above.'));
    $pdf->Ln(1);
    $c1 = ($W - 12) * 0.34; $c2 = ($W - 12) * 0.44; $c3 = ($W - 12) - $c1 - $c2;
    blank($pdf, 16, $c1, 'Technician printed name', $tech ?: '');
    blank($pdf, 16 + $c1 + 6, $c2, 'Technician signature');
    blank($pdf, 16 + $c1 + $c2 + 12, $c3, 'Date');
    $pdf->Ln(12);
    $pdf->SetFont('Helvetica', 'I', 7.5); $pdf->SetTextColor(90);
    $pdf->MultiCell($W, 3.6, t('Technician: after the manager signs, take a phone photo of this page and upload it with your completion photos on the job page. The provider reviews both before approving payment.'));
}

// Measure the section on a scratch page, then place it so it never splits.
$probe = new WorkOrderPdf('P', 'mm', 'Letter');
$probe->SetMargins(16, 14, 16); $probe->SetAutoPageBreak(false); $probe->AddPage(); $probe->SetY(14);
signoff($probe, $W, $tech, $red);
$need = $probe->GetY() - 14;
$bottom = $pdf->GetPageHeight() - 17;   // just above the footer
$pdf->SetAutoPageBreak(true, 16);
if ($need > $bottom - $pdf->GetY()) $pdf->AddPage();
else $pdf->SetY($bottom - $need);   // sit the sign-off at the bottom of the page   // sit the sign-off at the bottom of the page
signoff($pdf, $W, $tech, $red);

// Optional separate page of detailed instructions from the provider.
if (($instr = job_instructions((int)$w['id'])) !== null) {
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 16); $pdf->SetTextColor(...$navy);
    $pdf->Cell($W, 8, 'DETAILED INSTRUCTIONS', 0, 1);
    $pdf->SetFont('Helvetica', '', 10); $pdf->SetTextColor(90);
    $pdf->Cell($W, 5, t($num . '   |   ' . $w['title'] . '   |   from ' . $w['company']), 0, 1);
    $pdf->SetDrawColor(...$red); $pdf->SetLineWidth(0.6); $pdf->Line(16, $pdf->GetY() + 2, 16 + $W, $pdf->GetY() + 2);
    $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(...$rule); $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 9.5); $pdf->SetTextColor(20);
    $pdf->MultiCell($W, 4.6, t($instr));
}

$file = $num . '.pdf';
header('Cache-Control: private, no-store');
$pdf->Output(isset($_GET['download']) ? 'D' : 'I', $file);
