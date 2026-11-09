"""
test_web.py - automated functional and security tests for the web application.

Usage (site and prediction service must both be running):
    pip install requests
    python tests/test_web.py http://localhost/restaurant-ai/web/
The admin login can be given with environment variables ADMIN_USER / ADMIN_PASS
(default: admin / Admin@12345).

Every test prints PASS or FAIL. A summary is written to tests/test_results.md,
which is used as evidence in the Testing chapter of the thesis.
The tests create one weekly record and two temporary users, and delete them again.
They also save two predictions (the prediction history is an audit log, so those stay).
"""

import html
import os
import re
import sys
from datetime import datetime
from pathlib import Path

import requests

BASE = (sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080/").rstrip("/") + "/"
ADMIN_USER = os.environ.get("ADMIN_USER", "admin")
ADMIN_PASS = os.environ.get("ADMIN_PASS", "Admin@12345")
ML_URL = os.environ.get("ML_URL", "http://127.0.0.1:5000")
DB_LABEL = os.environ.get("TEST_DB_LABEL", "not stated (set TEST_DB_LABEL, e.g. \"MySQL 8 via XAMPP\")")

results = []


def check(name, condition, detail=""):
    results.append((name, bool(condition), detail))
    print(("PASS  " if condition else "FAIL  ") + name + (f"   [{detail}]" if detail and not condition else ""))


def new_session():
    s = requests.Session()
    s.headers["User-Agent"] = "fyp-tests"
    return s


def csrf(text):
    # Pulls the hidden CSRF token out of a page's HTML so the test can send it
    # straight back in the next POST, exactly like a real browser submitting
    # the form would (see csrf_field()/csrf_check() in web/includes/helpers.php).
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', text)
    return m.group(1) if m else ""


def login(username, password):
    s = new_session()
    page = s.get(BASE + "login.php")
    r = s.post(BASE + "login.php", data={"csrf": csrf(page.text), "username": username, "password": password},
               allow_redirects=False)
    return s, r


def get_page(s, path):
    r = s.get(BASE + path)
    return r, r.text


def main():
    # ---------------------------------------------------------------- access control
    anon = new_session()
    r = anon.get(BASE + "index.php", allow_redirects=False)
    check("T01 Dashboard redirects to login when not signed in", r.status_code == 302 and "login.php" in r.headers.get("Location", ""))
    for page in ["records.php", "predict.php", "predictions.php", "model.php", "users.php", "record_edit.php"]:
        r = anon.get(BASE + page, allow_redirects=False)
        check(f"T02 {page} requires login", r.status_code == 302 and "login.php" in r.headers.get("Location", ""))
    r = anon.get(BASE + "report_image.php?f=fig_model_comparison.png", allow_redirects=False)
    check("T03 report images require login", r.status_code == 302)

    # ---------------------------------------------------------------- login
    s, r = login(ADMIN_USER, "definitely-wrong-password")
    check("T04 Wrong password is rejected", r.status_code == 200 and "Wrong username or password" in r.text)
    # The classic SQL-injection payload: if login.php built its SQL by gluing
    # strings together, "admin' OR '1'='1" would make the WHERE clause always
    # true and log the attacker in as admin with no real password. Because
    # includes/db.php uses true prepared statements (PDO::ATTR_EMULATE_PREPARES
    # = false), the whole string is just an (invalid) username to compare
    # against, so this should fail exactly like any other wrong login.
    s, r = login("admin' OR '1'='1", "x")
    check("T05 SQL injection in the username does not log in", r.status_code == 200 and "Wrong username or password" in r.text)
    # Same error message for "wrong password" and "no such user" -- this is the
    # timing-attack countermeasure in attempt_login() (includes/auth.php): an
    # attacker can't use the response to tell which usernames actually exist.
    s, r = login("no_such_user_xyz", "whatever123")
    check("T06 Unknown user gets the same message as a wrong password", "Wrong username or password" in r.text)
    s, r = login(ADMIN_USER, ADMIN_PASS)
    check("T07 Correct login redirects to the dashboard", r.status_code == 302 and "index.php" in r.headers.get("Location", ""),
          f"status {r.status_code}")
    admin = s

    r, page = get_page(admin, "index.php")
    check("T08 Dashboard loads with KPIs and charts", r.status_code == 200 and "Weekly records" in page and 'data-chart=' in page)
    # HttpOnly means JavaScript cannot read the session cookie -- so even if an
    # XSS bug slipped through somewhere, a malicious script couldn't steal the
    # logged-in session by reading document.cookie.
    check("T09 Session cookie is HttpOnly", any("httponly" in str(c).lower() or c.has_nonstandard_attr("HttpOnly")
                                                 for c in admin.cookies if c.name == "fdwp_session"))

    # ---------------------------------------------------------------- pick a real meal/kitchen
    r, page = get_page(admin, "records.php")
    m = re.search(r"record_edit\.php\?center=(\d+)&amp;meal=(\d+)", page)
    check("T10 Records list shows data", r.status_code == 200 and m is not None)
    if not m:
        return
    center, meal = m.group(1), m.group(2)

    # ---------------------------------------------------------------- add / validate / duplicate
    r, form = get_page(admin, f"record_edit.php?center={center}&meal={meal}")
    week = int(re.search(r'name="week_no"[^>]*value="(\d+)"', form).group(1))
    price = re.search(r'name="checkout_price"[^>]*value="([\d.]+)"', form).group(1)
    base = re.search(r'name="base_price"[^>]*value="([\d.]+)"', form).group(1)
    token = csrf(form)
    good = {"csrf": token, "week_no": week, "center_id": center, "meal_id": meal, "checkout_price": price,
            "base_price": base, "orders_sold": 240, "prepared_qty": 280, "waste_qty": ""}

    bad = dict(good, orders_sold="-5")
    r = admin.post(BASE + f"record_edit.php?center={center}&meal={meal}", data=bad)
    check("T11 Negative orders are rejected", "Please fix the highlighted fields" in r.text)
    bad = dict(good, prepared_qty="100", waste_qty="150")
    r = admin.post(BASE + f"record_edit.php?center={center}&meal={meal}", data=bad)
    check("T12 Waste larger than prepared is rejected", "Waste cannot be more than what was prepared" in r.text)
    bad = dict(good, checkout_price="abc")
    r = admin.post(BASE + f"record_edit.php?center={center}&meal={meal}", data=bad)
    check("T13 Non-numeric price is rejected", "Please fix the highlighted fields" in r.text)
    bad = dict(good, csrf="0" * 64)
    r = admin.post(BASE + f"record_edit.php?center={center}&meal={meal}", data=bad)
    check("T14 Form without a valid CSRF token is refused (HTTP 400)", r.status_code == 400)

    r = admin.post(BASE + f"record_edit.php?center={center}&meal={meal}", data=good, allow_redirects=True)
    check("T15 Valid record is saved", "Record saved for week" in r.text, r.text[:200])
    r, page = get_page(admin, f"records.php?center={center}&meal={meal}&from={week}&to={week}")
    check("T16 New record appears in the list; waste auto-calculated as prepared - sold (= 40)",
          re.search(r">\s*240\s*<", page) and re.search(r">\s*40\s*<", page) and "recorded" in page)
    r, form = get_page(admin, f"record_edit.php?center={center}&meal={meal}")
    r2 = admin.post(BASE + f"record_edit.php?center={center}&meal={meal}", data=dict(good, csrf=csrf(form), week_no=week))
    check("T17 Duplicate week for the same meal/kitchen is refused", "already exists" in r2.text)

    # ---------------------------------------------------------------- prediction
    r, page = get_page(admin, f"predict.php?center={center}&meal={meal}")
    check("T18 Prediction form loads with pre-filled values", r.status_code == 200 and 'name="week"' in page)
    pred_week = int(re.search(r'name="week"[^>]*value="(\d+)"', page).group(1))
    pform = {"csrf": csrf(page), "center": center, "meal": meal, "week": pred_week,
             "checkout_price": price, "base_price": base, "service_level": "0.8"}
    r = admin.post(BASE + "predict.php", data=pform)
    check("T19 Prediction is produced and shown", "Predicted demand" in r.text and "Recommended to prepare" in r.text, re.sub(r"\s+", " ", html.unescape(re.sub("<[^>]+>", " ", r.text)))[:300])
    predicted = re.search(r'Predicted demand</div><div class="big">([\d,]+)', r.text)
    check("T20 Predicted value is a sensible positive number", predicted and 0 < int(predicted.group(1).replace(",", "")) < 1_000_000)

    r = admin.post(BASE + "predict.php", data=dict(pform, week=5000))
    check("T21 Prediction for a week with no history gives a clear error",
          "No sales were recorded" in r.text)
    r = admin.post(BASE + "predict.php", data=dict(pform, checkout_price="-3"))
    check("T22 Prediction with an invalid price is rejected", "Enter the planned selling price" in r.text)
    r = admin.post(BASE + "predict.php", data=dict(pform, csrf="bad"))
    check("T23 Prediction form without valid CSRF token is refused", r.status_code == 400)

    # prediction for the week that now has an actual value -> history page shows the error
    r = admin.post(BASE + "predict.php", data=dict(pform, week=week))
    check("T24 Predicting a week with known actual orders shows the actual value", "Actual orders for this week are already recorded" in r.text)
    r, page = get_page(admin, "predictions.php")
    check("T25 Prediction history lists predictions with error against actuals", r.status_code == 200 and "Forecast error (WAPE)" in page and "%" in page)

    # ---------------------------------------------------------------- other pages
    r, page = get_page(admin, "model.php")
    check("T26 Model & results page loads", r.status_code == 200 and "Model comparison" in page)
    r = admin.get(BASE + "report_image.php?f=fig_model_comparison.png")
    check("T27 Report image is served as PNG", r.status_code == 200 and r.headers.get("Content-Type") == "image/png")
    r = admin.get(BASE + "report_image.php?f=../config.php")
    check("T28 Path traversal on the image script is blocked", r.status_code == 404)
    r = admin.get(BASE + f"records.php?export=1&center={center}&meal={meal}")
    check("T29 CSV export works", r.status_code == 200 and "text/csv" in r.headers.get("Content-Type", "") and r.text.startswith("week_no,"))
    r = admin.get(BASE + "records.php?center=1%20OR%201=1&page=-5")
    check("T30 Odd query-string values do not break the list", r.status_code == 200)

    # ---------------------------------------------------------------- health of the ML service
    try:
        h = requests.get(ML_URL + "/health", timeout=5).json()
        check("T31 Prediction service /health reports the model", h.get("ok") and "model_name" in h)
        bad = requests.post(ML_URL + "/predict", json={"week": "x"}, timeout=5)
        check("T32 Prediction service rejects malformed input with 4xx (not a crash)", 400 <= bad.status_code < 500)
    except requests.RequestException as ex:
        check("T31 Prediction service reachable", False, str(ex))

    # ---------------------------------------------------------------- users, roles, XSS, lock-out
    r, page = get_page(admin, "users.php")
    r = admin.post(BASE + "users.php", data={"csrf": csrf(page), "action": "add", "username": "tmp_mgr",
                                             "full_name": "<script>alert(1)</script>", "role": "manager", "password": "weak"})
    check("T33 Weak password is rejected when creating a user", "at least 8 characters" in r.text or "letter and a number" in r.text)
    r, page = get_page(admin, "users.php")
    r = admin.post(BASE + "users.php", data={"csrf": csrf(page), "action": "add", "username": "tmp_mgr",
                                             "full_name": "<script>alert(1)</script>", "role": "manager", "password": "Manager123"},
                   allow_redirects=True)
    check("T34 Admin can create a manager", "User created" in r.text)
    # The full name was deliberately set to a <script> tag above. If the page
    # printed it back raw, that script would run in the browser of anyone who
    # later views the users list -- a stored XSS attack. e() (helpers.php)
    # escapes it to &lt;script&gt;... instead, so the browser shows it as text.
    check("T35 Script tags in a name are HTML-escaped (XSS blocked)",
          "&lt;script&gt;alert(1)&lt;/script&gt;" in r.text and "<script>alert(1)" not in r.text)

    mgr, r = login("tmp_mgr", "Manager123")
    check("T36 New manager can log in", r.status_code == 302)
    r = mgr.get(BASE + "users.php")
    check("T37 Manager cannot open the Users page (403)", r.status_code == 403)
    r, page = get_page(mgr, "records.php")
    check("T38 Manager sees no Delete buttons", "Delete" not in page)
    rid = None
    r, page = get_page(admin, f"records.php?center={center}&meal={meal}&from={week}&to={week}")
    mm = re.search(r'name="delete_id" value="(\d+)"', page)
    if mm:
        rid = mm.group(1)
    r = mgr.post(BASE + "records.php", data={"csrf": csrf(get_page(mgr, "records.php")[1]), "delete_id": rid or "1"})
    check("T39 Manager cannot delete records even by sending the request directly (403)", r.status_code == 403)

    # 5 wrong attempts trips the lockout in attempt_login() (includes/auth.php);
    # the 6th attempt below uses the CORRECT password but should still be
    # refused, proving the account is actually locked rather than just the
    # wrong password being rejected again.
    for _ in range(5):
        login("tmp_mgr", "wrong-password-1")
    s, r = login("tmp_mgr", "Manager123")
    check("T40 Account locks after 5 wrong passwords (right password refused)", "Too many failed attempts" in r.text)

    # ---------------------------------------------------------------- clean up
    if rid:
        r, page = get_page(admin, f"records.php?center={center}&meal={meal}&from={week}&to={week}")
        r = admin.post(BASE + "records.php", data={"csrf": csrf(page), "delete_id": rid}, allow_redirects=True)
        check("T41 Admin can delete a record", "Record deleted" in r.text)
    r, page = get_page(admin, "users.php")
    uid = re.search(r'name="user_id" value="(\d+)"', page)
    if uid:
        admin.post(BASE + "users.php", data={"csrf": csrf(page), "action": "delete", "user_id": uid.group(1)})
    r, page = get_page(admin, "users.php")
    check("T42 Temporary user removed", "tmp_mgr" not in page)

    r = admin.post(BASE + "logout.php", data={"csrf": csrf(get_page(admin, "index.php")[1])}, allow_redirects=False)
    r = admin.get(BASE + "index.php", allow_redirects=False)
    check("T43 After logout the dashboard is protected again", r.status_code == 302)


if __name__ == "__main__":
    main()
    passed = sum(1 for _, ok, _ in results if ok)
    print(f"\n{passed}/{len(results)} tests passed")
    out = Path(__file__).with_name("test_results.md")
    lines = [f"# Automated test results", "", f"Run on {datetime.now():%Y-%m-%d %H:%M}, target {BASE}", "", f"Database behind the site: {DB_LABEL}", "",
             f"**{passed} of {len(results)} tests passed.**", "", "| # | Test | Result |", "|---|------|--------|"]
    for i, (name, ok, detail) in enumerate(results, 1):
        lines.append(f"| {i} | {name} | {'PASS' if ok else 'FAIL'} |")
    out.write_text("\n".join(lines) + "\n")
    sys.exit(0 if passed == len(results) else 1)
