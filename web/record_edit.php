<?php
/**
 * record_edit.php - add a new weekly sales record, or edit an existing one.
 *
 * One row here is one week of one meal at one kitchen (table weekly_records).
 * This is the data the ML model is trained on (ml/train.py) and the history it
 * reads at prediction time (ml/features.py), so keeping it accurate matters.
 * When editing an existing record ($existing set), the week/kitchen/meal are
 * fixed (shown read-only below) -- only the numbers can change -- because those
 * three together are the record's identity (see uq_record in db/schema.sql,
 * which stops a duplicate week/kitchen/meal row from ever being saved);
 * changing any of them would really mean creating a different record, not
 * editing this one.
 */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$id = input_int($_GET, 'id');
$existing = $id ? db_one('SELECT * FROM weekly_records WHERE record_id = ?', [$id]) : null;
if ($id && !$existing) {
    http_response_code(404);
    exit('Record not found.');
}

$centers = all_centers();
$meals = all_meals();
$errors = [];

// ---- starting values for the form -------------------------------------------------------
if ($existing) {
    $v = $existing;
} else {
    $v = ['week_no' => latest_week() + 1, 'center_id' => input_int($_GET, 'center'), 'meal_id' => input_int($_GET, 'meal'),
          'checkout_price' => '', 'base_price' => '', 'emailer_for_promotion' => 0, 'homepage_featured' => 0,
          'orders_sold' => '', 'prepared_qty' => '', 'waste_qty' => ''];
    // "Next week" shortcut: copy prices from the newest record of that meal at that kitchen
    if ($v['center_id'] && $v['meal_id'] && ($last = last_record($v['center_id'], $v['meal_id']))) {
        $v['week_no'] = (int)$last['week_no'] + 1;
        $v['checkout_price'] = $last['checkout_price'];
        $v['base_price'] = $last['base_price'];
    }
}

// ---- saving ------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $week    = $existing ? (int)$existing['week_no']   : input_int($_POST, 'week_no');
    $centerId = $existing ? (int)$existing['center_id'] : input_int($_POST, 'center_id');
    $mealId   = $existing ? (int)$existing['meal_id']   : input_int($_POST, 'meal_id');
    $price   = input_float($_POST, 'checkout_price');
    $base    = input_float($_POST, 'base_price');
    $promo   = isset($_POST['emailer_for_promotion']) ? 1 : 0;
    $feat    = isset($_POST['homepage_featured']) ? 1 : 0;
    $sold    = input_int($_POST, 'orders_sold');
    $prep    = input_int($_POST, 'prepared_qty');
    $waste   = input_int($_POST, 'waste_qty');

    if ($week === null || $week < 1 || $week > 9999) { $errors['week_no'] = 'Enter a week number between 1 and 9999.'; }
    if (!$centerId || !get_center($centerId)) { $errors['center_id'] = 'Choose a kitchen.'; }
    if (!$mealId || !get_meal($mealId)) { $errors['meal_id'] = 'Choose a meal.'; }
    if ($price === null || $price <= 0 || $price > 100000) { $errors['checkout_price'] = 'Enter the selling price (greater than 0).'; }
    if ($base === null || $base <= 0 || $base > 100000) { $errors['base_price'] = 'Enter the normal (base) price (greater than 0).'; }
    if ($sold === null || $sold < 0 || $sold > 10000000) { $errors['orders_sold'] = 'Enter the number of portions sold (0 or more).'; }
    if ($prep !== null && $prep < 0) { $errors['prepared_qty'] = 'Prepared quantity cannot be negative.'; }
    if ($waste !== null && $waste < 0) { $errors['waste_qty'] = 'Wasted quantity cannot be negative.'; }
    if ($waste !== null && $prep === null) { $errors['prepared_qty'] = 'Enter the prepared quantity too, or leave both empty.'; }
    if ($waste !== null && $prep !== null && $waste > $prep) { $errors['waste_qty'] = 'Waste cannot be more than what was prepared.'; }

    // If prepared is known but waste is left empty, work it out: prepared minus sold (never below 0).
    if (!$errors && $prep !== null && $waste === null) {
        $waste = max($prep - $sold, 0);
    }

    $v = ['week_no' => $week, 'center_id' => $centerId, 'meal_id' => $mealId, 'checkout_price' => $_POST['checkout_price'] ?? '',
          'base_price' => $_POST['base_price'] ?? '', 'emailer_for_promotion' => $promo, 'homepage_featured' => $feat,
          'orders_sold' => $_POST['orders_sold'] ?? '', 'prepared_qty' => $_POST['prepared_qty'] ?? '',
          'waste_qty' => $_POST['waste_qty'] ?? ''];

    if (!$errors) {
        // waste_source records WHERE the waste number came from: 'recorded' when
        // someone actually entered a prepared quantity (so waste is a real
        // measurement, possibly calculated above), or NULL when no prepared
        // quantity was given at all. The ml/ pipeline uses this to tell real
        // waste figures apart from the 'simulated' ones generated when the
        // historical data was first prepared (see db/schema.sql).
        $source = $prep !== null ? 'recorded' : null;
        try {
            if ($existing) {
                db_run('UPDATE weekly_records
                           SET checkout_price = ?, base_price = ?, emailer_for_promotion = ?, homepage_featured = ?,
                               orders_sold = ?, prepared_qty = ?, waste_qty = ?, waste_source = ?
                         WHERE record_id = ?',
                    [$price, $base, $promo, $feat, $sold, $prep, $waste, $source, $existing['record_id']]);
                flash('success', 'Record updated.');
            } else {
                db_run('INSERT INTO weekly_records (week_no, center_id, meal_id, checkout_price, base_price,
                            emailer_for_promotion, homepage_featured, orders_sold, prepared_qty, waste_qty,
                            waste_source, created_by, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$week, $centerId, $mealId, $price, $base, $promo, $feat, $sold, $prep, $waste, $source,
                     $user['user_id'], date('Y-m-d H:i:s')]);
                flash('success', "Record saved for week $week.");
            }
            redirect('records.php?center=' . $centerId . '&meal=' . $mealId);
        } catch (PDOException $ex) {
            // SQLSTATE 23000 = "integrity constraint violation": this only happens
            // here if two people submit the same new week/kitchen/meal combination
            // at almost the same time (the uq_record unique key in db/schema.sql
            // catches it at the database level even though nothing looked wrong
            // when the page checked a moment earlier).
            if ($ex->getCode() === '23000') {
                $errors['week_no'] = 'A record for this week, kitchen and meal already exists. Edit that record instead.';
            } else {
                throw $ex;
            }
        }
    }
}

