<?php
require __DIR__ . '/../../private/bootstrap.php';
$u = require_login('provider');
$minCents = (int)round((float)cfg('min_job_amount', 50) * 100);
$rate = (float)cfg('platform_fee_rate', 0.10);
$errors = [];
$in = ['title' => '', 'description' => '', 'skills' => [], 'street' => '', 'city' => '', 'state' => '', 'zip' => '',
       'start_date' => '', 'start_time' => '09:00', 'est_hours' => '', 'pay' => '', 'min_coverage' => '', 'photos' => '', 'instructions' => ''];

$templates = job_templates((int)$u['id']);
$tplName = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Start from a saved template, or copy one of your past jobs.
    if (($tid = (int)($_GET['template'] ?? 0)) && ($t = job_template((int)$u['id'], $tid))) {
        foreach (TEMPLATE_FIELDS as $k) if (isset($t['fields'][$k])) $in[$k] = $t['fields'][$k];
        $tplName = $t['name'];
    } elseif (($cid = (int)($_GET['copy'] ?? 0)) && ($old = load_wo($cid)) && (int)$old['provider_id'] === (int)$u['id']) {
        $in = array_merge($in, job_as_form($old));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!require_verified_action($u)) redirect('/portal/jobs/new.php');
    foreach ($in as $k => $_) $in[$k] = is_array($_POST[$k] ?? null) ? $_POST[$k] : trim((string)($_POST[$k] ?? ''));
    $tplName = trim((string)($_POST['template_name'] ?? ''));
    $saveTpl = !empty($_POST['save_template']) || isset($_POST['template_only']);
    if ($saveTpl && $tplName === '') $errors[] = 'Give the template a name, e.g. "Access point swap".';
    if (isset($_POST['template_only'])) {
        // Save the boilerplate without posting a job: only the title and scope are required.
        $in['skills'] = array_values(array_intersect(array_keys(SKILLS), (array)$in['skills']));
        if ($in['title'] === '' || mb_strlen($in['description']) < 20) $errors[] = 'A template needs at least a title and a scope of work.';
        if (!$errors) {
            save_job_template((int)$u['id'], $tplName, $in);
            log_activity((int)$u['id'], 'template_saved', $tplName);
            flash('Template "' . $tplName . '" saved.');
            redirect('/portal/jobs/templates.php');
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['template_only'])) {
    $in['skills'] = array_values(array_intersect(array_keys(SKILLS), (array)$in['skills']));
    $in['state'] = strtoupper($in['state']);
    $payCents = (int)round((float)str_replace([',', '$'], '', $in['pay']) * 100);
    $coverage = (int)preg_replace('/[^\d]/', '', $in['min_coverage']);
    $start = $in['start_date'] . ' ' . $in['start_time'] . ':00';

    $photoList = photo_request_lines($in['photos']);
    if (count($photoList) > 10) $errors[] = 'Ask for at most 10 photos.';
    if ($in['title'] === '' || mb_strlen($in['title']) > 160) $errors[] = 'Give the job a short title.';
    if (mb_strlen($in['description']) < 20) $errors[] = 'Describe the work in a few sentences: what needs doing, equipment, site access.';
    if (!$in['skills']) $errors[] = 'Choose at least one skill the job needs.';
    if ($in['street'] === '' || $in['city'] === '' || !in_array($in['state'], US_STATES, true) || !preg_match('/^\d{5}(-\d{4})?$/', $in['zip'])) {
        $errors[] = 'Enter the job site\'s street address, city, state and ZIP code.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $start) || strtotime($start) < time()) $errors[] = 'Choose a start date and time in the future.';
    if ($in['est_hours'] !== '' && (!is_numeric($in['est_hours']) || $in['est_hours'] <= 0 || $in['est_hours'] > 999)) $errors[] = 'Estimated hours should be a number.';
    if ($payCents < $minCents) $errors[] = 'The tech\'s pay must be at least ' . money($minCents) . '.';

    $geo = null;
    if (!$errors) {
        $geo = geocode_us($in['street'], $in['city'], $in['state'], $in['zip']);
        if (!$geo) $errors[] = 'We couldn\'t find the job site address on the map. Check the street and ZIP code.';
    }

    if (!$errors) {
        $fee = (int)round($payCents * $rate);
        db()->prepare('INSERT INTO work_orders (provider_id, title, description, skills, street, city, state, zip, lat, lng, start_at,
                       est_hours, pay_cents, fee_cents, min_coverage, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$u['id'], $in['title'], $in['description'], implode(',', $in['skills']), $in['street'], $in['city'],
                       $in['state'], $in['zip'], $geo[0], $geo[1], $start, $in['est_hours'] === '' ? null : $in['est_hours'],
                       $payCents, $fee, $coverage, now(), now()]);
        $id = (int)db()->lastInsertId();
        foreach ($photoList as $label) add_photo_request($id, $label);
        save_job_instructions($id, $in['instructions']);
        if ($saveTpl) save_job_template((int)$u['id'], $tplName, $in);
        log_activity((int)$u['id'], 'job_posted', "#$id " . $in['title'] . ' ' . money($payCents));
        flash('Job posted. Insured techs within range can now request it, or you can offer it to a tech.');
        redirect('/portal/jobs/view.php?id=' . $id);
    }
}

