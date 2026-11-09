<?php
/**
 * ml_client.php - talks to the Python prediction service (ml/service.py).
 *
 * PHP sends the numbers as JSON with cURL and reads JSON back. If the service is
 * not running, we return a friendly error instead of crashing the page.
 */
declare(strict_types=1);

function ml_request(string $method, string $path, ?array $payload = null): array
{
    $cfg = $GLOBALS['CONFIG']['ml'];
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'The PHP cURL extension is not enabled (enable extension=curl in php.ini).'];
    }

    $ch = curl_init(rtrim($cfg['url'], '/') . $path);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($cfg['api_key'] !== '') {
        $headers[] = 'X-API-Key: ' . $cfg['api_key'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int)$cfg['timeout'],   // give up on the whole request after this many seconds
        CURLOPT_CONNECTTIMEOUT => 3,                       // but give up much sooner if the service isn't even reachable
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'unreachable' => true,
                'error' => 'The prediction service is not running. Start it with "python service.py" in the ml folder. (' . $err . ')'];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'The prediction service sent an unreadable answer.'];
    }
    return $data;
}

function ml_health(): array
{
    return ml_request('GET', '/health');
}

/** Ask the service for a forecast. $payload is described in ml/service.py. */
function ml_predict(array $payload): array
{
    return ml_request('POST', '/predict', $payload);
}

/** Build the request the service expects from database rows. */
function build_prediction_payload(array $center, array $meal, int $week, array $inputs, array $history, string $level): array
{
    $hist = [];
    foreach ($history as $h) {
        $hist[] = ['week' => (int)$h['week_no'], 'num_orders' => (int)$h['orders_sold']];
    }
    return [
        'week'                  => $week,
        'checkout_price'        => (float)$inputs['checkout_price'],
        'base_price'            => (float)$inputs['base_price'],
        'emailer_for_promotion' => (int)$inputs['emailer_for_promotion'],
        'homepage_featured'     => (int)$inputs['homepage_featured'],
        'service_level'         => $level,
        'center' => [
            'center_id'   => (int)$center['center_id'],
            'city_code'   => (string)$center['city_code'],
            'region_code' => (string)$center['region_code'],
            'center_type' => (string)$center['center_type'],
            'op_area'     => (float)$center['op_area'],
        ],
        'meal' => [
            'meal_id'  => (int)$meal['meal_id'],
            'category' => (string)$meal['category'],
            'cuisine'  => (string)$meal['cuisine'],
        ],
        'history' => $hist,
    ];
}
