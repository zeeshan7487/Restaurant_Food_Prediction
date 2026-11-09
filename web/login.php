<?php
/**
 * login.php - the sign-in form. The actual checking (password hash
 * comparison, account lockout, timing-attack countermeasure) all happens
 * inside attempt_login() in includes/auth.php -- this page just collects the
 * username/password and shows whatever error message comes back.
 */
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $error = attempt_login(input_str($_POST, 'username', 50), (string)($_POST['password'] ?? ''));
    if ($error === null) {
        redirect('index.php');
    }
}

render_header('Log in');
?>
<div class="card login-box">
    <h1>Log in</h1>
    <p class="sub">Restaurant food demand and waste prediction</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus maxlength="50">
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button class="btn" type="submit">Log in</button>
    </form>
</div>
<?php render_footer();
