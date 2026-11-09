<?php
/**
 * import_data.php - loads the prepared CSV files into the MySQL database.
 *
 * Run from a terminal (in XAMPP: use the "Shell" button, or C:\xampp\php\php.exe):
 *     php web/tools/import_data.php
 *
 * Before this: (1) import db/schema.sql, (2) run "python prepare_import.py" in the ml folder.
 * Safe to run again: it empties centers/meals/weekly_records first (users are kept).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this script from the command line only.\n");
}

$GLOBALS['CONFIG'] = require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';

$dir = __DIR__ . '/../../data/processed';
foreach (['centers.csv', 'meals.csv', 'weekly_records.csv'] as $f) {
    if (!is_file("$dir/$f")) {
        exit("Missing $dir/$f - run 'python prepare_import.py' in the ml folder first.\n");
    }
}

$pdo = db();
$now = date('Y-m-d H:i:s');

/** Read a CSV file and call $handle(array $row) for every data row. */
function each_csv_row(string $path, callable $handle): void
{
    $fh = fopen($path, 'r');
    $header = fgetcsv($fh, 0, ',', '"', '');
    while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if ($row === [null]) {
            continue;   // fgetcsv() returns [null] for a truly blank line; skip it
        }
        $handle(array_combine($header, $row));
    }
    fclose($fh);
}

/** Insert rows in batches: one INSERT with many (?,?,?) groups is much faster than one per row. */
function batch_insert(PDO $pdo, string $table, array $columns, array $rows): void
{
    if (!$rows) {
        return;
    }
    $group = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $sql = "INSERT INTO $table (" . implode(',', $columns) . ') VALUES ' . implode(',', array_fill(0, count($rows), $group));
    $params = [];
    foreach ($rows as $r) {
        foreach ($r as $v) {
            $params[] = $v;
        }
    }
    $pdo->prepare($sql)->execute($params);
}

// Everything below runs as one transaction: if anything fails partway through,
// the whole import rolls back rather than leaving the database half-emptied.
$pdo->beginTransaction();
try {
    // Delete in this order (predictions, then weekly_records, then meals and
    // centers) because of the foreign keys in db/schema.sql: a row can't be
    // deleted while something else still points at it.
    $pdo->exec('DELETE FROM predictions');
    $pdo->exec('DELETE FROM weekly_records');
    $pdo->exec('DELETE FROM meals');
    $pdo->exec('DELETE FROM centers');

    $batch = [];
    each_csv_row("$dir/centers.csv", function ($r) use (&$batch) {
        $batch[] = [(int)$r['center_id'], $r['city_code'], $r['region_code'], $r['center_type'], (float)$r['op_area']];
    });
    batch_insert($pdo, 'centers', ['center_id', 'city_code', 'region_code', 'center_type', 'op_area'], $batch);
    echo 'Centers: ' . count($batch) . "\n";

    $batch = [];
    each_csv_row("$dir/meals.csv", function ($r) use (&$batch) {
        $batch[] = [(int)$r['meal_id'], $r['category'], $r['cuisine']];
    });
    batch_insert($pdo, 'meals', ['meal_id', 'category', 'cuisine'], $batch);
    echo 'Meals: ' . count($batch) . "\n";

    // Each row has 13 values; MySQL allows at most 65,535 placeholders per statement,
    // so 500 rows per statement (6,500 placeholders) is comfortably safe.
    $cols = ['week_no', 'center_id', 'meal_id', 'checkout_price', 'base_price', 'emailer_for_promotion',
             'homepage_featured', 'orders_sold', 'prepared_qty', 'waste_qty', 'waste_source', 'created_by', 'created_at'];
    $batch = [];
    $total = 0;
    $flush = function () use ($pdo, $cols, &$batch, &$total) {
        batch_insert($pdo, 'weekly_records', $cols, $batch);
        $total += count($batch);
        $batch = [];
        echo "\rWeekly records: $total";
    };
    each_csv_row("$dir/weekly_records.csv", function ($r) use (&$batch, $flush, $now) {
        $prep = $r['prepared_qty'] === '' ? null : (int)round((float)$r['prepared_qty']);
        $waste = $r['waste_qty'] === '' ? null : (int)round((float)$r['waste_qty']);
        $batch[] = [(int)$r['week_no'], (int)$r['center_id'], (int)$r['meal_id'], (float)$r['checkout_price'],
                    (float)$r['base_price'], (int)$r['emailer_for_promotion'], (int)$r['homepage_featured'],
                    (int)$r['orders_sold'], $prep, $waste, $r['waste_source'] === '' ? null : $r['waste_source'], null, $now];
        if (count($batch) >= 500) {
            $flush();
        }
    });
    $flush();
    echo "\n";

    $pdo->commit();
    echo "Import finished.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Import failed and was rolled back: " . $e->getMessage() . "\n");
    exit(1);
}
