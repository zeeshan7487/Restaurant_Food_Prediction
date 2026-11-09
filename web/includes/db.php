<?php
/**
 * db.php - one shared database connection (PDO).
 *
 * PDO "prepared statements" are used for every query: the SQL text and the user's
 * values travel separately, so a value like  ' OR 1=1 --  can never change the
 * meaning of a query. This is the standard defence against SQL injection.
 */
declare(strict_types=1);

function db(): PDO
{
    // `static` keeps this variable's value between calls within the same page
    // request, so every db_all()/db_one()/db_run() call on a page reuses the
    // same connection instead of opening a new one each time.
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = $GLOBALS['CONFIG']['db'];

    if ($c['driver'] === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['sqlite_path']);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['user'], $c['pass']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);        // a bad query throws instead of failing silently
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);   // rows come back as ['column' => value, ...]
    // false = use MySQL's OWN prepared statements, not PHP's emulated ones. The
    // query and the values are then sent to MySQL as two separate messages, so
    // there is no way for a value to be reinterpreted as part of the SQL -- the
    // strongest form of the injection defence mentioned above.
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $pdo;
}

/** Run a query with values and return all rows. */
function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Run a query and return the first row, or null. */
function db_one(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Run a query and return the first column of the first row (e.g. a COUNT). */
function db_value(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/** Run INSERT/UPDATE/DELETE and return the number of rows changed. */
function db_run(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}
