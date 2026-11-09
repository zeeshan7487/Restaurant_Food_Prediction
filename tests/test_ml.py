"""
test_ml.py - automated tests for the machine-learning part.

Run from the project folder (after train.py and evaluate_policy.py):
    python tests/test_ml.py

What is tested and why:
  * lag features are looked up by WEEK NUMBER (not row position)     -> correctness
  * features for a week never use information from that week or later -> no data leakage
  * the web service gives the same answer as the offline model        -> no training/serving skew
  * time split has no overlap; safety factors are sensible            -> validity
  * waste/shortage arithmetic is consistent                           -> correctness of the waste study
  * the chosen model beats the simple baselines on the test weeks     -> acceptance criterion
Results are written to tests/test_results_ml.md for the Testing chapter of the thesis.
"""

import json
import sys
import time
from datetime import datetime
from pathlib import Path

import numpy as np
import pandas as pd

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "ml"))

import config                                     # noqa: E402
from features import ALL_FEATURES, build_features, add_lag_features   # noqa: E402
import evaluate_policy                            # noqa: E402

results = []


def check(name, condition, detail=""):
    results.append((name, bool(condition), detail))
    print(("PASS  " if condition else "FAIL  ") + name + (f"   [{detail}]" if detail and not condition else ""))


def main():
    orders = pd.read_csv(config.RAW_DIR / "train.csv")
    meals = pd.read_csv(config.RAW_DIR / "meal_info.csv")
    centers = pd.read_csv(config.RAW_DIR / "fulfilment_center_info.csv")
    report = json.loads(config.METRICS_FILE.read_text())

    # ---- M01: lags come from the correct WEEK even when weeks are missing -------------
    tiny = pd.DataFrame({"week": [1, 2, 4, 5], "center_id": 1, "meal_id": 1, "num_orders": [10, 20, 40, 50]})
    out = add_lag_features(tiny).set_index("week")
    check("M01 lag_1 of week 5 is week 4 (=40); lag_2 of week 5 is week 3 (missing -> NaN)",
          out.loc[5, "lag_1"] == 40 and np.isnan(out.loc[5, "lag_2"]) and out.loc[5, "lag_3"] == 20)
    check("M02 week 4 has lag_1 missing (week 3 absent), not the wrong value 20",
          np.isnan(out.loc[4, "lag_1"]) and out.loc[4, "lag_2"] == 20)

    # ---- M03: no leakage -------------------------------------------------------------------
    # The idea: build the features for one specific (center, meal, week=130) row
    # normally, then deliberately corrupt the orders data for that SAME week and
    # every week after it (999999 is an impossible value, chosen so any leak
    # would be obvious), and build the features again. If build_features() is
    # correctly using only PAST weeks for its lag features, the row's features
    # must come out byte-for-byte identical both times, because nothing at or
    # after week 130 should have been allowed to influence them.
    df = build_features(orders, meals, centers)
    target = df[(df.week == 130)].iloc[0]
    changed = orders.copy()
    mask = (changed.center_id == target.center_id) & (changed.meal_id == target.meal_id) & (changed.week >= 130)
    changed.loc[mask, "num_orders"] = 999999          # destroy the target week AND the future
    df2 = build_features(changed, meals, centers)
    t2 = df2[(df2.week == 130) & (df2.center_id == target.center_id) & (df2.meal_id == target.meal_id)].iloc[0]
    same = all((pd.isna(target[c]) and pd.isna(t2[c])) or target[c] == t2[c] for c in ALL_FEATURES)
    check("M03 Features of a week do not change when that week's and later orders are changed (no leakage)", same)

    # ---- M04: time split -------------------------------------------------------------------
    sp = report["split"]
    check("M04 Train, validation and test weeks do not overlap and are in time order",
          sp["train_weeks"][1] < sp["validation_weeks"][0] and sp["validation_weeks"][1] < sp["test_weeks"][0])

    # ---- M05-M06: safety factors --------------------------------------------------------------
    sf = report["safety_factors"]
    vals = [sf[k] for k in sorted(sf, key=float)]
    check("M05 Safety factors increase with the service level", all(a < b for a, b in zip(vals, vals[1:])), str(sf))
    check("M06 Safety factors are between 0.5 and 3", all(0.5 < v < 3 for v in vals))

    # ---- M07: waste arithmetic -----------------------------------------------------------------
    # 4 hand-picked weeks covering every case: over-prepared (100 vs 90, waste
    # 10), under-prepared (80 vs 100, shortage 20), exactly right (120 vs 120,
    # neither), and badly over-prepared (50 vs 10, waste 40). Because total
    # prepared - total demand always equals total waste - total shortage (an
    # accounting identity -- the two differences must balance), checking that
    # equality is a quick way to catch a sign error or off-by-one in evaluate().
    prep = np.array([100, 80, 120, 50], dtype=float)
    actual = np.array([90, 100, 120, 10], dtype=float)
    ev = evaluate_policy.evaluate(prep, actual)
    check("M07 waste - shortage = prepared - demand (identity holds)",
          ev["waste_total"] - ev["shortage_total"] == ev["prepared_total"] - ev["demand_total"])
    check("M08 waste = 50 (10 + 0 + 0 + 40) and shortage = 20 for the hand-made example",
          ev["waste_total"] == 50 and ev["shortage_total"] == 20)

    # ---- M09-M10: web service equals offline model ----------------------------------------------
    # This is the "no training/serving skew" check: 150 random rows get predicted
    # two different ways -- once by calling the trained model object directly
    # (offline, "service.MODEL.predict(...)"), and once by sending the same
    # rows as JSON through the actual Flask /predict endpoint, exactly as
    # web/includes/ml_client.php would. Both paths end up calling the identical
    # build_features() function (that is the point of ml/service.py's design),
    # so the two predictions should match almost exactly; the tiny allowed
    # difference (<0.1) is just floating-point/JSON rounding, not a real
    # discrepancy.
    import service                                  # loads the trained model file
    client = service.app.test_client()
    last_weeks = orders.week.max()
    sample = df[(df.week >= last_weeks - 9)].dropna(subset=["lag_1"]).sample(150, random_state=3)
    offline = service.MODEL.predict(sample[ALL_FEATURES])
    diffs, failures, latencies = [], 0, []
    by_key = {k: g.set_index("week")["num_orders"] for k, g in orders.groupby(["center_id", "meal_id"])}
    for (_, row), off in zip(sample.iterrows(), offline):
        c = centers[centers.center_id == row.center_id].iloc[0]
        m = meals[meals.meal_id == row.meal_id].iloc[0]
        series = by_key[(row.center_id, row.meal_id)]
        hist = [{"week": int(w), "num_orders": int(series[w])} for w in range(int(row.week) - 4, int(row.week)) if w in series.index]
        payload = {"week": int(row.week), "checkout_price": float(row.checkout_price), "base_price": float(row.base_price),
                   "emailer_for_promotion": int(row.emailer_for_promotion), "homepage_featured": int(row.homepage_featured),
                   "center": {"center_id": int(c.center_id), "city_code": str(c.city_code), "region_code": str(c.region_code),
                              "center_type": str(c.center_type), "op_area": float(c.op_area)},
                   "meal": {"meal_id": int(m.meal_id), "category": str(m.category), "cuisine": str(m.cuisine)},
                   "history": hist}
        t0 = time.perf_counter()
        r = client.post("/predict", json=payload)
        latencies.append((time.perf_counter() - t0) * 1000)
        if r.status_code != 200:
            failures += 1
            continue
        diffs.append(abs(r.get_json()["predicted_demand"] - max(off, 0)))
    check("M09 Web service answered all 150 sampled requests", failures == 0, f"{failures} failures")
    check("M10 Web-service prediction equals the offline model prediction (max difference < 0.1 portion)",
          diffs and max(diffs) < 0.1, f"max diff {max(diffs) if diffs else 'n/a'}")

    if latencies:
        (ROOT / "tests" / "ml_latency.json").write_text(json.dumps({"requests": len(latencies), "median_ms": round(float(np.median(latencies)), 1), "p95_ms": round(float(np.percentile(latencies, 95)), 1), "max_ms": round(float(max(latencies)), 1)}))

    r = client.post("/predict", json={"week": 10, "checkout_price": 100, "base_price": 100, "emailer_for_promotion": 0,
                                      "homepage_featured": 0,
                                      "center": {"center_id": 1, "city_code": "1", "region_code": "1", "center_type": "TYPE_A", "op_area": 3.0},
                                      "meal": {"meal_id": 1, "category": "Pizza", "cuisine": "Italian"}, "history": []})
    check("M11 Service refuses a prediction with no sales history (HTTP 422)", r.status_code == 422)
    r = client.post("/predict", data="not json", content_type="text/plain")
    check("M12 Service refuses a non-JSON body (HTTP 400)", r.status_code == 400)

    # ---- M13: acceptance criterion ---------------------------------------------------------------------
    comp = report["comparison"]
    best = report["best_model"]
    base_wape = min(v["test"]["WAPE_%"] for k, v in comp.items() if k.startswith("Baseline"))
    check(f"M13 Acceptance: chosen model ({best}) has lower test WAPE than the best baseline",
          comp[best]["test"]["WAPE_%"] < base_wape, f"{comp[best]['test']['WAPE_%']} vs {base_wape}")


if __name__ == "__main__":
    main()
    passed = sum(1 for _, ok, _ in results if ok)
    print(f"\n{passed}/{len(results)} tests passed")
    lines = ["# Automated ML test results", "", f"Run on {datetime.now():%Y-%m-%d %H:%M}.", "",
             f"**{passed} of {len(results)} tests passed.**", "", "| # | Test | Result |", "|---|------|--------|"]
    for i, (name, ok, _) in enumerate(results, 1):
        lines.append(f"| {i} | {name} | {'PASS' if ok else 'FAIL'} |")
    Path(__file__).with_name("test_results_ml.md").write_text("\n".join(lines) + "\n")
    sys.exit(0 if passed == len(results) else 1)
