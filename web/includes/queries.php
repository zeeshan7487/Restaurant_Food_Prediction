<?php
/**
 * queries.php - all reusable database questions in one place.
 * Pages call these functions instead of writing SQL everywhere.
 */
declare(strict_types=1);

const HISTORY_WEEKS = 4;   // how many previous weeks the model looks at (must match ml/config.py LAGS)

function all_centers(): array
{
    return db_all('SELECT * FROM centers ORDER BY center_id');
}

function all_meals(): array
{
    return db_all('SELECT * FROM meals ORDER BY category, meal_id');
}

function get_center(int $id): ?array
{
    return db_one('SELECT * FROM centers WHERE center_id = ?', [$id]);
}

function get_meal(int $id): ?array
{
    return db_one('SELECT * FROM meals WHERE meal_id = ?', [$id]);
}

/** Meals that have sales history at a given kitchen. */
function meals_for_center(int $centerId): array
{
    return db_all(
        'SELECT m.meal_id, m.category, m.cuisine, MAX(w.week_no) AS last_week
           FROM meals m
           JOIN weekly_records w ON w.meal_id = m.meal_id
          WHERE w.center_id = ?
          GROUP BY m.meal_id, m.category, m.cuisine
          ORDER BY m.category, m.meal_id',
        [$centerId]
    );
}

function latest_week(): int
{
    return (int)(db_value('SELECT MAX(week_no) FROM weekly_records') ?? 0);
}

/** The most recent record for a meal at a kitchen (used to pre-fill forms). */
function last_record(int $centerId, int $mealId): ?array
{
    return db_one(
        'SELECT * FROM weekly_records WHERE center_id = ? AND meal_id = ? ORDER BY week_no DESC LIMIT 1',
        [$centerId, $mealId]
    );
}

/** The previous weeks of orders before $week (newest first). */
function recent_history(int $centerId, int $mealId, int $week, int $weeks = HISTORY_WEEKS): array
{
    return db_all(
        'SELECT week_no, orders_sold, checkout_price, base_price
           FROM weekly_records
          WHERE center_id = ? AND meal_id = ? AND week_no < ? AND week_no >= ?
          ORDER BY week_no DESC',
        [$centerId, $mealId, $week, $week - $weeks]
    );
}

/** Weekly orders series for charts (oldest first). */
function order_series(int $centerId, int $mealId, int $upToWeek, int $weeks = 12): array
{
    $rows = db_all(
        'SELECT week_no, orders_sold FROM weekly_records
          WHERE center_id = ? AND meal_id = ? AND week_no < ? AND week_no >= ?
          ORDER BY week_no',
        [$centerId, $mealId, $upToWeek, $upToWeek - $weeks]
    );
    return $rows;
}

/**
 * Build the "WHERE ..." part for the records list from the filters in $f.
 * Returns [sql, params].
 */
function record_filter_sql(array $f): array
{
    $where = [];
    $params = [];
    if (!empty($f['center'])) { $where[] = 'w.center_id = ?'; $params[] = $f['center']; }
    if (!empty($f['meal']))   { $where[] = 'w.meal_id = ?';   $params[] = $f['meal']; }
    if (!empty($f['from']))   { $where[] = 'w.week_no >= ?';  $params[] = $f['from']; }
    if (!empty($f['to']))     { $where[] = 'w.week_no <= ?';  $params[] = $f['to']; }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function count_records(array $f): int
{
    [$where, $params] = record_filter_sql($f);
    return (int)db_value("SELECT COUNT(*) FROM weekly_records w $where", $params);
}

function list_records(array $f, int $limit, int $offset): array
{
    [$where, $params] = record_filter_sql($f);
    // LIMIT/OFFSET are written directly into the SQL text here rather than passed
    // as bound parameters (MySQL's prepared statements don't support parameters
    // in LIMIT/OFFSET on older versions). This is still safe from SQL injection:
    // $limit and $offset are function parameters already typed as int by PHP, and
    // max()/min() below force them to a sane whole-number range before use, so
    // nothing from a URL or form can reach the SQL text unescaped. The actual
    // user-supplied VALUES (center, meal, week range, in $where/$params) all go
    // through the normal "?" placeholders.
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    return db_all(
        "SELECT w.*, m.category, m.cuisine
           FROM weekly_records w
           JOIN meals m ON m.meal_id = w.meal_id
           $where
          ORDER BY w.week_no DESC, w.center_id, w.meal_id
          LIMIT $limit OFFSET $offset",
        $params
    );
}

// ---- dashboard numbers ------------------------------------------------------------
function dashboard_stats(?int $centerId): array
{
    $cw = $centerId ? 'AND center_id = ?' : '';
    $cp = $centerId ? [$centerId] : [];
    $latest = (int)(db_value("SELECT MAX(week_no) FROM weekly_records WHERE 1=1 $cw", $cp) ?? 0);

    $recent = db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(orders_sold),0) AS orders,
                COALESCE(SUM(prepared_qty),0) AS prepared, COALESCE(SUM(waste_qty),0) AS waste
           FROM weekly_records
          WHERE week_no > ? AND prepared_qty IS NOT NULL $cw",
        array_merge([$latest - 12], $cp)
    );
    $last4 = (int)db_value("SELECT COALESCE(SUM(orders_sold),0) FROM weekly_records WHERE week_no > ? $cw",
        array_merge([$latest - 4], $cp));
    $prev4 = (int)db_value("SELECT COALESCE(SUM(orders_sold),0) FROM weekly_records WHERE week_no > ? AND week_no <= ? $cw",
        array_merge([$latest - 8, $latest - 4], $cp));

    return [
        'latest_week'  => $latest,
        'records'      => (int)db_value("SELECT COUNT(*) FROM weekly_records WHERE 1=1 $cw", $cp),
        'orders_last4' => $last4,
        'orders_prev4' => $prev4,
        'waste_pct'    => ($recent && (int)$recent['prepared'] > 0) ? (int)$recent['waste'] / (int)$recent['prepared'] * 100 : null,
        'waste_total'  => $recent ? (int)$recent['waste'] : 0,
        'simulated'    => (int)db_value("SELECT COUNT(*) FROM weekly_records WHERE waste_source = 'simulated' $cw", $cp),
        'predictions'  => (int)db_value('SELECT COUNT(*) FROM predictions'),
    ];
}

