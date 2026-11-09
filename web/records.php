<?php
/**
 * records.php - browse, filter, delete and export the raw weekly_records
 * table (one row = one meal/kitchen/week). This is the data entry and audit
 * page; record_edit.php is where a single row is actually added or changed.
 */
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

// ---- delete (admin only, POST + CSRF) --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Only administrators can delete records.');
    }
    $id = input_int($_POST, 'delete_id');
    if ($id !== null && db_run('DELETE FROM weekly_records WHERE record_id = ?', [$id]) > 0) {
        flash('success', 'Record deleted.');
    }
    redirect('records.php');
}

$filters = [
    'center' => input_int($_GET, 'center'),
    'meal'   => input_int($_GET, 'meal'),
    'from'   => input_int($_GET, 'from'),
    'to'     => input_int($_GET, 'to'),
];

// ---- CSV export of the filtered list ------------------------------------------------
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="weekly_records.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['week_no', 'center_id', 'meal_id', 'category', 'cuisine', 'checkout_price', 'base_price',
        'emailer_for_promotion', 'homepage_featured', 'orders_sold', 'prepared_qty', 'waste_qty', 'waste_source'], ',', '"', '');
    // Read the table in chunks of 5000 rows so a large export does not use much memory.
    for ($offset = 0; ; $offset += 5000) {
        $chunk = list_records($filters, 5000, $offset);
        if (!$chunk) {
            break;
        }
        foreach ($chunk as $r) {
            fputcsv($out, [$r['week_no'], $r['center_id'], $r['meal_id'], $r['category'], $r['cuisine'], $r['checkout_price'],
                $r['base_price'], $r['emailer_for_promotion'], $r['homepage_featured'], $r['orders_sold'],
                $r['prepared_qty'], $r['waste_qty'], $r['waste_source']], ',', '"', '');
        }
    }
    fclose($out);
    exit;
}

$perPage = 25;
$total = count_records($filters);
$pg = paginate($total, $perPage, input_int($_GET, 'page') ?? 1);
$rows = list_records($filters, $perPage, $pg['offset']);

render_header('Weekly records', 'records');
?>
<div class="actions" style="justify-content:space-between">
    <div><h1>Weekly records</h1>
    <p class="sub"><?= num($total) ?> records. One row = one meal at one kitchen in one week.</p></div>
    <div class="actions">
        <a class="btn btn-secondary" href="<?= e(url_with(['export' => 1, 'page' => null])) ?>">Export CSV</a>
        <a class="btn" href="record_edit.php">Add record</a>
    </div>
</div>

<form method="get" class="card">
    <div class="form-grid">
        <div class="field"><label for="center">Kitchen</label>
            <select id="center" name="center"><?= options(all_centers(), 'center_id', 'center_label', $filters['center'], 'All') ?></select></div>
        <div class="field"><label for="meal">Meal</label>
            <select id="meal" name="meal"><?= options(all_meals(), 'meal_id', 'meal_label', $filters['meal'], 'All') ?></select></div>
        <div class="field"><label for="from">From week</label>
            <input type="number" id="from" name="from" min="1" value="<?= e($filters['from']) ?>"></div>
        <div class="field"><label for="to">To week</label>
            <input type="number" id="to" name="to" min="1" value="<?= e($filters['to']) ?>"></div>
    </div>
    <div class="actions"><button class="btn" type="submit">Filter</button>
        <a class="btn btn-secondary" href="records.php">Clear</a></div>
</form>

<div class="card">
<div class="table-wrap">
<table>
    <thead><tr>
        <th>Week</th><th>Kitchen</th><th>Meal</th><th class="num">Price</th><th>Promo</th><th>Featured</th>
        <th class="num">Sold</th><th class="num">Prepared</th><th class="num">Wasted</th><th>Waste data</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['week_no']) ?></td>
            <td><?= e($r['center_id']) ?></td>
            <td><?= e($r['meal_id']) ?> <span class="hint"><?= e($r['category']) ?></span></td>
            <td class="num"><?= num($r['checkout_price'], 2) ?></td>
            <td><?= $r['emailer_for_promotion'] ? 'Yes' : '-' ?></td>
            <td><?= $r['homepage_featured'] ? 'Yes' : '-' ?></td>
            <td class="num"><?= num($r['orders_sold']) ?></td>
            <td class="num"><?= num($r['prepared_qty']) ?></td>
            <td class="num"><?= num($r['waste_qty']) ?></td>
            <td><?php if ($r['waste_source'] === 'simulated'): ?><span class="badge badge-sim">simulated</span>
                <?php elseif ($r['waste_source'] === 'recorded'): ?><span class="badge badge-ok">recorded</span>
                <?php else: ?>-<?php endif; ?></td>
            <td class="actions">
                <a class="btn btn-secondary btn-small" href="record_edit.php?id=<?= e($r['record_id']) ?>">Edit</a>
                <a class="btn btn-secondary btn-small" href="record_edit.php?center=<?= e($r['center_id']) ?>&amp;meal=<?= e($r['meal_id']) ?>">Next week</a>
                <?php if ($user['role'] === 'admin'): ?>
                <form method="post" class="inline" onsubmit="return confirm('Delete this record?');">
                    <?= csrf_field() ?><input type="hidden" name="delete_id" value="<?= e($r['record_id']) ?>">
                    <button class="btn btn-danger btn-small" type="submit">Delete</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11">No records match.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<div class="pager">
    <?php if ($pg['page'] > 1): ?><a class="btn btn-secondary btn-small" href="<?= e(url_with(['page' => $pg['page'] - 1])) ?>">Previous</a><?php endif; ?>
    <span>Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span>
    <?php if ($pg['page'] < $pg['pages']): ?><a class="btn btn-secondary btn-small" href="<?= e(url_with(['page' => $pg['page'] + 1])) ?>">Next</a><?php endif; ?>
</div>
</div>
<?php render_footer();
