<?php
/**
 * auth.php - logging in, logging out and protecting pages.
 *
 * Security features (each one is a viva talking point):
 *  - Passwords are stored as bcrypt hashes (password_hash), never as plain text.
 *  - The session id is regenerated at login (prevents "session fixation").
 *  - The session cookie is HttpOnly (JavaScript cannot read it) and SameSite=Lax.
 *  - After 5 wrong passwords the account is locked for 5 minutes (slows guessing).
 *  - The same error message is shown for "wrong user" and "wrong password"
 *    so an attacker cannot discover which usernames exist.
 */
declare(strict_types=1);

const MAX_FAILED_LOGINS = 5;
const LOCK_MINUTES = 5;

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('fdwp_session');
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Only administrators can open this page.');
    }
    return $user;
}

/**
 * Try to log in. Returns null on success or an error message on failure.
 */
function attempt_login(string $username, string $password): ?string
{
    $generic = 'Wrong username or password.';
    $user = db_one('SELECT * FROM users WHERE username = ?', [$username]);

    if ($user === null) {
        // Even though we already know this username doesn't exist, we still run
        // password_verify() against a dummy hash before returning. Checking a
        // password normally takes a small, measurable amount of time; without
        // this line, a real username would take noticeably longer to reject than
        // a fake one, and an attacker could use that timing difference to guess
        // which usernames exist on the system ("timing attack").
        password_verify($password, '$2y$12$daZPQOOE4BZfpe5fPAwXm.sLILQ0l.NBxHDHnrYjUKZ8KmBZWkNom'); // same time cost
        return $generic;
    }

    if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
        return 'Too many failed attempts. Try again in a few minutes.';
    }

    if (!password_verify($password, $user['password_hash'])) {
        $fails = (int)$user['failed_logins'] + 1;
        $lock = null;
        if ($fails >= MAX_FAILED_LOGINS) {
            // 5 wrong passwords in a row -> lock the account for 5 minutes and
            // reset the counter, so the next failed attempt (after the lock
            // expires) starts counting from 1 again, not 6.
            $lock = date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60);
            $fails = 0;
        }
        db_run('UPDATE users SET failed_logins = ?, locked_until = ? WHERE user_id = ?',
            [$fails, $lock, $user['user_id']]);
        return $generic;
    }

    db_run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE user_id = ?', [$user['user_id']]);
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'user_id'   => (int)$user['user_id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
    ];
    return null;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

/** Password rules used when creating users or changing a password. */
function password_problem(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}