function weekly_totals(?int $centerId, int $latest, int $weeks = 26): array
{
    $cw = $centerId ? 'AND center_id = ?' : '';
    $cp = $centerId ? [$centerId] : [];
    return db_all(
        "SELECT week_no, SUM(orders_sold) AS orders
           FROM weekly_records
          WHERE week_no > ? $cw
          GROUP BY week_no
          ORDER BY week_no",
        array_merge([$latest - $weeks], $cp)
    );
}

function waste_by_category(?int $centerId, int $latest, int $weeks = 12): array
{
    $cw = $centerId ? 'AND w.center_id = ?' : '';
    $cp = $centerId ? [$centerId] : [];
    return db_all(
        "SELECT m.category, SUM(w.waste_qty) AS waste, SUM(w.prepared_qty) AS prepared
           FROM weekly_records w
           JOIN meals m ON m.meal_id = w.meal_id
          WHERE w.week_no > ? AND w.prepared_qty IS NOT NULL $cw
          GROUP BY m.category
          ORDER BY SUM(w.waste_qty) DESC",
        array_merge([$latest - $weeks], $cp)
    );
}

function top_wasted_meals(?int $centerId, int $latest, int $weeks = 12, int $limit = 5): array
{
    $cw = $centerId ? 'AND w.center_id = ?' : '';
    $cp = $centerId ? [$centerId] : [];
    $limit = max(1, $limit);
    return db_all(
        "SELECT m.meal_id, m.category, m.cuisine, SUM(w.waste_qty) AS waste, SUM(w.prepared_qty) AS prepared
           FROM weekly_records w
           JOIN meals m ON m.meal_id = w.meal_id
          WHERE w.week_no > ? AND w.prepared_qty IS NOT NULL $cw
          GROUP BY m.meal_id, m.category, m.cuisine
          ORDER BY SUM(w.waste_qty) DESC
          LIMIT $limit",
        array_merge([$latest - $weeks], $cp)
    );
}

// ---- predictions -----------------------------------------------------------------------
function save_prediction(array $row): int
{
    db_run(
        'INSERT INTO predictions (week_no, center_id, meal_id, checkout_price, base_price,
                                  emailer_for_promotion, homepage_featured, predicted_demand,
                                  service_level, recommended_prep, model_name, history_weeks_used,
                                  created_by, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$row['week_no'], $row['center_id'], $row['meal_id'], $row['checkout_price'], $row['base_price'],
         $row['emailer_for_promotion'], $row['homepage_featured'], $row['predicted_demand'],
         $row['service_level'], $row['recommended_prep'], $row['model_name'], $row['history_weeks_used'],
         $row['created_by'], date('Y-m-d H:i:s')]
    );
    return (int)db()->lastInsertId();
}

function list_predictions(int $limit, int $offset): array
{
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    return db_all(
        "SELECT p.*, m.category, m.cuisine, u.username, w.orders_sold AS actual
           FROM predictions p
           JOIN meals m ON m.meal_id = p.meal_id
           LEFT JOIN users u ON u.user_id = p.created_by
           LEFT JOIN weekly_records w
                  ON w.week_no = p.week_no AND w.center_id = p.center_id AND w.meal_id = p.meal_id
          ORDER BY p.prediction_id DESC
          LIMIT $limit OFFSET $offset"
    );
}

/**
 * How accurate have our saved predictions been, now that real orders are known?
 * This is a LIVE version of the same WAPE metric used throughout the thesis
 * (total error as a % of total demand), computed here from the site's own
 * prediction history joined back to the weekly_records once the real sales for
 * that week have been entered. "covered_pct" is how often the recommended
 * preparation quantity would have been enough (the "service level" idea).
 */
function prediction_accuracy(): array
{
    $row = db_one(
        'SELECT COUNT(*) AS n,
                SUM(ABS(p.predicted_demand - w.orders_sold)) AS abs_err,
                SUM(w.orders_sold) AS total_actual,
                SUM(CASE WHEN p.recommended_prep >= w.orders_sold THEN 1 ELSE 0 END) AS covered
           FROM predictions p
           JOIN weekly_records w
             ON w.week_no = p.week_no AND w.center_id = p.center_id AND w.meal_id = p.meal_id'
    );
    if (!$row || (int)$row['n'] === 0) {
        return ['n' => 0, 'wape' => null, 'covered_pct' => null];
    }
    return [
        'n'           => (int)$row['n'],
        'wape'        => (float)$row['abs_err'] / max(1, (float)$row['total_actual']) * 100,
        'covered_pct' => (int)$row['covered'] / (int)$row['n'] * 100,
    ];
}
