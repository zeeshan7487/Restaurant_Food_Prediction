"""
evaluate_policy.py - "How much food waste could the forecast save?"

The public dataset has orders (demand) but no waste. So waste is SIMULATED on
the test weeks by comparing two ways of deciding how much to prepare:

  MANUAL policy (the yardstick)
      prepare = average of the last 4 weeks of orders x (1 + buffer)
      This imitates a kitchen that plans from experience and cooks a bit extra
      to be safe. The buffer (15 % by default) is an ASSUMPTION - it is varied
      below so the result does not depend on one lucky number.

  AI policy
      prepare = predicted demand x safety factor
      The safety factor is learned from validation errors (see train.py) so the
      kitchen covers demand about 50 %, 70 %, 80 %, 90 % or 95 % of the time.

For every meal/kitchen/week in the test set:
      waste     = prepared - orders   (if we prepared more than was ordered)
      shortage  = orders - prepared   (if we prepared less than was ordered)

WHY THIS IS AN HONEST COMPARISON: it is easy to "reduce waste" by preparing
less - but then customers are turned away. So we report waste AND shortage, and
compare the policies at the SAME coverage (the AI safety factor is tuned until it
covers demand exactly as often as the manual policy) and draw the whole trade-off curve.
A block bootstrap over meal/kitchen series gives a 95 % interval for the waste reduction.
"""

import json

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd

import config


def evaluate(prep, actual):
    prep = np.asarray(prep, dtype=float)
    actual = np.asarray(actual, dtype=float)
    waste = np.clip(prep - actual, 0, None).sum()
    shortage = np.clip(actual - prep, 0, None).sum()
    return {
        "prepared_total": round(float(prep.sum())),
        "demand_total": round(float(actual.sum())),
        "waste_total": round(float(waste)),
        "waste_pct_of_prepared": round(float(waste / prep.sum() * 100), 2),
        "shortage_total": round(float(shortage)),
        "shortage_pct_of_demand": round(float(shortage / actual.sum() * 100), 2),
        "service_level_pct": round(float((prep >= actual).mean() * 100), 1),  # % of cases fully covered
    }


