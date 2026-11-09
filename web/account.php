<?php
/**
 * account.php - lets the signed-in user change their own password (any
 * logged-in user, not just admins -- this is self-service, unlike users.php).
 */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    // Must prove you know the CURRENT password before setting a new one, even
    // though you're already logged in -- this stops someone who grabs an
    // unlocked, still-logged-in browser from locking the real owner out.
    $row = db_one('SELECT password_hash FROM users WHERE user_id = ?', [$user['user_id']]);
    if (!$row || !password_verify($current, $row['password_hash'])) {
        $error = 'Your current password is not correct.';
    } elseif ($new !== $confirm) {
        $error = 'The two new passwords do not match.';
    } elseif (($problem = password_problem($new)) !== null) {
        $error = $problem;
    } else {
        db_run('UPDATE users SET password_hash = ? WHERE user_id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['user_id']]);
        flash('success', 'Password changed.');
        redirect('account.php');
    }
}

render_header('My account');
?>
<h1>My account</h1>
<p class="sub">Signed in as <strong><?= e($user['username']) ?></strong> (<?= e($user['role']) ?>).</p>
<form method="post" class="card" style="max-width:460px" autocomplete="off">
    <h2>Change password</h2>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="field"><label for="current">Current password</label><input type="password" id="current" name="current" required></div>
    <div class="field"><label for="new">New password</label><input type="password" id="new" name="new" required>
        <div class="hint">At least 8 characters with a letter and a number.</div></div>
    <div class="field"><label for="confirm">Repeat new password</label><input type="password" id="confirm" name="confirm" required></div>
    <button class="btn" type="submit">Change password</button>
</form>
<?php render_footer();
