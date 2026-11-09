<?php
/**
 * bootstrap.php - loaded at the top of every page.
 * Sets up configuration, error handling, the session and the helper functions.
 */
declare(strict_types=1);

$GLOBALS['CONFIG'] = require __DIR__ . '/../config.php';
date_default_timezone_set($GLOBALS['CONFIG']['timezone']);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/ml_client.php';
require_once __DIR__ . '/layout.php';

// Never show raw PHP errors to visitors; they can reveal file paths and SQL.
ini_set('display_errors', $GLOBALS['CONFIG']['debug'] ? '1' : '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e) {
    error_log((string)$e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    $detail = $GLOBALS['CONFIG']['debug'] ? '<pre>' . e((string)$e) . '</pre>' : '';
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
       . '<div style="font-family:sans-serif;max-width:560px;margin:80px auto">'
       . '<h2>Something went wrong</h2>'
       . '<p>The page could not be shown. Check that MySQL is running and the database has been imported.</p>'
       . $detail . '</div>';
    exit;
});

start_secure_session();