def main():
    test = pd.read_csv(config.PROCESSED_DIR / "test_predictions.csv")
    report = json.loads(config.METRICS_FILE.read_text())
    safety = {float(k): v for k, v in report["safety_factors"].items()}
    test = test.dropna(subset=["lag_mean_4"])
    actual = test["actual"].to_numpy()

    results = {"trained_on_synthetic_data": report["trained_on_synthetic_data"],
               "rows_evaluated": int(len(test)), "manual": {}, "ai": {}}

    for b in [0.0, 0.05, 0.10, 0.15, 0.20, 0.30]:
        results["manual"][f"buffer_{int(round(b * 100))}%"] = evaluate(test["lag_mean_4"] * (1 + b), actual)
    for q, f in safety.items():
        results["ai"][f"service_level_{int(q * 100)}%"] = evaluate(test["predicted"] * f, actual)

    # ---- Fair comparison: give the AI policy the SAME coverage as the manual one ----------
    # Coverage ("service level") = share of meal/kitchen/week cases where the prepared
    # quantity was enough. For each manual buffer we search (bisection) for the AI safety
    # factor that gives exactly the same coverage, then compare waste and shortage.
    pred = test["predicted"].to_numpy()

    def coverage(f):
        return float((pred * f >= actual).mean())

    def factor_for_coverage(target):
        # Binary search (bisection) for the safety factor that gives exactly the
        # manual policy's coverage: coverage is a steadily increasing function of
        # the factor (a bigger factor means preparing more, which can only cover
        # more cases, never fewer), so we can narrow in on it by repeatedly
        # halving the search range. 60 rounds is far more precision than needed;
        # it converges in well under 30.
        lo, hi = 0.3, 5.0                      # coverage grows as the factor grows
        for _ in range(60):
            mid = (lo + hi) / 2
            if coverage(mid) < target:
                lo = mid
            else:
                hi = mid
        return hi

    series_key = test["center_id"].astype(str) + "_" + test["meal_id"].astype(str)
    series_codes = pd.factorize(series_key)[0]
    n_series = series_codes.max() + 1
    rng = np.random.default_rng(config.RANDOM_STATE)

    def per_series_sums(prep):
        waste = np.clip(prep - actual, 0, None)
        short = np.clip(actual - prep, 0, None)
        return (np.bincount(series_codes, waste, n_series), np.bincount(series_codes, short, n_series),
                np.bincount(series_codes, prep, n_series))

    matched = {}
    for b in [0.0, 0.05, 0.10, 0.15, 0.20, 0.30]:
        man_prep = test["lag_mean_4"].to_numpy() * (1 + b)
        man = evaluate(man_prep, actual)
        f = factor_for_coverage(man["service_level_pct"] / 100)
        ai = evaluate(pred * f, actual)
        # Block bootstrap over meal/kitchen series (weeks of one series are correlated,
        # so whole series are resampled together) -> 95 % interval for the waste reduction.
        # Sum each meal/kitchen's waste and shortage across all its weeks first,
        # so resampling below picks whole series, never individual weeks.
        mw, ms, mp = per_series_sums(man_prep)
        aw, as_, ap = per_series_sums(pred * f)
        reductions = []
        for _ in range(1000):
            # Block bootstrap: build a fake dataset by picking n_series meal/kitchen
            # series AT RANDOM, WITH REPLACEMENT (some series picked more than
            # once, some not at all), and recompute the waste reduction on that
            # fake dataset. Doing this 1,000 times and taking the middle 95% of
            # the results gives the 95% confidence interval -- it reflects that
            # a different sample of kitchens could have given a somewhat
            # different answer, without assuming the weeks within one kitchen
            # are independent (they aren't: demand for Tuesday is similar to
            # demand for Monday at the same kitchen).
            idx = rng.integers(0, n_series, n_series)
            if mw[idx].sum() > 0:
                reductions.append(1 - aw[idx].sum() / mw[idx].sum())
        lo, hi = np.percentile(reductions, [2.5, 97.5]) * 100
        matched[f"buffer_{int(round(b * 100))}%"] = {
            "manual": man, "ai_safety_factor": round(float(f), 4), "ai": ai,
            "waste_reduction_pct": round((1 - ai["waste_total"] / man["waste_total"]) * 100, 1) if man["waste_total"] else None,
            "waste_reduction_ci95_pct": [round(float(lo), 1), round(float(hi), 1)],
            "shortage_change_pct": round((ai["shortage_total"] / man["shortage_total"] - 1) * 100, 1) if man["shortage_total"] else None,
        }
    results["matched_comparison"] = matched
    key = f"buffer_{int(round(config.MANUAL_BUFFER * 100))}%"
    results["headline"] = {"manual_policy": f"4-week average + {int(round(config.MANUAL_BUFFER * 100))}% buffer",
                           "matched_key": key, **matched[key]}
    # keep the names used by the web app's results page
    results["headline"]["ai_policy_at_similar_service_level"] = f"safety factor {matched[key]['ai_safety_factor']}"

    config.REPORT_DIR.mkdir(parents=True, exist_ok=True)
    (config.REPORT_DIR / "policy_results.json").write_text(json.dumps(results, indent=2))

    manual_main = results["manual"][key]

    # ---- trade-off chart: waste vs shortage for many settings of each policy ----
    man = [(evaluate(test["lag_mean_4"] * (1 + b), actual), b) for b in np.arange(0.0, 0.61, 0.02)]
    ai = [(evaluate(test["predicted"] * f, actual), f) for f in np.arange(0.85, 1.61, 0.02)]
    fig, ax = plt.subplots(figsize=(7, 4.2))
    ax.plot([r["shortage_pct_of_demand"] for r, _ in man], [r["waste_pct_of_prepared"] for r, _ in man],
            color="#9aa5b1", lw=2, label="Manual (4-week average + buffer)")
    ax.plot([r["shortage_pct_of_demand"] for r, _ in ai], [r["waste_pct_of_prepared"] for r, _ in ai],
            color="#2b6cb0", lw=2, label="AI (predicted demand x safety factor)")
    ax.scatter([manual_main["shortage_pct_of_demand"]], [manual_main["waste_pct_of_prepared"]],
               color="#c53030", zorder=5, label=f"Manual, {int(config.MANUAL_BUFFER * 100)}% buffer")
    ax.set_xlabel("Shortage: % of customer demand NOT covered")
    ax.set_ylabel("Waste: % of prepared food thrown away")
    ax.set_title("Lower-left is better: less waste AND fewer shortages")
    ax.legend()
    ax.spines[["top", "right"]].set_visible(False)
    fig.tight_layout(); fig.savefig(config.REPORT_DIR / "fig_waste_tradeoff.png", dpi=200); plt.close(fig)

    print(json.dumps(results["headline"], indent=2))


if __name__ == "__main__":
    main()
