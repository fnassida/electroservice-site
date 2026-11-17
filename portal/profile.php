<?php
require __DIR__ . '/../private/bootstrap.php';
$u = require_login('tech');
$p = tech_profile((int)$u['id']) ?? [
    'headline' => '', 'bio' => '', 'skills' => '', 'certifications' => '', 'street' => '', 'city' => '',
    'state' => '', 'zip' => '', 'lat' => null, 'lng' => null, 'radius_miles' => 50,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $skills = array_values(array_intersect(array_keys(SKILLS), (array)($_POST['skills'] ?? [])));
    $new = [
        'headline'       => trim($_POST['headline'] ?? ''),
        'bio'            => trim($_POST['bio'] ?? ''),
        'skills'         => implode(',', $skills),
        'certifications' => trim($_POST['certifications'] ?? ''),
        'street'         => trim($_POST['street'] ?? ''),
        'city'           => trim($_POST['city'] ?? ''),
        'state'          => strtoupper(trim($_POST['state'] ?? '')),
        'zip'            => trim($_POST['zip'] ?? ''),
        'radius_miles'   => max(5, min(500, (int)($_POST['radius_miles'] ?? 50))),
    ];
    if (mb_strlen($new['headline']) > 160) $errors[] = 'Keep the headline under 160 characters.';
    if (!$skills) $errors[] = 'Choose at least one skill.';
    if ($new['street'] === '' || $new['city'] === '' || !in_array($new['state'], US_STATES, true) || !preg_match('/^\d{5}(-\d{4})?$/', $new['zip'])) {
        $errors[] = 'Enter your full street address, city, state and 5-digit ZIP code.';
    }

    $lat = $p['lat']; $lng = $p['lng'];
    $addrChanged = !$errors && ($new['street'] !== $p['street'] || $new['city'] !== $p['city']
        || $new['state'] !== $p['state'] || $new['zip'] !== $p['zip'] || $lat === null);
    if ($addrChanged) {
        $geo = geocode_us($new['street'], $new['city'], $new['state'], $new['zip']);
        if ($geo) { [$lat, $lng] = $geo; }
        else { $errors[] = 'We couldn\'t find that address on the map. Check the street and ZIP code and try again.'; }
    }

    if (!$errors) {
        db()->prepare('DELETE FROM tech_profiles WHERE user_id = ?')->execute([$u['id']]);
        db()->prepare('INSERT INTO tech_profiles (user_id, headline, bio, skills, certifications, street, city, state, zip, lat, lng, radius_miles, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$u['id'], $new['headline'], $new['bio'], $new['skills'], $new['certifications'], $new['street'],
                       $new['city'], $new['state'], $new['zip'], $lat, $lng, $new['radius_miles'], now()]);
        log_activity((int)$u['id'], 'profile_saved', $new['city'] . ', ' . $new['state'] . ' / ' . $new['radius_miles'] . ' mi');
        flash('Profile saved.');
        redirect('/portal/');
    }
    $p = array_merge($p, $new);
}
$mine = $p['skills'] ? explode(',', $p['skills']) : [];

page_start('Your profile', $u);
?>
<div style="max-width:44rem">
  <h1>Your tech profile</h1>
  <p class="muted">Providers see your name, headline, skills, certifications, city and how far you are from their job. They never see your street address.</p>
  <?php if ($errors): ?><div class="error" role="alert"><?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?></div><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Headline <span class="hint">One line about what you do, e.g. "Network and cabling tech, 12 years".</span>
      <input type="text" name="headline" maxlength="160" value="<?= e($p['headline']) ?>"></label>

    <fieldset>
      <legend>Skills</legend>
      <div class="skills">
        <?php foreach (SKILLS as $k => $label): ?>
          <label class="check"><input type="checkbox" name="skills[]" value="<?= e($k) ?>" <?= in_array($k, $mine, true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <label>Certifications <span class="hint">One per line, e.g. CompTIA A+, BICSI Installer 2.</span>
      <textarea name="certifications" rows="4"><?= e($p['certifications']) ?></textarea></label>

    <label>About you <span class="hint">Optional. Experience, equipment, anything a provider should know.</span>
      <textarea name="bio" rows="4"><?= e($p['bio']) ?></textarea></label>

    <fieldset>
      <legend>Your base address</legend>
      <p class="muted" style="margin:0 0 .6rem">Used only to work out distances to jobs. Never shown to anyone.</p>
      <div class="addr">
        <label class="wide">Street address <input type="text" name="street" autocomplete="street-address" value="<?= e($p['street']) ?>"></label>
        <label>City <input type="text" name="city" autocomplete="address-level2" value="<?= e($p['city']) ?>"></label>
        <label>State
          <select name="state" autocomplete="address-level1">
            <option value="">Choose</option>
            <?php foreach (US_STATES as $s): ?><option <?= $p['state'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
          </select></label>
        <label>ZIP <input type="text" name="zip" inputmode="numeric" autocomplete="postal-code" value="<?= e($p['zip']) ?>"></label>
      </div>
    </fieldset>

    <label>How far will you travel? <span class="hint">Miles from your base address. You'll only be offered jobs within this distance.</span>
      <input type="number" name="radius_miles" min="5" max="500" step="5" value="<?= (int)$p['radius_miles'] ?>" style="max-width:9rem"></label>

    <div><button class="btn primary">Save profile</button> <a class="btn plain" href="/portal/">Cancel</a></div>
  </form>
</div>
<?php page_end();
