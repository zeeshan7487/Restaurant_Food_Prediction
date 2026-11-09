<?php
/**
 * predict.php - the main "ask for a forecast" page (Objective 6/7).
 *
 * This is a 3-step wizard on one page, driven by which GET/POST fields are
 * present: (1) pick a kitchen, (2) pick a meal, (3) fill in the prediction form
 * and submit it. On submit, this page does NOT run any machine learning itself
 * -- it just collects the kitchen/meal/price/history details and hands them to
 * the Python prediction service via ml_predict() (includes/ml_client.php), then
 * saves whatever comes back as an audit row (save_prediction()) so the
 * "prediction history" page can later compare it with the real orders.
 */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

const MANUAL_BUFFER = 0.15;   // must match MANUAL_BUFFER in ml/config.py (the "usual planning" yardstick)
$LEVELS = ['0.5' => '50% - lean', '0.7' => '70%', '0.8' => '80% - balanced (default)', '0.9' => '90% - cautious', '0.95' => '95% - very cautious'];

$centers = all_centers();
$params = array_merge($_GET, $_POST);
$centerId = input_int($params, 'center');
$mealId = input_int($params, 'meal');
$center = $centerId ? get_center($centerId) : null;
$meal = $mealId ? get_meal($mealId) : null;

$errors = [];
$result = null;
$inputs = null;
$history = [];
$actual = null;