page_start('Post a job', $u);
?>
<div style="max-width:44rem">
  <h1>Post a job</h1>
  <?php if ($templates): ?>
    <form method="get" class="inline-add" style="margin-bottom:1.2rem">
      <label>Start from a template <select name="template">
        <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $t['name'] === $tplName ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
      </select></label>
      <button class="btn plain">Use template</button> <a href="/portal/jobs/templates.php">Manage templates</a>
    </form>
  <?php endif; ?>
  <?php if ($tplName && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?><p class="notice">Filled in from your "<?= e($tplName) ?>" template. Add the site address and date, then check the rest.</p><?php endif; ?>
  <?php if (isset($_GET['copy']) && !$tplName && $in['title'] !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?><p class="notice">Copied from your earlier job. Change the date, and the address if it's a different site.</p><?php endif; ?>
  <?php if ($errors): ?><div class="error" role="alert"><?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?></div><?php endif; ?>
  <form method="post" class="stack" id="jobform">
    <?= csrf_field() ?>
    <label>Title <span class="hint">e.g. "Replace 24-port switch at retail store".</span>
      <input type="text" name="title" maxlength="160" value="<?= e($in['title']) ?>"></label>
    <label>Scope of work <span class="hint">What needs doing, equipment provided, site contact and access instructions.</span>
      <textarea name="description" rows="6"><?= e($in['description']) ?></textarea></label>
    <details <?= $in['instructions'] !== '' ? 'open' : '' ?>>
      <summary>Add a detailed instructions page (optional)</summary>
      <label>Detailed instructions <span class="hint">Step-by-step procedure, site access, who to call before starting, equipment locations. Only the booked tech sees this, and it prints as a separate page after the work order.</span>
        <textarea name="instructions" rows="10" maxlength="20000"><?= e($in['instructions']) ?></textarea></label>
    </details>
    <label>Photos you need from the tech <span class="hint">One per line, e.g. "New access point mounted" or "Shipping label on the return box". The tech must upload each one before submitting the job. Leave blank and they'll still need at least one photo of the finished work.</span>
      <textarea name="photos" rows="3" maxlength="2000"><?= e($in['photos']) ?></textarea></label>

    <fieldset>
      <legend>Skills needed</legend>
      <div class="skills">
        <?php foreach (SKILLS as $k => $label): ?>
          <label class="check"><input type="checkbox" name="skills[]" value="<?= e($k) ?>" <?= in_array($k, (array)$in['skills'], true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <fieldset>
      <legend>Job site</legend>
      <p class="muted" style="margin:0 0 .6rem">Techs see the city and their distance. The street address is shown only to the tech you assign.</p>
      <div class="addr">
        <label class="wide">Street address <input type="text" name="street" value="<?= e($in['street']) ?>"></label>
        <label>City <input type="text" name="city" value="<?= e($in['city']) ?>"></label>
        <label>State
          <select name="state"><option value="">Choose</option>
            <?php foreach (US_STATES as $s): ?><option <?= $in['state'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
          </select></label>
        <label>ZIP <input type="text" name="zip" inputmode="numeric" value="<?= e($in['zip']) ?>"></label>
      </div>
    </fieldset>

    <div class="addr">
      <label>Start date <input type="date" name="start_date" value="<?= e($in['start_date']) ?>"></label>
      <label>Start time <span class="hint">Local time at the site.</span><input type="time" name="start_time" value="<?= e($in['start_time']) ?>"></label>
      <label>Estimated hours <input type="number" name="est_hours" min="0.5" step="0.5" value="<?= e($in['est_hours']) ?>"></label>
    </div>

    <div class="addr">
      <label>Tech's pay <span class="hint">Minimum <?= money($minCents) ?>.</span>
        <input type="text" name="pay" id="pay" inputmode="decimal" value="<?= e($in['pay']) ?>"></label>
      <label class="wide" style="grid-column:auto / span 2">Minimum insurance <span class="hint">Optional. General liability per occurrence, e.g. 1,000,000.</span>
        <input type="text" name="min_coverage" inputmode="numeric" value="<?= e($in['min_coverage']) ?>"></label>
    </div>
    <p class="total" id="total" aria-live="polite"></p>

    <fieldset>
      <legend>Reuse these details</legend>
      <label class="check"><input type="checkbox" name="save_template" value="1" <?= !empty($_POST['save_template']) ? 'checked' : '' ?>> <span>Save the scope, skills, pay, photos and instructions as a template</span></label>
      <label>Template name <span class="hint">Saving with an existing name updates that template. The address and date aren't saved.</span>
        <input type="text" name="template_name" maxlength="120" value="<?= e($tplName) ?>" placeholder="e.g. Access point swap"></label>
    </fieldset>

    <div style="display:flex;gap:.6rem;flex-wrap:wrap"><button class="btn primary">Post job</button>
      <button class="btn plain" name="template_only" value="1" formnovalidate>Save as template only</button>
      <a class="btn plain" href="/portal/jobs/">Cancel</a></div>
  </form>
</div>
<script>
(function () {
  var pay = document.getElementById('pay'), out = document.getElementById('total'), rate = <?= json_encode($rate) ?>, min = <?= $minCents / 100 ?>;
  function show() {
    var p = parseFloat((pay.value || '').replace(/[$,]/g, ''));
    if (!p || p < min) { out.textContent = 'You pay the tech\'s pay plus a ' + Math.round(rate * 100) + '% platform fee.'; return; }
    var fee = Math.round(p * rate * 100) / 100;
    out.textContent = 'Tech receives $' + p.toFixed(2) + '. Platform fee $' + fee.toFixed(2) + '. You pay $' + (p + fee).toFixed(2) + ' when you book a tech.';
  }
  pay.addEventListener('input', show); show();
})();
</script>
<?php page_end();
