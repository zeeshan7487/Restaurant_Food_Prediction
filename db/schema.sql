-- ============================================================================
-- schema.sql : MySQL database for the Restaurant Food Demand & Waste system
-- Import with phpMyAdmin (Import tab) or:  mysql -u root < db/schema.sql
-- Works on MySQL 5.7+/8.x and MariaDB 10.3+ (XAMPP).
-- ============================================================================

CREATE DATABASE IF NOT EXISTS restaurant_ai
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE restaurant_ai;

-- Drop in child-first order so the script can be re-run safely while developing.
DROP TABLE IF EXISTS predictions;
DROP TABLE IF EXISTS weekly_records;
DROP TABLE IF EXISTS meals;
DROP TABLE IF EXISTS centers;
DROP TABLE IF EXISTS users;

-- ---------------------------------------------------------------------------
-- users : people who can log in
--
-- All tables use ENGINE=InnoDB rather than MySQL's older MyISAM engine,
-- because InnoDB supports foreign keys (so a record can't point at a
-- center/meal/user that doesn't exist) and transactions (so a multi-step
-- change, e.g. the bulk CSV import, either fully succeeds or fully fails,
-- never leaves the database half-written).
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    user_id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username       VARCHAR(50)  NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,          -- bcrypt hash, never the password itself
    full_name      VARCHAR(100) NOT NULL,
    role           ENUM('admin','manager') NOT NULL DEFAULT 'manager', -- manager = normal user; admin can also manage accounts
    failed_logins  TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- reset to 0 on a successful login
    locked_until   DATETIME NULL,                  -- temporary lock after too many wrong passwords; NULL = not locked
    created_at     DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username)         -- no two accounts can share a username
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- centers : a kitchen / outlet (called "fulfilment center" in the public dataset)
-- ---------------------------------------------------------------------------
CREATE TABLE centers (
    center_id    INT NOT NULL,
    city_code    VARCHAR(10) NOT NULL,
    region_code  VARCHAR(10) NOT NULL,
    center_type  VARCHAR(10) NOT NULL,
    op_area      DECIMAL(4,1) NOT NULL,            -- operating area (size of the kitchen)
    PRIMARY KEY (center_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- meals : a menu item
-- ---------------------------------------------------------------------------
CREATE TABLE meals (
    meal_id   INT NOT NULL,
    category  VARCHAR(40) NOT NULL,
    cuisine   VARCHAR(40) NOT NULL,
    PRIMARY KEY (meal_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- weekly_records : one row = one meal, at one center, in one week
-- This is the main table; the model learns from it.
-- ---------------------------------------------------------------------------
CREATE TABLE weekly_records (
    record_id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    week_no                SMALLINT UNSIGNED NOT NULL,
    center_id              INT NOT NULL,
    meal_id                INT NOT NULL,
    checkout_price         DECIMAL(8,2) NOT NULL,
    base_price             DECIMAL(8,2) NOT NULL,
    emailer_for_promotion  TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = promoted by e-mail that week
    homepage_featured      TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = featured on the homepage
    orders_sold            INT UNSIGNED NOT NULL,           -- actual demand (portions)
    prepared_qty           INT UNSIGNED NULL,               -- portions cooked/prepared
    waste_qty              INT UNSIGNED NULL,               -- portions thrown away
    waste_source           ENUM('recorded','simulated') NULL, -- see note below
    created_by             INT UNSIGNED NULL,
    created_at             DATETIME NOT NULL,
    PRIMARY KEY (record_id),
    UNIQUE KEY uq_record (week_no, center_id, meal_id),     -- no duplicate week for the same meal/center
    -- This index exists specifically so "give me this meal's last 4 weeks at this
    -- center" (what the prediction screen needs every time) is a fast index lookup
    -- instead of a full table scan over 450,000+ rows.
    KEY idx_record_pair (center_id, meal_id, week_no),
    CONSTRAINT fk_rec_center FOREIGN KEY (center_id) REFERENCES centers (center_id),
    CONSTRAINT fk_rec_meal   FOREIGN KEY (meal_id)   REFERENCES meals (meal_id),
    -- If the user who entered a record is later deleted, keep the record but clear
    -- who entered it, rather than deleting their historical data.
    CONSTRAINT fk_rec_user   FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
-- NOTE on waste_source: the public dataset contains orders only. For those rows
-- prepared_qty/waste_qty are SIMULATED (see thesis, Methodology) and tagged
-- 'simulated'. Rows typed in through the website are tagged 'recorded'.

-- ---------------------------------------------------------------------------
-- predictions : every forecast the system produced (an audit trail)
-- ---------------------------------------------------------------------------
CREATE TABLE predictions (
    prediction_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    week_no            SMALLINT UNSIGNED NOT NULL,
    center_id          INT NOT NULL,
    meal_id            INT NOT NULL,
    checkout_price     DECIMAL(8,2) NOT NULL,
    base_price         DECIMAL(8,2) NOT NULL,
    emailer_for_promotion TINYINT(1) NOT NULL DEFAULT 0,
    homepage_featured  TINYINT(1) NOT NULL DEFAULT 0,
    predicted_demand   DECIMAL(10,1) NOT NULL,          -- the raw model output, before the safety factor is applied
    service_level      DECIMAL(3,2) NOT NULL,           -- e.g. 0.80 = cover demand ~80 % of the time
    recommended_prep   INT UNSIGNED NOT NULL,           -- predicted_demand x safety factor, rounded up: what to actually cook
    model_name         VARCHAR(60) NOT NULL,            -- which model produced this (kept even if a different model is chosen later)
    history_weeks_used TINYINT UNSIGNED NOT NULL,       -- how many of the last 4 weeks were actually available
    created_by         INT UNSIGNED NULL,
    created_at         DATETIME NOT NULL,
    PRIMARY KEY (prediction_id),
    KEY idx_pred_pair (center_id, meal_id, week_no),
    CONSTRAINT fk_pred_center FOREIGN KEY (center_id) REFERENCES centers (center_id),
    CONSTRAINT fk_pred_meal   FOREIGN KEY (meal_id)   REFERENCES meals (meal_id),
    CONSTRAINT fk_pred_user   FOREIGN KEY (created_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- A first administrator so you can log in. CHANGE THIS PASSWORD after the first login.
--   username: admin      password: Admin@12345
-- ---------------------------------------------------------------------------
INSERT INTO users (username, password_hash, full_name, role, created_at)
VALUES ('admin', '$2y$12$daZPQOOE4BZfpe5fPAwXm.sLILQ0l.NBxHDHnrYjUKZ8KmBZWkNom',
        'System Administrator', 'admin', NOW());
