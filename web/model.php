<?php
/**
 * model.php - "Model & results" page: shows the offline evaluation the
 * training scripts produced (ml/train.py, ml/evaluate_policy.py), not a live
 * calculation. The PHP app itself never trains or evaluates anything -- it
 * just reads the two JSON files those scripts write under ml/reports/ and
 * shows the PNG charts they also saved there (served through
 * report_image.php, never as a direct file path).
 */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$dir = $GLOBALS['CONFIG']['reports_dir'];
$metrics = is_file("$dir/metrics.json") ? json_decode(file_get_contents("$dir/metrics.json"), true) : null;
$policy = is_file("$dir/policy_results.json") ? json_decode(file_get_contents("$dir/policy_results.json"), true) : null;
$health = ml_health();   // is the Python prediction service actually reachable right now?

render_header('Model & results', 'model');
?>
<h1>Model and results</h1>
<p class="sub">How the forecasting model was built and how well it performed on weeks it had never seen.</p>

<div class="card">
    <h2>Prediction service</h2>
    <?php if (!empty($health['ok'])): ?>
        <span class="badge badge-ok">running</span>
        Model in use: <strong><?= e($health['model_name']) ?></strong>.
        Test weeks: <?= e($health['split']['test_weeks'][0]) ?> to <?= e($health['split']['test_weeks'][1]) ?>.
    <?php else: ?>
        <span class="badge badge-bad">not running</span>
        <?= e($health['error'] ?? 'Unknown problem.') ?>
    <?php endif; ?>
</div>

<?php if (!$metrics): ?>
    <div class="alert alert-warning">No results found yet. Run <code>python train.py</code> and <code>python evaluate_policy.py</code> in the ml folder.</div>
<?php else: ?>
    <?php if (!empty($metrics['trained_on_synthetic_data'])): ?>
        <div class="alert alert-warning">These results come from <strong>synthetic development data</strong>. Replace the data and retrain before quoting any number.</div>
    <?php endif; ?>

    <div class="card">
        <h2>Model comparison (test weeks <?= e($metrics['split']['test_weeks'][0]) ?>-<?= e($metrics['split']['test_weeks'][1]) ?>)</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Model</th><th class="num">MAE</th><th class="num">RMSE</th><th class="num">WAPE %</th><th class="num">R&sup2;</th><th class="num">Bias %</th></tr></thead>
            <tbody>
            <?php foreach ($metrics['comparison'] as $name => $m): $t = $m['test']; ?>
                <tr <?= $name === $metrics['best_model'] ? 'style="font-weight:700"' : '' ?>>
                    <td><?= e($name) ?><?= $name === $metrics['best_model'] ? ' (chosen)' : '' ?></td>
                    <td class="num"><?= e($t['MAE']) ?></td><td class="num"><?= e($t['RMSE']) ?></td>
                    <td class="num"><?= e($t['WAPE_%']) ?></td><td class="num"><?= e($t['R2']) ?></td><td class="num"><?= e($t['Bias_%']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <p class="hint">MAE = average miss in portions. WAPE = total error as a percentage of total demand (lower is better).
            The two "Baseline" rows are simple rules of thumb; a useful model must beat them.</p>
        <div class="two-col">
            <img class="figure" src="report_image.php?f=fig_model_comparison.png" alt="Model comparison chart">
            <img class="figure" src="report_image.php?f=fig_actual_vs_predicted.png" alt="Actual versus predicted orders">
        </div>
    </div>

    <?php if ($policy): $h = $policy['headline']; ?>
    <div class="card">
        <h2>Waste study (simulated on the test weeks)</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Planning method</th><th class="num">Prepared</th><th class="num">Wasted</th><th class="num">Waste % of prepared</th>
                <th class="num">Shortage % of demand</th><th class="num">Demand covered</th></tr></thead>
            <tbody>
                <tr><td>Usual planning (<?= e($h['manual_policy']) ?>)</td><td class="num"><?= num($h['manual']['prepared_total']) ?></td>
                    <td class="num"><?= num($h['manual']['waste_total']) ?></td><td class="num"><?= e($h['manual']['waste_pct_of_prepared']) ?>%</td>
                    <td class="num"><?= e($h['manual']['shortage_pct_of_demand']) ?>%</td><td class="num"><?= e($h['manual']['service_level_pct']) ?>%</td></tr>
                <tr><td>AI-assisted (<?= e($h['ai_policy_at_similar_service_level']) ?>)</td><td class="num"><?= num($h['ai']['prepared_total']) ?></td>
                    <td class="num"><?= num($h['ai']['waste_total']) ?></td><td class="num"><?= e($h['ai']['waste_pct_of_prepared']) ?>%</td>
                    <td class="num"><?= e($h['ai']['shortage_pct_of_demand']) ?>%</td><td class="num"><?= e($h['ai']['service_level_pct']) ?>%</td></tr>
            </tbody></table></div>
        <?php /* The 95% interval here comes from the block bootstrap in
                 ml/evaluate_policy.py (resampling whole meal/kitchen series,
                 not individual weeks) -- it is PHP's job only to display the
                 two numbers that script already computed, not to calculate
                 them. */ ?>
        <p><strong>Waste reduction at the same coverage:</strong> <?= e($h['waste_reduction_pct']) ?>%
            (95% interval <?= e($h['waste_reduction_ci95_pct'][0]) ?>% to <?= e($h['waste_reduction_ci95_pct'][1]) ?>%).</p>
        <p class="hint">The AI safety factor is tuned so both methods cover demand equally often, so the AI does not "save waste" simply by
            preparing less. Waste here is simulated from orders: it shows what the forecast could achieve, not a measured result.</p>
        <img class="figure" src="report_image.php?f=fig_waste_tradeoff.png" alt="Waste versus shortage trade-off chart">
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>What the model pays attention to</h2>
        <img class="figure" src="report_image.php?f=fig_feature_importance.png" alt="Feature importance chart" style="max-width:640px">
    </div>
<?php endif; ?>
<?php render_footer();
