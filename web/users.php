<?php
/**
 * users.php - admin-only user management (add/delete accounts). require_admin()
 * in includes/auth.php blocks a manager from ever reaching this page, so the
 * role check doesn't need repeating anywhere else in this file.
 */
require __DIR__ . '/includes/bootstrap.php';
$admin = require_admin();

$errors = [];
$form = ['username' => '', 'full_name' => '', 'role' => 'manager'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = input_str($_POST, 'action', 20);

    if ($action === 'delete') {
        $id = input_int($_POST, 'user_id');
        // Stop an admin deleting their own account -- otherwise it's possible to
        // lock everyone out of user management if they're the only admin.
        if ($id === (int)$admin['user_id']) {
            flash('error', 'You cannot delete your own account.');
        } elseif ($id !== null && db_run('DELETE FROM users WHERE user_id = ?', [$id]) > 0) {
            flash('success', 'User deleted.');
        }
        redirect('users.php');
    }

    if ($action === 'add') {
        $form['username'] = input_str($_POST, 'username', 50);
        $form['full_name'] = input_str($_POST, 'full_name', 100);
        $form['role'] = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'manager';
        $password = (string)($_POST['password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $form['username'])) {
            $errors['username'] = 'Use 3-50 letters, numbers, dots, dashes or underscores.';
        } elseif (db_one('SELECT user_id FROM users WHERE username = ?', [$form['username']])) {
            $errors['username'] = 'That username is already taken.';
        }
        if ($form['full_name'] === '') { $errors['full_name'] = 'Enter the full name.'; }
        if (($problem = password_problem($password)) !== null) { $errors['password'] = $problem; }

        if (!$errors) {
            // password_hash() with PASSWORD_DEFAULT = bcrypt: the plain password is
            // never stored, only this one-way hash (same approach used when a user
            // changes their own password in account.php, and checked back against
            // with password_verify() in includes/auth.php at login time).
            db_run('INSERT INTO users (username, password_hash, full_name, role, created_at) VALUES (?,?,?,?,?)',
                [$form['username'], password_hash($password, PASSWORD_DEFAULT), $form['full_name'], $form['role'], date('Y-m-d H:i:s')]);
            flash('success', 'User created.');
            redirect('users.php');
        }
    }
}

$users = db_all('SELECT user_id, username, full_name, role, created_at FROM users ORDER BY username');

render_header('Users', 'users');
?>
<h1>Users</h1>
<p class="sub">Administrators can manage users and delete records. Managers can view data, add records and make predictions.</p>

<div class="card"><div class="table-wrap"><table>
    <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr><td><?= e($u['username']) ?></td><td><?= e($u['full_name']) ?></td><td><?= e($u['role']) ?></td><td><?= e($u['created_at']) ?></td>
            <td><?php if ((int)$u['user_id'] !== (int)$admin['user_id']): ?>
                <form method="post" class="inline" onsubmit="return confirm('Delete this user?');">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?= e($u['user_id']) ?>">
                    <button class="btn btn-danger btn-small" type="submit">Delete</button></form>
                <?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>

<form method="post" class="card" autocomplete="off">
    <h2>Add a user</h2>
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="form-grid">
        <div class="field"><label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= e($form['username']) ?>" required maxlength="50">
            <?php if (isset($errors['username'])): ?><div class="error-text"><?= e($errors['username']) ?></div><?php endif; ?></div>
        <div class="field"><label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" required maxlength="100">
            <?php if (isset($errors['full_name'])): ?><div class="error-text"><?= e($errors['full_name']) ?></div><?php endif; ?></div>
        <div class="field"><label for="role">Role</label>
            <select id="role" name="role"><option value="manager" <?= $form['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
                <option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option></select></div>
        <div class="field"><label for="password">Password</label>
            <input type="password" id="password" name="password" required>
            <div class="hint">At least 8 characters with a letter and a number.</div>
            <?php if (isset($errors['password'])): ?><div class="error-text"><?= e($errors['password']) ?></div><?php endif; ?></div>
    </div>
    <button class="btn" type="submit">Create user</button>
</form>
<?php render_footer();
