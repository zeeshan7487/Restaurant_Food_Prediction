<?php
/**
 * predictions.php - history of every forecast ever made on predict.php, with
 * live accuracy figures (prediction_accuracy() in includes/queries.php) once
 * the real orders for that week have since been entered under Weekly records.
 */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$perPage = 20;
$total = (int)db_value('SELECT COUNT(*) FROM predictions');
$pg = paginate($total, $perPage, input_int($_GET, 'page') ?? 1);
$rows = list_predictions($perPage, $pg['offset']);
$acc = prediction_accuracy();

render_header('Prediction history', 'predictions');
?>
<h1>Prediction history</h1>
<p class="sub">Every prediction is saved. When the real orders for that week are entered under "Weekly records", the actual figure and the error appear here.</p>

<div class="grid grid-4">
    <div class="card kpi"><div class="label">Predictions saved</div><div class="value"><?= num($total) ?></div></div>
    <div class="card kpi"><div class="label">With real orders known</div><div class="value"><?= num($acc['n']) ?></div></div>
    <div class="card kpi"><div class="label">Forecast error (WAPE)</div><div class="value"><?= pct($acc['wape']) ?></div>
        <div class="note">total error / total actual orders</div></div>
    <div class="card kpi"><div class="label">Demand covered by plan</div><div class="value"><?= pct($acc['covered_pct'], 0) ?></div>
        <div class="note">recommended prep &ge; actual orders</div></div>
</div>

<div class="card"><div class="table-wrap">
<table>
    <thead><tr><th>Saved</th><th>Week</th><th>Kitchen</th><th>Meal</th><th class="num">Predicted</th><th class="num">Safety</th>
        <th class="num">Recommended prep</th><th class="num">Actual orders</th><th class="num">Error</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        // Signed % error for this one row (positive = over-predicted, negative =
        // under-predicted). Only computable once the real order count for that
        // week has been entered -- otherwise $r['actual'] is NULL and the column
        // just shows "not yet". This is a per-row figure for browsing; the WAPE
        // card above is the proper aggregate accuracy metric across all rows.
        $err = $r['actual'] !== null ? ($r['predicted_demand'] - $r['actual']) / max(1, (float)$r['actual']) * 100 : null; ?>
        <tr>
            <td><?= e($r['created_at']) ?></td>
            <td><?= e($r['week_no']) ?></td>
            <td><?= e($r['center_id']) ?></td>
            <td><?= e($r['meal_id']) ?> <span class="hint"><?= e($r['category']) ?></span></td>
            <td class="num"><?= num($r['predicted_demand']) ?></td>
            <td class="num"><?= e((float)$r['service_level'] * 100) ?>%</td>
            <td class="num"><?= num($r['recommended_prep']) ?></td>
            <td class="num"><?= $r['actual'] !== null ? num($r['actual']) : '<span class="hint">not yet</span>' ?></td>
            <td class="num"><?= $err !== null ? ($err >= 0 ? '+' : '') . number_format($err, 1) . '%' : '-' ?></td>
            <td><?= e($r['username'] ?? '-') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10">No predictions yet. <a href="predict.php">Make one</a>.</td></tr><?php endif; ?>
    </tbody>
</table></div>
<div class="pager">
    <?php if ($pg['page'] > 1): ?><a class="btn btn-secondary btn-small" href="<?= e(url_with(['page' => $pg['page'] - 1])) ?>">Previous</a><?php endif; ?>
    <span>Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span>
    <?php if ($pg['page'] < $pg['pages']): ?><a class="btn btn-secondary btn-small" href="<?= e(url_with(['page' => $pg['page'] + 1])) ?>">Next</a><?php endif; ?>
</div></div>
<?php render_footer();
