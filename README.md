# AI-Based Restaurant Food Demand and Waste Prediction System

Final-year project – Zeeshan Zafar (P2863794), BSc Computer Science, De Montfort University.
Supervisor: Syed Muhammad Ali.

The full write-up is in the thesis; this repository is the system it describes. The thesis's Appendices
(set-up guide, full test tables, source-code excerpts, database schema, usability materials and
supplementary tables) are in `Appendices_Zeeshan_Zafar.pdf` at the root of this repository.

The system has three parts that talk to each other:

| Part | Folder | What it does |
|------|--------|--------------|
| Database (MySQL) | `db/` | Stores users, kitchens, meals, weekly records and predictions |
| Machine learning (Python) | `ml/` | Trains the demand model offline and serves predictions through a small local service |
| Web application (PHP) | `web/` | Login, data entry, prediction screen, history and results pages |

Other folders: `data/` (the real Kaggle data set plus the processed files built from it), `tests/`
(automated tests and their result logs), `docs/diagrams/` (architecture, ER, use-case and data-flow
diagrams, as both editable Mermaid source and rendered PNGs).

The real *Food Demand Forecasting* data set (Analytics Vidhya / Genpact, via Kaggle) is already in
`data/raw`, the model is already trained, and the automated tests have already been run against a real
MySQL database — see `tests/test_results.md` and `tests/test_results_ml.md` for the logs (48 of 48 web
checks and 13 of 13 machine-learning checks passed). The steps below reproduce that from scratch if you
want to run it yourself.

---

## 1. Install (Windows + XAMPP)

1. Install **XAMPP** (Apache, MySQL, PHP 7.4 or later) and **Python 3.10 or later** (tick *Add Python to PATH*).
2. Copy this whole folder to `C:\xampp\htdocs\restaurant-ai`.
3. Start **Apache** and **MySQL** in the XAMPP Control Panel.
4. Open `http://localhost/phpmyadmin` -> *Import* -> choose `db/schema.sql` -> *Go*.
   If MySQL has a root password, put it in `web/config.php` (`'pass'`).
5. Make sure cURL is on: in XAMPP open `php.ini` and check that the line `extension=curl` has no `;` in front. Restart Apache if you changed it.
6. Open a terminal in the project folder:
   ```
   python -m venv .venv
   .venv\Scripts\activate
   pip install -r ml/requirements.txt
   ```

## 2. Train the model (optional — a trained model is already included)

`ml/models/demand_model.joblib` is already trained on the real data. To retrain it yourself:

```
python ml/train.py
python ml/evaluate_policy.py
python ml/error_analysis.py
python ml/prepare_import.py
```

Train on the same computer that will run the site — **do not copy `demand_model.joblib` between
computers** with different library versions; it will refuse to load.

Then load the data into MySQL:
```
C:\xampp\php\php.exe web\tools\import_data.php
```

## 3. Run

1. Double-click `ml/start_service.bat` and keep its window open (the prediction service listens on `127.0.0.1:5000` only).
2. Open `http://localhost/restaurant-ai/web/`.
3. Sign in as **`admin`** with the initial password written in `db/schema.sql`, then **change it at once** on the *Account* page.
   Extra users: *Users* page (administrator) or `php web\tools\create_user.php`.

## 4. Tests

```
set TEST_DB_LABEL=MySQL 8 via XAMPP
set ADMIN_PASS=your-new-admin-password
python tests/test_web.py http://localhost/restaurant-ai/web/
python tests/test_ml.py
```

Running these again writes a fresh `tests/test_results*.md`. The logs already in this repository are
from the last confirmed run, against a real MySQL database.

## 5. Settings you may want to change

* `web/config.php`: `timezone` is set to `Asia/Karachi` (an assumption – change it to yours), database password, `ml.url`, `ml.api_key`.
* Environment variables `ML_PORT` (service port) and `ML_URL` (address the PHP side uses) if port 5000 is busy.

## 6. Security notes (short)

Passwords are stored with `password_hash` (bcrypt); forms use CSRF tokens; database access uses prepared statements; sessions are regenerated at login; five failed sign-ins lock the account for five minutes. Not done (listed as future work): HTTPS, Argon2id, security-event logging. Do not expose this to the internet as it is.
