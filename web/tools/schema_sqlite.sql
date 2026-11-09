-- schema_sqlite.sql : TEST-ONLY copy of schema.sql for SQLite.
-- Used by tests/run_tests.php so the PHP code can be exercised without a MySQL server.
-- The real project uses db/schema.sql (MySQL). Column names and constraints are identical.
PRAGMA foreign_keys = ON;

CREATE TABLE users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    full_name TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'manager' CHECK (role IN ('admin','manager')),
    failed_logins INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE centers (
    center_id INTEGER PRIMARY KEY,
    city_code TEXT NOT NULL, region_code TEXT NOT NULL, center_type TEXT NOT NULL,
    op_area REAL NOT NULL
);
CREATE TABLE meals (
    meal_id INTEGER PRIMARY KEY,
    category TEXT NOT NULL, cuisine TEXT NOT NULL
);
CREATE TABLE weekly_records (
    record_id INTEGER PRIMARY KEY AUTOINCREMENT,
    week_no INTEGER NOT NULL,
    center_id INTEGER NOT NULL REFERENCES centers(center_id),
    meal_id INTEGER NOT NULL REFERENCES meals(meal_id),
    checkout_price REAL NOT NULL, base_price REAL NOT NULL,
    emailer_for_promotion INTEGER NOT NULL DEFAULT 0,
    homepage_featured INTEGER NOT NULL DEFAULT 0,
    orders_sold INTEGER NOT NULL,
    prepared_qty INTEGER NULL, waste_qty INTEGER NULL,
    waste_source TEXT NULL CHECK (waste_source IN ('recorded','simulated')),
    created_by INTEGER NULL REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    UNIQUE (week_no, center_id, meal_id)
);
CREATE INDEX idx_record_pair ON weekly_records (center_id, meal_id, week_no);
CREATE TABLE predictions (
    prediction_id INTEGER PRIMARY KEY AUTOINCREMENT,
    week_no INTEGER NOT NULL,
    center_id INTEGER NOT NULL REFERENCES centers(center_id),
    meal_id INTEGER NOT NULL REFERENCES meals(meal_id),
    checkout_price REAL NOT NULL, base_price REAL NOT NULL,
    emailer_for_promotion INTEGER NOT NULL DEFAULT 0,
    homepage_featured INTEGER NOT NULL DEFAULT 0,
    predicted_demand REAL NOT NULL, service_level REAL NOT NULL,
    recommended_prep INTEGER NOT NULL, model_name TEXT NOT NULL,
    history_weeks_used INTEGER NOT NULL,
    created_by INTEGER NULL REFERENCES users(user_id) ON DELETE SET NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX idx_pred_pair ON predictions (center_id, meal_id, week_no);
INSERT INTO users (username, password_hash, full_name, role, created_at)
VALUES ('admin', '$2y$12$daZPQOOE4BZfpe5fPAwXm.sLILQ0l.NBxHDHnrYjUKZ8KmBZWkNom',
        'System Administrator', 'admin', '2026-01-01 00:00:00');
