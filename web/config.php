<?php
/**
 * config.php - every setting you may need to change lives here.
 *
 * The defaults match a fresh XAMPP installation (MySQL user "root", no password).
 * If you set a MySQL password, put it in 'pass' below.
 */
return [
    'app_name' => 'Food Demand & Waste Predictor',
    'timezone' => 'Asia/Karachi',
    'debug'    => false,            // true = show technical error messages (development only)

    'db' => [
        // 'mysql' is used by the real system. 'sqlite' exists only so the automated
        // tests can run on a computer without MySQL.
        'driver'      => getenv('FYP_DB_DRIVER') ?: 'mysql',
        'host'        => getenv('FYP_DB_HOST') ?: '127.0.0.1',
        'port'        => getenv('FYP_DB_PORT') ?: '3306',
        'name'        => getenv('FYP_DB_NAME') ?: 'restaurant_ai',
        'user'        => getenv('FYP_DB_USER') ?: 'root',
        'pass'        => getenv('FYP_DB_PASS') !== false ? getenv('FYP_DB_PASS') : '',
        'sqlite_path' => getenv('FYP_SQLITE_PATH') ?: '',
    ],

    // The Python prediction service (ml/service.py)
    'ml' => [
        'url'     => getenv('ML_URL') ?: 'http://127.0.0.1:5000',
        'api_key' => getenv('ML_API_KEY') ?: '',
        'timeout' => 15,
    ],

    // Folder with the charts and metrics written by the training scripts
    'reports_dir' => __DIR__ . '/../ml/reports',
];
