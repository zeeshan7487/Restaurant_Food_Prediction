# Automated test results

Run on 2026-10-21 19:05, target http://localhost/restaurant-ai/web/

Database behind the site: MySQL (XAMPP)

**48 of 48 tests passed.**

| # | Test | Result |
|---|------|--------|
| 1 | T01 Dashboard redirects to login when not signed in | PASS |
| 2 | T02 records.php requires login | PASS |
| 3 | T02 predict.php requires login | PASS |
| 4 | T02 predictions.php requires login | PASS |
| 5 | T02 model.php requires login | PASS |
| 6 | T02 users.php requires login | PASS |
| 7 | T02 record_edit.php requires login | PASS |
| 8 | T03 report images require login | PASS |
| 9 | T04 Wrong password is rejected | PASS |
| 10 | T05 SQL injection in the username does not log in | PASS |
| 11 | T06 Unknown user gets the same message as a wrong password | PASS |
| 12 | T07 Correct login redirects to the dashboard | PASS |
| 13 | T08 Dashboard loads with KPIs and charts | PASS |
| 14 | T09 Session cookie is HttpOnly | PASS |
| 15 | T10 Records list shows data | PASS |
| 16 | T11 Negative orders are rejected | PASS |
| 17 | T12 Waste larger than prepared is rejected | PASS |
| 18 | T13 Non-numeric price is rejected | PASS |
| 19 | T14 Form without a valid CSRF token is refused (HTTP 400) | PASS |
| 20 | T15 Valid record is saved | PASS |
| 21 | T16 New record appears in the list; waste auto-calculated as prepared - sold (= 40) | PASS |
| 22 | T17 Duplicate week for the same meal/kitchen is refused | PASS |
| 23 | T18 Prediction form loads with pre-filled values | PASS |
| 24 | T19 Prediction is produced and shown | PASS |
| 25 | T20 Predicted value is a sensible positive number | PASS |
| 26 | T21 Prediction for a week with no history gives a clear error | PASS |
| 27 | T22 Prediction with an invalid price is rejected | PASS |
| 28 | T23 Prediction form without valid CSRF token is refused | PASS |
| 29 | T24 Predicting a week with known actual orders shows the actual value | PASS |
| 30 | T25 Prediction history lists predictions with error against actuals | PASS |
| 31 | T26 Model & results page loads | PASS |
| 32 | T27 Report image is served as PNG | PASS |
| 33 | T28 Path traversal on the image script is blocked | PASS |
| 34 | T29 CSV export works | PASS |
| 35 | T30 Odd query-string values do not break the list | PASS |
| 36 | T31 Prediction service /health reports the model | PASS |
| 37 | T32 Prediction service rejects malformed input with 4xx (not a crash) | PASS |
| 38 | T33 Weak password is rejected when creating a user | PASS |
| 39 | T34 Admin can create a manager | PASS |
| 40 | T35 Script tags in a name are HTML-escaped (XSS blocked) | PASS |
| 41 | T36 New manager can log in | PASS |
| 42 | T37 Manager cannot open the Users page (403) | PASS |
| 43 | T38 Manager sees no Delete buttons | PASS |
| 44 | T39 Manager cannot delete records even by sending the request directly (403) | PASS |
| 45 | T40 Account locks after 5 wrong passwords (right password refused) | PASS |
| 46 | T41 Admin can delete a record | PASS |
| 47 | T42 Temporary user removed | PASS |
| 48 | T43 After logout the dashboard is protected again | PASS |