if ($center && $meal) {
    // Step 3 reached (kitchen AND meal both chosen): pre-fill the form with
    // sensible defaults so the user usually just has to change the week number.
    $last = last_record($center['center_id'], $meal['meal_id']);
    if (!$last) {
        flash('warning', 'That meal has no sales history at this kitchen. Add weekly records first.');
        redirect('predict.php?center=' . $center['center_id']);
    }
    // default values for the form
    $lastWeek = (int)$last['week_no'];
    $inputs = [
        'week'                  => $lastWeek + 1,
        'checkout_price'        => $last['checkout_price'],
        'base_price'            => $last['base_price'],
        'emailer_for_promotion' => 0,
        'homepage_featured'     => 0,
        'service_level'         => '0.8',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $center && $meal) {
    csrf_check();
    $week  = input_int($_POST, 'week');
    $price = input_float($_POST, 'checkout_price');
    $base  = input_float($_POST, 'base_price');
    $level = input_str($_POST, 'service_level', 4);

    if ($week === null || $week < 2 || $week > 9999) { $errors['week'] = 'Enter the week you want to predict (2 or more).'; }
    if ($price === null || $price <= 0) { $errors['checkout_price'] = 'Enter the planned selling price.'; }
    if ($base === null || $base <= 0) { $errors['base_price'] = 'Enter the normal (base) price.'; }
    if (!isset($LEVELS[$level])) { $level = '0.8'; }

    $inputs = [
        'week' => $week, 'checkout_price' => $_POST['checkout_price'] ?? '', 'base_price' => $_POST['base_price'] ?? '',
        'emailer_for_promotion' => isset($_POST['emailer_for_promotion']) ? 1 : 0,
        'homepage_featured' => isset($_POST['homepage_featured']) ? 1 : 0,
        'service_level' => $level,
    ];

    if (!$errors) {
        // Pull the last few weeks of real sales from MySQL -- this is the
        // "history" the prediction service needs to build lag features from
        // (the same ones features.py computes during training).
        $history = recent_history($center['center_id'], $meal['meal_id'], $week);
        if (!$history) {
            $errors['week'] = 'No sales were recorded in the ' . HISTORY_WEEKS . ' weeks before week ' . $week . '. Enter recent weekly sales first.';
        }
    }

    if (!$errors) {
        $inputs['checkout_price'] = $price;
        $inputs['base_price'] = $base;
        // Everything the ML side needs, packaged as one JSON request (see
        // ml/service.py for exactly what it expects and does with this).
        $payload = build_prediction_payload($center, $meal, $week, $inputs, $history, $level);
        $response = ml_predict($payload);

        if (empty($response['ok'])) {
            $errors['service'] = $response['error'] ?? 'The prediction failed.';
        } else {
            $result = $response;
            // Keep a permanent record of this forecast (what was predicted, with
            // what model and inputs) so it can be compared with the real orders
            // later, on the Prediction History page.
            save_prediction([
                'week_no' => $week, 'center_id' => $center['center_id'], 'meal_id' => $meal['meal_id'],
                'checkout_price' => $price, 'base_price' => $base,
                'emailer_for_promotion' => $inputs['emailer_for_promotion'], 'homepage_featured' => $inputs['homepage_featured'],
                'predicted_demand' => $response['predicted_demand'], 'service_level' => (float)$response['service_level'],
                'recommended_prep' => $response['recommended_prep'], 'model_name' => $response['model_name'],
                'history_weeks_used' => $response['history_weeks_used'], 'created_by' => $user['user_id'],
            ]);
            $actual = db_value('SELECT orders_sold FROM weekly_records WHERE week_no = ? AND center_id = ? AND meal_id = ?',
                [$week, $center['center_id'], $meal['meal_id']]);
        }
    }
}

render_header('Predict demand', 'predict');
?>
<h1>Predict demand and waste</h1>
<p class="sub">Choose a kitchen and a meal. The system looks at recent weekly sales, sends them to the prediction model and recommends how much to prepare.</p>

<?php if (!$center): ?>
    <form method="get" class="card">
        <div class="field" style="max-width:420px">
            <label for="center">Step 1: choose a kitchen</label>
            <select id="center" name="center" required><?= options($centers, 'center_id', 'center_label', null, 'Choose...') ?></select>
        </div>
        <button class="btn" type="submit">Next</button>
    </form>

<?php elseif (!$meal): ?>
    <form method="get" class="card">
        <input type="hidden" name="center" value="<?= e($center['center_id']) ?>">
        <p><strong><?= e(center_label($center)) ?></strong> - <a href="predict.php">change</a></p>
        <div class="field" style="max-width:420px">
            <label for="meal">Step 2: choose a meal</label>
            <select id="meal" name="meal" required><?= options(meals_for_center((int)$center['center_id']), 'meal_id', 'meal_label', null, 'Choose...') ?></select>
        </div>
        <button class="btn" type="submit">Next</button>
    </form>

<?php else: ?>
    <?php if (!empty($errors['service'])): ?><div class="alert alert-error"><?= e($errors['service']) ?></div><?php endif; ?>

    <form method="post" class="card" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="center" value="<?= e($center['center_id']) ?>">
        <input type="hidden" name="meal" value="<?= e($meal['meal_id']) ?>">
        <p><strong><?= e(center_label($center)) ?></strong> &middot; <strong><?= e(meal_label($meal)) ?></strong>
            - <a href="predict.php">change</a></p>
        <div class="form-grid">
            <div class="field"><label for="week">Week to predict</label>
                <input type="number" id="week" name="week" min="2" value="<?= e($inputs['week']) ?>" required>
                <div class="hint">Latest recorded week for this meal is <?= e($lastWeek) ?>.</div>
                <?php if (isset($errors['week'])): ?><div class="error-text"><?= e($errors['week']) ?></div><?php endif; ?></div>
            <div class="field"><label for="checkout_price">Planned selling price</label>
                <input type="number" step="0.01" min="0" id="checkout_price" name="checkout_price" value="<?= e($inputs['checkout_price']) ?>" required>
                <?php if (isset($errors['checkout_price'])): ?><div class="error-text"><?= e($errors['checkout_price']) ?></div><?php endif; ?></div>
            <div class="field"><label for="base_price">Normal (base) price</label>
                <input type="number" step="0.01" min="0" id="base_price" name="base_price" value="<?= e($inputs['base_price']) ?>" required>
                <?php if (isset($errors['base_price'])): ?><div class="error-text"><?= e($errors['base_price']) ?></div><?php endif; ?></div>
            <div class="field"><label for="service_level">How safe should the plan be?</label>
                <select id="service_level" name="service_level">
                    <?php foreach ($LEVELS as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= (string)$inputs['service_level'] === (string)$k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="hint">Higher = fewer sold-out cases but more food prepared (more possible waste).</div></div>
        </div>
        <div class="field"><label class="check"><input type="checkbox" name="emailer_for_promotion" value="1" <?= $inputs['emailer_for_promotion'] ? 'checked' : '' ?>> Promoted by e-mail that week</label></div>
        <div class="field"><label class="check"><input type="checkbox" name="homepage_featured" value="1" <?= $inputs['homepage_featured'] ? 'checked' : '' ?>> Featured on the homepage that week</label></div>
        <button class="btn" type="submit">Predict</button>
    </form>

    <?php if ($result):
        // "Usual planning" comparison shown next to the model's number: the same
        // simple rule used as the manual-policy yardstick throughout the thesis
        // (average of recent weeks, plus a 15% safety buffer), computed here live
        // from this meal/kitchen's own history so the user sees what they'd have
        // planned without the model.
        $orders = array_map(fn($h) => (int)$h['orders_sold'], $history);
        $manual = (int)round(array_sum($orders) / max(1, count($orders)) * (1 + MANUAL_BUFFER));
        // Build the "recent orders + prediction" chart: a normal line of actual
        // orders, with the predicted value appended as one extra point on a
        // second, dashed line that starts exactly where the actual line ends
        // (hence copying the last actual value into $predLine before appending
        // the prediction) -- this is what makes the two lines join up visually.
        $series = order_series((int)$center['center_id'], (int)$meal['meal_id'], (int)$inputs['week'], 12);
        $labels = array_map(fn($r) => 'W' . $r['week_no'], $series);
        $actualVals = array_map(fn($r) => (int)$r['orders_sold'], $series);
        $labels[] = 'W' . $inputs['week'];
        $predLine = array_fill(0, count($actualVals), null);
        if ($actualVals) { $predLine[count($actualVals) - 1] = end($actualVals); }
        $predLine[] = $result['predicted_demand'];
        $actualVals[] = null;   // no actual value yet for the week being predicted
        $chart = ['type' => 'line', 'title' => 'Recent orders and prediction', 'labels' => $labels, 'series' => [
            ['name' => 'Actual orders', 'values' => $actualVals, 'color' => '#1a202c'],
            ['name' => 'Predicted', 'values' => $predLine, 'color' => '#1f5f8b', 'dashed' => true, 'markLast' => true],
        ]];
    ?>
    <div class="card">
        <h2>Result for week <?= e($inputs['week']) ?></h2>
        <?php if (!empty($result['trained_on_synthetic_data'])): ?>
            <div class="alert alert-warning">This model was trained on <strong>synthetic development data</strong>. Do not use these numbers as real results.</div>
        <?php endif; ?>
        <div class="result">
            <div><div class="lbl">Predicted demand</div><div class="big"><?= num($result['predicted_demand']) ?></div><div class="lbl">portions</div></div>
            <div><div class="lbl">Recommended to prepare</div><div class="big"><?= num($result['recommended_prep']) ?></div>
                <div class="lbl">covers demand about <?= e((float)$result['service_level'] * 100) ?>% of the time</div></div>
            <div><div class="lbl">Usual planning would be</div><div class="big" style="color:#5f6b7a"><?= num($manual) ?></div>
                <div class="lbl">average of last <?= count($history) ?> weeks + <?= MANUAL_BUFFER * 100 ?>%</div></div>
        </div>
        <p class="hint" style="margin-top:14px">Reliability: <strong><?= e($result['reliability']) ?></strong>
            (<?= e($result['history_weeks_used']) ?> of <?= HISTORY_WEEKS ?> previous weeks available). Model: <?= e($result['model_name']) ?>.</p>
        <?php if ($actual !== null): ?>
            <p class="alert alert-info">Actual orders for this week are already recorded: <strong><?= num($actual) ?></strong>
                (prediction error <?= pct(abs($result['predicted_demand'] - $actual) / max(1, (float)$actual) * 100) ?>).</p>
        <?php endif; ?>

        <h2 style="margin-top:20px">Recent orders and this prediction</h2>
        <div class="chart" data-chart="<?= e(json_encode($chart)) ?>"></div>

        <h2 style="margin-top:20px">What if you plan more cautiously?</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Safety level</th><th class="num">Prepare</th><th>Meaning</th></tr></thead>
            <tbody>
            <?php foreach ($result['all_levels'] as $k => $lv): ?>
                <tr><td><?= e($LEVELS[$k] ?? $k) ?></td><td class="num"><?= num($lv['recommended_prep']) ?></td>
                    <td>Demand is covered in about <?= e((float)$k * 100) ?>% of similar cases</td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <p class="hint">Saved in <a href="predictions.php">prediction history</a>. Once the real orders for that week are entered, the history page shows how close the prediction was.</p>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php render_footer();
