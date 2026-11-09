<?php
/**
 * index.php - the dashboard (home page): overall numbers, an orders trend
 * chart, a waste-by-category chart, and the meals wasting the most food.
 * Everything here is read-only reporting over weekly_records -- no ML model
 * is called on this page, unlike predict.php.
 */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$centers = all_centers();
$centerId = input_int($_GET, 'center');
if ($centerId !== null && get_center($centerId) === null) {
    $centerId = null;
}

$stats = dashboard_stats($centerId);
$latest = $stats['latest_week'];
$totals = weekly_totals($centerId, $latest, 26);
$byCategory = waste_by_category($centerId, $latest, 12);
$topWaste = top_wasted_meals($centerId, $latest, 12, 5);

// % change vs the 4 weeks before that, for the little up/down arrow in the KPI card.
// Left as null (shown as "no earlier data") rather than divide-by-zero when there
// is no earlier 4-week window to compare against yet.
$change = $stats['orders_prev4'] > 0 ? ($stats['orders_last4'] / $stats['orders_prev4'] - 1) * 100 : null;

$trendChart = [
    'type' => 'line',
    'title' => 'Total weekly orders',
    'labels' => array_map(fn($r) => 'W' . $r['week_no'], $totals),
    'series' => [['name' => 'Orders', 'values' => array_map(fn($r) => (int)$r['orders'], $totals), 'color' => '#1f5f8b']],
];
$catChart = [
    'type' => 'bar',
    'title' => 'Waste rate by category',
    'labels' => array_map(fn($r) => $r['category'], $byCategory),
    'values' => array_map(fn($r) => $r['prepared'] > 0 ? round($r['waste'] / $r['prepared'] * 100, 1) : 0, $byCategory),
    'unit' => '%',
    'color' => '#c05621',
];

render_header('Dashboard', 'dashboard');
?>
<h1>Dashboard</h1>
<p class="sub">Latest data: week <?= e($latest) ?>. Waste figures cover the last 12 weeks.</p>

<form method="get" class="card actions">
    <label for="center" style="margin:0">Kitchen</label>
    <select id="center" name="center" style="max-width:320px" onchange="this.form.submit()">
        <?= options($centers, 'center_id', 'center_label', $centerId, 'All kitchens') ?>
    </select>
    <noscript><button class="btn btn-small" type="submit">Apply</button></noscript>
</form>

<?php if ($stats['simulated'] > 0): ?>
<div class="alert alert-info">
    Prepared and waste figures for imported dataset rows are <strong>simulated</strong>
    (the public dataset contains orders only). Rows you enter yourself are marked as recorded.
</div>
<?php endif; ?>

<div class="grid grid-4">
    <div class="card kpi"><div class="label">Weekly records</div><div class="value"><?= num($stats['records']) ?></div>
        <div class="note">meal / kitchen / week rows</div></div>
    <div class="card kpi"><div class="label">Orders, last 4 weeks</div><div class="value"><?= num($stats['orders_last4']) ?></div>
        <div class="note <?= $change !== null && $change >= 0 ? 'up' : 'down' ?>">
            <?= $change === null ? 'no earlier data' : ($change >= 0 ? '+' : '') . number_format($change, 1) . '% vs previous 4 weeks' ?></div></div>
    <div class="card kpi"><div class="label">Waste rate, last 12 weeks</div><div class="value"><?= pct($stats['waste_pct']) ?></div>
        <div class="note"><?= num($stats['waste_total']) ?> portions of prepared food</div></div>
    <div class="card kpi"><div class="label">Predictions made</div><div class="value"><?= num($stats['predictions']) ?></div>
        <div class="note"><a href="predict.php">Make a prediction</a></div></div>
</div>

<div class="grid grid-2">
    <div class="card">
        <h2>Orders per week</h2>
        <div class="chart" data-chart="<?= e(json_encode($trendChart)) ?>"></div>
    </div>
    <div class="card">
        <h2>Waste rate by category (%)</h2>
        <?php if ($byCategory): ?>
            <div class="chart" data-chart="<?= e(json_encode($catChart)) ?>"></div>
        <?php else: ?><p class="sub">No waste data yet.</p><?php endif; ?>
    </div>
</div>

<div class="card">
    <h2>Meals with the most waste (last 12 weeks)</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Meal</th><th class="num">Prepared</th><th class="num">Wasted</th><th class="num">Waste rate</th></tr></thead>
        <tbody>
        <?php foreach ($topWaste as $r): ?>
            <tr>
                <td><?= e(meal_label($r)) ?></td>
                <td class="num"><?= num($r['prepared']) ?></td>
                <td class="num"><?= num($r['waste']) ?></td>
                <td class="num"><?= pct($r['prepared'] > 0 ? $r['waste'] / $r['prepared'] * 100 : null) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$topWaste): ?><tr><td colspan="4">No data yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php render_footer();
