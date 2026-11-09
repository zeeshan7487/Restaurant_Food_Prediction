"""
error_analysis.py - WHERE does the model make its biggest errors?

Splits the test-week predictions into groups (promotion or not, discounted or not,
meal category, kitchen type, size of demand) and reports the forecast error (WAPE) in
each group. Averages can hide weak spots; this table shows them.
Run after train.py:   python error_analysis.py
"""

import json

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd

import config


def wape(g):
    return float(np.abs(g.predicted - g.actual).sum() / g.actual.sum() * 100)


def bias(g):
    return float((g.predicted - g.actual).sum() / g.actual.sum() * 100)


def summarise(df, key, label_map=None):
    rows = []
    for name, g in df.groupby(key, observed=True):
        rows.append({"group": str(label_map.get(name, name)) if label_map else str(name), "rows": int(len(g)),
                     "share_of_demand_pct": round(float(g.actual.sum() / df.actual.sum() * 100), 1),
                     "WAPE_%": round(wape(g), 2), "Bias_%": round(bias(g), 2)})
    return rows


def main():
    df = pd.read_csv(config.PROCESSED_DIR / "test_predictions.csv")
    df["promotion"] = df.emailer_for_promotion.map({0: "no e-mail promotion", 1: "e-mail promotion"})
    df["featured"] = df.homepage_featured.map({0: "not featured", 1: "homepage featured"})
    df["discount"] = np.where(df.discount_pct > 1, "discounted (>1%)", "full price")
    # qcut splits the test rows into 3 equal-SIZED groups by actual demand (not
    # equal-width ranges), so "low/medium/high demand" each cover roughly a third
    # of the rows, however the demand numbers themselves are distributed.
    df["demand_size"] = pd.qcut(df.actual, 3, labels=["low demand (lowest third)", "medium demand", "high demand (highest third)"])
    # Simple, readable thresholds (not tuned): "last week was at least 20% above/
    # below the recent average" is treated as a demand swing worth separating out.
    df["last_week_change"] = np.where(df.lag_1 > df.lag_mean_4 * 1.2, "last week > 20% above average",
                                      np.where(df.lag_1 < df.lag_mean_4 * 0.8, "last week > 20% below average",
                                               "stable recent demand"))
    out = {"overall": {"rows": int(len(df)), "WAPE_%": round(wape(df), 2), "Bias_%": round(bias(df), 2)}}
    for key in ["promotion", "featured", "discount", "demand_size", "last_week_change", "center_type", "category"]:
        out[key] = summarise(df, key)
    (config.REPORT_DIR / "error_analysis.json").write_text(json.dumps(out, indent=2))

    # one figure: WAPE by group for the most informative splits
    groups = []
    for key, title in [("promotion", "Promotion"), ("discount", "Price"), ("demand_size", "Demand size"),
                       ("last_week_change", "Recent trend")]:
        for r in out[key]:
            groups.append((f"{title}: {r['group']}", r["WAPE_%"]))
    fig, ax = plt.subplots(figsize=(7.2, 4.2))
    labels = [g[0] for g in groups][::-1]
    vals = [g[1] for g in groups][::-1]
    ax.barh(labels, vals, color="#2b6cb0")
    ax.axvline(out["overall"]["WAPE_%"], color="#c53030", lw=1.2, ls="--", label=f"overall {out['overall']['WAPE_%']:.1f}%")
    for i, v in enumerate(vals):
        ax.text(v + 0.2, i, f"{v:.1f}%", va="center", fontsize=8)
    ax.set_xlabel("Forecast error on test weeks (WAPE %, lower is better)")
    ax.legend(loc="lower right")
    ax.spines[["top", "right"]].set_visible(False)
    fig.tight_layout(); fig.savefig(config.REPORT_DIR / "fig_error_by_group.png", dpi=200); plt.close(fig)
    print(json.dumps(out["overall"]), "-> error_analysis.json and fig_error_by_group.png written")


if __name__ == "__main__":
    main()
