<?php
/**
 * report_image.php - serves the training charts (PNG files in ml/reports) to logged-in users.
 * Only file names on the allow-list below can be requested, so nobody can use
 * this script to read other files on the server ("path traversal").
 */
require __DIR__ . '/includes/bootstrap.php';
require_login();

$allowed = [
    'fig_model_comparison.png', 'fig_actual_vs_predicted.png', 'fig_weekly_total.png',
    'fig_feature_importance.png', 'fig_waste_tradeoff.png',
];
$name = input_str($_GET, 'f', 60);
if (!in_array($name, $allowed, true)) {
    http_response_code(404);
    exit('Not found');
}
$path = $GLOBALS['CONFIG']['reports_dir'] . '/' . $name;
if (!is_file($path)) {
    http_response_code(404);
    exit('Chart not generated yet. Run the training scripts first.');
}
header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
readfile($path);
