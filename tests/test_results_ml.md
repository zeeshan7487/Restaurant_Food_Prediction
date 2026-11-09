# Automated ML test results

Run on 2026-09-22 02:13.

**13 of 13 tests passed.**

| # | Test | Result |
|---|------|--------|
| 1 | M01 lag_1 of week 5 is week 4 (=40); lag_2 of week 5 is week 3 (missing -> NaN) | PASS |
| 2 | M02 week 4 has lag_1 missing (week 3 absent), not the wrong value 20 | PASS |
| 3 | M03 Features of a week do not change when that week's and later orders are changed (no leakage) | PASS |
| 4 | M04 Train, validation and test weeks do not overlap and are in time order | PASS |
| 5 | M05 Safety factors increase with the service level | PASS |
| 6 | M06 Safety factors are between 0.5 and 3 | PASS |
| 7 | M07 waste - shortage = prepared - demand (identity holds) | PASS |
| 8 | M08 waste = 50 (10 + 0 + 0 + 40) and shortage = 20 for the hand-made example | PASS |
| 9 | M09 Web service answered all 150 sampled requests | PASS |
| 10 | M10 Web-service prediction equals the offline model prediction (max difference < 0.1 portion) | PASS |
| 11 | M11 Service refuses a prediction with no sales history (HTTP 422) | PASS |
| 12 | M12 Service refuses a non-JSON body (HTTP 400) | PASS |
| 13 | M13 Acceptance: chosen model (Gradient boosting) has lower test WAPE than the best baseline | PASS |