$title = $existing ? 'Edit record' : 'Add weekly record';
render_header($title, 'records');

function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="error-text">' . e($errors[$key]) . '</div>' : '';
}
?>
<h1><?= e($title) ?></h1>
<p class="sub">Enter what happened in one week for one meal at one kitchen. Prepared and wasted portions are optional but make the waste reports more useful.</p>

<?php if ($errors): ?><div class="alert alert-error">Please fix the highlighted fields.</div><?php endif; ?>

<form method="post" class="card" novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field"><label for="week_no">Week number</label>
            <input type="number" id="week_no" name="week_no" min="1" max="9999" required value="<?= e($v['week_no']) ?>" <?= $existing ? 'readonly' : '' ?>>
            <?= field_error($errors, 'week_no') ?></div>
        <div class="field"><label for="center_id">Kitchen</label>
            <select id="center_id" name="center_id" <?= $existing ? 'disabled' : '' ?>><?= options($centers, 'center_id', 'center_label', $v['center_id'], 'Choose...') ?></select>
            <?= field_error($errors, 'center_id') ?></div>
        <div class="field"><label for="meal_id">Meal</label>
            <select id="meal_id" name="meal_id" <?= $existing ? 'disabled' : '' ?>><?= options($meals, 'meal_id', 'meal_label', $v['meal_id'], 'Choose...') ?></select>
            <?= field_error($errors, 'meal_id') ?></div>
        <div class="field"><label for="checkout_price">Selling price</label>
            <input type="number" step="0.01" min="0" id="checkout_price" name="checkout_price" required value="<?= e($v['checkout_price']) ?>">
            <?= field_error($errors, 'checkout_price') ?></div>
        <div class="field"><label for="base_price">Normal (base) price</label>
            <input type="number" step="0.01" min="0" id="base_price" name="base_price" required value="<?= e($v['base_price']) ?>">
            <div class="hint">Selling price is lower than this when the meal is discounted.</div>
            <?= field_error($errors, 'base_price') ?></div>
        <div class="field"><label for="orders_sold">Portions sold (demand)</label>
            <input type="number" min="0" id="orders_sold" name="orders_sold" required value="<?= e($v['orders_sold']) ?>">
            <?= field_error($errors, 'orders_sold') ?></div>
        <div class="field"><label for="prepared_qty">Portions prepared (optional)</label>
            <input type="number" min="0" id="prepared_qty" name="prepared_qty" value="<?= e($v['prepared_qty']) ?>">
            <?= field_error($errors, 'prepared_qty') ?></div>
        <div class="field"><label for="waste_qty">Portions wasted (optional)</label>
            <input type="number" min="0" id="waste_qty" name="waste_qty" value="<?= e($v['waste_qty']) ?>">
            <div class="hint">Leave empty to calculate: prepared minus sold.</div>
            <?= field_error($errors, 'waste_qty') ?></div>
    </div>
    <div class="field"><label class="check"><input type="checkbox" name="emailer_for_promotion" value="1" <?= $v['emailer_for_promotion'] ? 'checked' : '' ?>> Promoted by e-mail this week</label></div>
    <div class="field"><label class="check"><input type="checkbox" name="homepage_featured" value="1" <?= $v['homepage_featured'] ? 'checked' : '' ?>> Featured on the homepage this week</label></div>
    <div class="actions">
        <button class="btn" type="submit">Save</button>
        <a class="btn btn-secondary" href="records.php">Cancel</a>
    </div>
</form>
<?php render_footer();
