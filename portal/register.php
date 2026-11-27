<?php
require __DIR__ . '/../private/bootstrap.php';
if (current_user()) redirect('/portal/');

$errors = [];
$in = ['role' => $_GET['as'] ?? 'tech', 'full_name' => '', 'email' => '', 'company' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $in = [
        'role'      => $_POST['role'] ?? '',
        'full_name' => trim($_POST['full_name'] ?? ''),
        'email'     => strtolower(trim($_POST['email'] ?? '')),
        'company'   => trim($_POST['company'] ?? ''),
    ];
    $pass = (string)($_POST['password'] ?? '');

    if (!in_array($in['role'], ['tech', 'provider'], true)) $errors[] = 'Choose whether you are a tech or a provider.';
    if ($in['full_name'] === '' || mb_strlen($in['full_name']) > 120) $errors[] = 'Enter your full name.';
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($in['role'] === 'provider' && $in['company'] === '') $errors[] = 'Enter your company name.';
    if (strlen($pass) < 10) $errors[] = 'Use a password of at least 10 characters.';
    if (empty($_POST['terms'])) $errors[] = 'You need to agree to the Terms of Service and Privacy Policy.';

    if (!$errors) {
        $st = db()->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$in['email']]);
        if ($st->fetch()) $errors[] = 'An account with that email already exists. Log in instead.';
    }

    if (!$errors) {
        db()->prepare('INSERT INTO users (role, email, password_hash, full_name, company, terms_accepted_at, created_at)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([$in['role'], $in['email'], password_hash($pass, PASSWORD_DEFAULT), $in['full_name'],
                       $in['role'] === 'provider' ? $in['company'] : null, now(), now()]);
        $id = (int)db()->lastInsertId();
        log_activity($id, 'register', $in['role']);
        login_user(['id' => $id]);
        send_verification(['id' => $id, 'email' => $in['email'], 'full_name' => $in['full_name']]);
        flash('Your account is ready. We sent a link to ' . $in['email'] . ' to confirm your email.');
        redirect('/portal/');
    }
}

page_start('Sign up');
?>
<div class="narrow">
  <h1>Create your account</h1>
  <p class="muted">Already have one? <a href="/portal/login.php">Log in</a>.</p>
  <?php if ($errors): ?><div class="error" role="alert"><?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?></div><?php endif; ?>
  <form method="post" class="stack" novalidate>
    <?= csrf_field() ?>
    <fieldset>
      <legend>I'm signing up as</legend>
      <div class="choice">
        <label><input type="radio" name="role" value="tech" <?= $in['role'] === 'tech' ? 'checked' : '' ?>>
          <span>A tech<small>Find work orders and get paid.</small></span></label>
        <label><input type="radio" name="role" value="provider" <?= $in['role'] === 'provider' ? 'checked' : '' ?>>
          <span>A provider<small>Post work and hire techs.</small></span></label>
      </div>
    </fieldset>
    <label>Full name <input type="text" name="full_name" autocomplete="name" required value="<?= e($in['full_name']) ?>"></label>
    <label>Company name <span class="hint">Providers only.</span>
      <input type="text" name="company" autocomplete="organization" value="<?= e($in['company']) ?>"></label>
    <label>Email <input type="email" name="email" autocomplete="email" required value="<?= e($in['email']) ?>"></label>
    <label>Password <span class="hint">At least 10 characters.</span>
      <input type="password" name="password" autocomplete="new-password" required minlength="10"></label>
    <label class="check"><input type="checkbox" name="terms" value="1">
      <span>I agree to the <a href="/terms.html" target="_blank">Terms of Service</a> and <a href="/privacy.html" target="_blank">Privacy Policy</a>, including keeping other users' information confidential and booking all work with people I meet here through Electro Service.</span></label>
    <div><button class="btn primary">Create account</button></div>
  </form>
</div>
<?php page_end();
