<?php
// Admin overview. Viewing here writes nothing users can see.
require __DIR__ . '/../../private/bootstrap.php';
$admin = require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['user_id'] ?? 0);
    $to = ($_POST['action'] ?? '') === 'suspend' ? 'suspended' : 'active';
    db()->prepare("UPDATE users SET status = ? WHERE id = ? AND role <> 'admin'")->execute([$to, $id]);
    log_activity((int)$admin['id'], 'admin_' . $to, 'user ' . $id);
    flash($to === 'suspended' ? 'Account suspended.' : 'Account reactivated.');
    redirect('/portal/admin/');
}

$counts = db()->query("SELECT role, COUNT(*) n FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
$pendingIns = (int)db()->query("SELECT COUNT(*) FROM insurance_docs WHERE status = 'pending'")->fetchColumn();
$users  = db()->query("SELECT * FROM users WHERE role <> 'admin' ORDER BY created_at DESC LIMIT 200")->fetchAll();
$log    = db()->query("SELECT a.*, u.full_name, u.role FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
                       ORDER BY a.id DESC LIMIT 50")->fetchAll();

page_start('Admin', $admin);
?>
<h1>Admin dashboard</h1>
<ul class="stats">
  <li><b><?= (int)($counts['tech'] ?? 0) ?></b> techs</li>
  <li><b><?= (int)($counts['provider'] ?? 0) ?></b> providers</li>
  <li><b><?= $pendingIns ?></b> insurance to review</li>
</ul>
<p><a class="btn <?= $pendingIns ? 'primary' : 'plain' ?>" href="/portal/admin/insurance.php">Review insurance</a>
  <a class="btn plain" href="/portal/admin/email.php">Email forwarding</a>
  <a class="btn plain" href="/portal/admin/texts.php">Texts</a></p>

<h2>Accounts</h2>
<?php if (!$users): ?>
  <p class="muted">No techs or providers have signed up yet.</p>
<?php else: ?>
<table>
  <thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Company / insurance</th><th>Joined (UTC)</th><th>Last login</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $r): ?>
    <tr>
      <td><?= e($r['full_name']) ?> <?php if ($r['status'] !== 'active'): ?><span class="tag suspended">Suspended</span><?php endif; ?></td>
      <td><span class="tag <?= e($r['role']) ?>"><?= e(ucfirst($r['role'])) ?></span></td>
      <td><?= e($r['email']) ?></td>
      <td><?php if ($r['role'] === 'tech') {
            $is = insurance_state((int)$r['id']);
            echo $is['insured'] ? 'Insured to ' . e($is['doc']['expires_on'])
               : ($is['doc'] ? e(ucfirst($is['expired'] ? 'expired' : $is['doc']['status'])) : '<span class="muted">No insurance</span>');
          } else { echo e($r['company']); } ?></td>
      <td><?= e(substr($r['created_at'], 0, 16)) ?></td>
      <td><?= e($r['last_login_at'] ? substr($r['last_login_at'], 0, 16) : 'Never') ?></td>
      <td>
        <form method="post" class="inline"><?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
          <?php if ($r['status'] === 'active'): ?>
            <button class="link" name="action" value="suspend">Suspend</button>
          <?php else: ?>
            <button class="link" name="action" value="activate">Reactivate</button>
          <?php endif; ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2 style="margin-top:2.5rem">Recent activity</h2>
<?php if (!$log): ?>
  <p class="muted">Nothing yet.</p>
<?php else: ?>
<table>
  <thead><tr><th>When (UTC)</th><th>Who</th><th>What</th><th>Detail</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($log as $a): ?>
    <tr>
      <td><?= e(substr($a['created_at'], 0, 16)) ?></td>
      <td><?= e($a['full_name'] ?? 'Unknown') ?></td>
      <td><?= e(str_replace('_', ' ', $a['action'])) ?></td>
      <td><?= e($a['detail']) ?></td>
      <td><?= e($a['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php page_end();
