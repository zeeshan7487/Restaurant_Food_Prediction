<?php
/**
 * create_user.php - create or reset a login from the command line.
 *
 *   php web/tools/create_user.php <username> <password> "<Full name>" [admin|manager]
 *
 * Useful if you forget the admin password:
 *   php web/tools/create_user.php admin NewPass123 "System Administrator" admin
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line only.\n");
}
if ($argc < 4) {
    exit("Usage: php create_user.php <username> <password> \"<Full name>\" [admin|manager]\n");
}

$GLOBALS['CONFIG'] = require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';

[$script, $username, $password, $fullName] = $argv;
$role = ($argv[4] ?? 'manager') === 'admin' ? 'admin' : 'manager';   // anything but exactly "admin" becomes "manager"

if (($problem = password_problem($password)) !== null) {
    exit($problem . "\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);   // same bcrypt hashing the website itself uses
$existing = db_one('SELECT user_id FROM users WHERE username = ?', [$username]);
if ($existing) {
    // Also clears any account lockout (failed_logins / locked_until, see
    // includes/auth.php) -- useful when this command is being run specifically
    // to recover a locked-out admin account.
    db_run('UPDATE users SET password_hash = ?, full_name = ?, role = ?, failed_logins = 0, locked_until = NULL WHERE user_id = ?',
        [$hash, $fullName, $role, $existing['user_id']]);
    echo "Updated user '$username'.\n";
} else {
    db_run('INSERT INTO users (username, password_hash, full_name, role, created_at) VALUES (?,?,?,?,?)',
        [$username, $hash, $fullName, $role, date('Y-m-d H:i:s')]);
    echo "Created user '$username'.\n";
}
