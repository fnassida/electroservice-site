<?php
require __DIR__ . '/../../private/bootstrap.php';
$admin = require_login('admin');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $url = trim((string)($_POST['url'] ?? ''));
    if ($url !== '' && !preg_match('#^https://[^\s<>"]+$#i', $url)) {
        $error = 'Paste the full tracking link, starting with https://';
    } else {
        set_setting('ins_aff_url', $url !== '' ? $url : null);
        set_setting('ins_aff_enabled', !empty($_POST['enabled']) ? '1' : '0');
        log_activity((int)$admin['id'], 'affiliate_updated', ($url ?: 'default link') . (!empty($_POST['enabled']) ? ', on' : ', off'));
        flash('Saved.');
        redirect('/portal/admin/affiliates.php');
    }
}
$url = setting('ins_aff_url', '');
$on = insurance_offer_on();
$clicks = db()->query("SELECT COUNT(*) total, SUM(CASE WHEN created_at > '" . gmdate('Y-m-d H:i:s', time() - 30 * 86400) . "' THEN 1 ELSE 0 END) last30
                       FROM activity_log WHERE action = 'affiliate_click'")->fetch();
$byPlace = db()->query("SELECT detail, COUNT(*) n FROM activity_log WHERE action = 'affiliate_click' GROUP BY detail ORDER BY n DESC")->fetchAll();

page_start('Insurance referral', $admin);
?>
<p><a href="/portal/admin/">Back to admin dashboard</a></p>
<h1>Insurance referral</h1>
<p>Techs without current, approved insurance see a "Get a Hiscox quote" suggestion on their dashboard, their insurance page and the Find work page.</p>
<ul class="stats">
  <li><b><?= $on ? 'On' : 'Off' ?></b> suggestion</li>
  <li><b><?= (int)$clicks['last30'] ?></b> clicks, last 30 days</li>
  <li><b><?= (int)$clicks['total'] ?></b> clicks, all time</li>
</ul>
<?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="stack" style="max-width:40rem">
  <?= csrf_field() ?>
  <label>Hiscox affiliate tracking link
    <span class="hint">From your CJ Affiliate account once Hiscox accepts you. Leave blank to send techs to Hiscox's normal page (no commission).</span>
    <input type="text" name="url" value="<?= e($url) ?>" placeholder="https://www.anrdoezrs.net/click-..."></label>
  <label class="check"><input type="checkbox" name="enabled" value="1" <?= $on ? 'checked' : '' ?>><span>Show the suggestion to uninsured techs</span></label>
  <div><button class="btn primary">Save</button></div>
</form>
<?php if ($byPlace): ?>
  <h2 style="margin-top:2rem">Where techs clicked</h2>
  <table style="max-width:30rem"><thead><tr><th>Place</th><th>Clicks</th></tr></thead><tbody>
  <?php foreach ($byPlace as $r): ?><tr><td><?= e(str_replace(['insurance from ', '_'], ['', ' '], (string)$r['detail'])) ?></td><td><?= (int)$r['n'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
<?php endif; ?>
<p class="muted" style="margin-top:2rem">Clicks are counted on this site. Quotes and commissions are reported in your CJ Affiliate account.</p>
<?php page_end();
