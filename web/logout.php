<?php
/**
 * logout.php - ends the session (see logout() in includes/auth.php).
 * Only accepts POST (with its CSRF token) so a link or image on another site
 * can't silently log the user out just by being loaded in their browser.
 */
require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    logout();
}
redirect('login.php');
