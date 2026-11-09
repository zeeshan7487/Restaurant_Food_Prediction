"""
train.py - trains, compares and saves the demand-forecasting model.

Run from inside the ml/ folder:   python train.py

What it does, step by step (each step is also explained in the thesis):
  1. Load the raw CSV files and check their quality.
  2. Build features (orders in previous weeks, price, promotion, seasonality...).
  3. Split the data BY TIME into train / validation / test.
  4. Train several models: two simple baselines and three machine-learning models.
  5. Pick the best model using the VALIDATION weeks only.
  6. Re-train the winner on train+validation and score it ONCE on the TEST weeks.
  7. Learn the "safety factors" used to recommend how much to prepare.
  8. Save the model, the scores, the charts and the test predictions.
"""

import json
import sys
import warnings

import joblib
import matplotlib
matplotlib.use("Agg")                     # draw charts to files, no window needed
import matplotlib.pyplot as plt
import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer, TransformedTargetRegressor
from sklearn.ensemble import HistGradientBoostingRegressor, RandomForestRegressor
from sklearn.impute import SimpleImputer
from sklearn.inspection import permutation_importance
from sklearn.linear_model import Ridge
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder, StandardScaler

import config
from features import (ALL_FEATURES, CATEGORICAL_FEATURES, NUMERIC_FEATURES,
                      build_features, has_some_history)

warnings.filterwarnings("ignore")


# ----------------------------------------------------------------------------
# 1. Loading and checking the data
# ----------------------------------------------------------------------------
def load_raw():
    orders = pd.read_csv(config.RAW_DIR / "train.csv")
    meals = pd.read_csv(config.RAW_DIR / "meal_info.csv")
    centers = pd.read_csv(config.RAW_DIR / "fulfilment_center_info.csv")
    return orders, meals, centers


def data_quality_report(orders, meals, centers):
    """Numbers for the 'Data Analysis' section of the thesis."""
    report = {
        "order_rows": int(len(orders)),
        "meals": int(meals.meal_id.nunique()),
        "centers": int(centers.center_id.nunique()),
        "week_min": int(orders.week.min()),
        "week_max": int(orders.week.max()),
        "missing_values_per_column": {c: int(v) for c, v in orders.isna().sum().items()},
        "duplicate_rows": int(orders.duplicated(["center_id", "meal_id", "week"]).sum()),
        "orders_min": int(orders.num_orders.min()),
        "orders_median": float(orders.num_orders.median()),
        "orders_mean": round(float(orders.num_orders.mean()), 2),
        "orders_max": int(orders.num_orders.max()),
        "orders_std": round(float(orders.num_orders.std()), 2),
        "share_rows_with_promotion": round(float(orders.emailer_for_promotion.mean()), 4),
        "share_rows_featured": round(float(orders.homepage_featured.mean()), 4),
        "rows_with_orphan_meal_id": int((~orders.meal_id.isin(meals.meal_id)).sum()),
        "rows_with_orphan_center_id": int((~orders.center_id.isin(centers.center_id)).sum()),
    }
    return report


# ----------------------------------------------------------------------------
# 2. Metrics
# ----------------------------------------------------------------------------
def metrics(actual, predicted):
    actual = np.asarray(actual, dtype=float)
    predicted = np.clip(np.asarray(predicted, dtype=float), 0, None)
    err = predicted - actual
    return {
        "MAE": round(float(np.mean(np.abs(err))), 2),                       # average miss, in orders
        "RMSE": round(float(np.sqrt(np.mean(err ** 2))), 2),                # punishes big misses
        "WAPE_%": round(float(np.sum(np.abs(err)) / np.sum(actual) * 100), 2),  # total error as % of total demand
        "RMSLE": round(float(np.sqrt(np.mean((np.log1p(predicted) - np.log1p(actual)) ** 2))), 4),
        "R2": round(float(1 - np.sum(err ** 2) / np.sum((actual - actual.mean()) ** 2)), 4),
        "Bias_%": round(float(err.sum() / actual.sum() * 100), 2),          # + = over-forecasting
    }


# ----------------------------------------------------------------------------
# 3. The models
# ----------------------------------------------------------------------------
class LastWeekBaseline:
    """Baseline 1: 'next week will be the same as last week'."""
    name = "Baseline: same as last week"
    def fit(self, X, y): return self
    def predict(self, X): return X["lag_1"].fillna(X["lag_mean_4"]).fillna(0).to_numpy()


class MovingAverageBaseline:
    """Baseline 2: 'next week will be the average of the last 4 weeks'.
    This is close to how a kitchen plans from experience."""
    name = "Baseline: 4-week average"
    def fit(self, X, y): return self
    def predict(self, X): return X["lag_mean_4"].fillna(0).to_numpy()


def make_linear():
    # Ridge regression needs numbers on a similar scale (StandardScaler) and can't
    # handle missing values (SimpleImputer fills gaps with the median). Tree models
    # below don't need either of these, which is one reason they're simpler to use
    # on this kind of messy, real-world data.
    pre = ColumnTransformer([
        ("num", Pipeline([("imp", SimpleImputer(strategy="median")), ("sc", StandardScaler())]), NUMERIC_FEATURES),
        ("cat", OneHotEncoder(handle_unknown="ignore"), CATEGORICAL_FEATURES),
    ])
    # TransformedTargetRegressor: train on log(1 + orders) instead of raw orders, then
    # convert back with exp. This (a) stops a model prediction ever going negative,
    # and (b) stops a handful of huge-demand meals from dominating the training error.
    reg = TransformedTargetRegressor(Ridge(alpha=1.0), func=np.log1p, inverse_func=np.expm1)
    return Pipeline([("pre", pre), ("model", reg)])


def make_forest():
    # Tree-based models split on raw values, so numeric features don't need scaling
    # or imputing (a missing value is just another branch of the tree) -> "passthrough".
    pre = ColumnTransformer([
        ("num", "passthrough", NUMERIC_FEATURES),
        ("cat", OneHotEncoder(handle_unknown="ignore", sparse_output=False), CATEGORICAL_FEATURES),
    ])
    # n_estimators=80 trees, each at most 18 levels deep, each leaf covering at
    # least 5 rows, each tree trained on half the data (max_samples=0.5) -- all
    # chosen as reasonable middle-of-the-road values (Section 3.3.2), not tuned
    # extensively, to keep the comparison simple.
    rf = RandomForestRegressor(n_estimators=80, max_depth=18, min_samples_leaf=5, max_samples=0.5,
                               n_jobs=-1, random_state=config.RANDOM_STATE)
    reg = TransformedTargetRegressor(rf, func=np.log1p, inverse_func=np.expm1)
    return Pipeline([("pre", pre), ("model", reg)])


def make_boosting():
    pre = ColumnTransformer([
        ("num", "passthrough", NUMERIC_FEATURES),
        ("cat", OneHotEncoder(handle_unknown="ignore", sparse_output=False), CATEGORICAL_FEATURES),
    ])
    # Gradient boosting builds trees one at a time, each one correcting the
    # previous trees' mistakes (up to 400 of them). This is the model that won
    # on the validation weeks -- see the thesis for why tree ensembles tend to
    # beat a plain linear model on this kind of tabular data.
    hgb = HistGradientBoostingRegressor(max_iter=400, learning_rate=0.06, max_leaf_nodes=31,
                                        min_samples_leaf=40, l2_regularization=1.0,
                                        random_state=config.RANDOM_STATE)
    reg = TransformedTargetRegressor(hgb, func=np.log1p, inverse_func=np.expm1)
    return Pipeline([("pre", pre), ("model", reg)])


CANDIDATES = {
    "Baseline: same as last week": LastWeekBaseline,
    "Baseline: 4-week average": MovingAverageBaseline,
    "Ridge regression": make_linear,
    "Random forest": make_forest,
    "Gradient boosting": make_boosting,
}


# ----------------------------------------------------------------------------
# 4. Charts
# ----------------------------------------------------------------------------
def save_charts(test, comparison, importance, best_name):
    config.REPORT_DIR.mkdir(parents=True, exist_ok=True)
    plt.rcParams.update({"font.size": 10, "axes.spines.top": False, "axes.spines.right": False})

    # Chart 1: model comparison on the test weeks
    fig, ax = plt.subplots(figsize=(7, 3.6))
    names = list(comparison.keys())
    vals = [comparison[n]["test"]["WAPE_%"] for n in names]
    colors = ["#9aa5b1" if n.startswith("Baseline") else "#2b6cb0" for n in names]
    ax.barh(names, vals, color=colors)
    for i, v in enumerate(vals):
        ax.text(v + 0.3, i, f"{v:.1f}%", va="center")
    ax.set_xlabel("Forecast error on test weeks (WAPE %, lower is better)")
    ax.invert_yaxis()
    fig.tight_layout(); fig.savefig(config.REPORT_DIR / "fig_model_comparison.png", dpi=200); plt.close(fig)

    # Chart 2: actual vs predicted
    fig, ax = plt.subplots(figsize=(5, 5))
    sample = test.sample(min(len(test), 6000), random_state=1)
    ax.scatter(sample.actual, sample.predicted, s=6, alpha=0.35, color="#2b6cb0")
    lim = [0, max(sample.actual.max(), sample.predicted.max()) * 1.02]
    ax.plot(lim, lim, color="#c53030", lw=1, label="perfect prediction")
    ax.set_xlim(lim); ax.set_ylim(lim)
    ax.set_xlabel("Actual orders"); ax.set_ylabel("Predicted orders"); ax.legend()
    ax.set_title(f"{best_name}: test weeks")
    fig.tight_layout(); fig.savefig(config.REPORT_DIR / "fig_actual_vs_predicted.png", dpi=200); plt.close(fig)

    # Chart 3: total demand actual vs predicted per test week
    weekly = test.groupby("week")[["actual", "predicted"]].sum()
    fig, ax = plt.subplots(figsize=(7, 3.6))
    ax.plot(weekly.index, weekly.actual, marker="o", color="#1a202c", label="Actual")
    ax.plot(weekly.index, weekly.predicted, marker="s", color="#2b6cb0", label="Predicted")
    ax.set_xlabel("Week"); ax.set_ylabel("Total orders (all meals, all kitchens)")
    ax.legend(); ax.set_title("Total weekly demand on the test weeks")
    fig.tight_layout(); fig.savefig(config.REPORT_DIR / "fig_weekly_total.png", dpi=200); plt.close(fig)

    # Chart 4: which inputs matter most
    top = importance.head(10).iloc[::-1]
    fig, ax = plt.subplots(figsize=(7, 3.8))
    ax.barh(top.index, top.values, color="#2b6cb0")
    ax.set_xlabel("Importance (drop in accuracy when the input is shuffled)")
    fig.tight_layout(); fig.savefig(config.REPORT_DIR / "fig_feature_importance.png", dpi=200); plt.close(fig)


# ----------------------------------------------------------------------------
# 5. Main
# ----------------------------------------------------------------------------
def main():
    config.MODEL_DIR.mkdir(parents=True, exist_ok=True)
    config.REPORT_DIR.mkdir(parents=True, exist_ok=True)
    config.PROCESSED_DIR.mkdir(parents=True, exist_ok=True)

    synthetic = (config.RAW_DIR / "SYNTHETIC_STAND_IN.txt").exists()
    if synthetic:
        print("\n*** WARNING: training on SYNTHETIC stand-in data. Do not report these numbers. ***\n")

    orders, meals, centers = load_raw()
    quality = data_quality_report(orders, meals, centers)
    print("Data quality:", json.dumps(quality, indent=2))

    # --- features & split -----------------------------------------------------
    df = build_features(orders, meals, centers)
    # Drop rows with no usable history at all (can't forecast a meal/center we've
    # never seen sell before).
    df = df[has_some_history(df)].reset_index(drop=True)
    # Split by WEEK NUMBER, not randomly. The test weeks are always the most
    # recent ones, so the model is always judged on forecasting the future from
    # the past -- never on "predicting" a week it was trained on, which would make
    # the accuracy numbers look better than they really are (data leakage).
    max_week = int(df.week.max())
    test_start = max_week - config.TEST_WEEKS + 1
    val_start = test_start - config.VALIDATION_WEEKS

    train = df[df.week < val_start]       # used to fit each candidate model
    val = df[(df.week >= val_start) & (df.week < test_start)]   # used to pick the best one and learn safety factors
    test = df[df.week >= test_start]      # touched exactly once, right at the end, for the final reported numbers
    trainval = df[df.week < test_start]   # train+validation together, used to refit the winner before the final test
    split = {"train_weeks": [int(train.week.min()), val_start - 1], "validation_weeks": [val_start, test_start - 1],
             "test_weeks": [test_start, max_week],
             "train_rows": len(train), "validation_rows": len(val), "test_rows": len(test)}
    print("Split:", split)

    # --- train every candidate, score on VALIDATION ------------------------------
    comparison, val_predictions = {}, {}
    for name, factory in CANDIDATES.items():
        print(f"Training {name} ...")
        model = factory()
        model.fit(train[ALL_FEATURES], train["num_orders"])
        pred = model.predict(val[ALL_FEATURES])
        val_predictions[name] = pred
        comparison[name] = {"validation": metrics(val.num_orders, pred)}
        print("   validation:", comparison[name]["validation"])

    # Choose among the machine-learning models only (baselines are yardsticks)
    ml_names = [n for n in CANDIDATES if not n.startswith("Baseline")]
    best_name = min(ml_names, key=lambda n: comparison[n]["validation"]["MAE"])
    print(f"\nBest model on validation weeks: {best_name}")

    # --- safety factors (from validation predictions of the train-only model) -----
    # A "safety factor" turns a prediction into a preparation quantity: prepare
    # predicted_demand x factor, rather than exactly the prediction. We work out
    # the factor from how wrong the model has been so far: for every validation
    # week, ratio = actual orders / predicted orders tells us how much the model
    # under- or over-shot. Taking the q-th quantile of that ratio (e.g. q=0.80)
    # gives a factor that, if used in the past, would have been enough to cover
    # demand in 80% of cases -- this is the "service level". One factor is learned
    # per service level (Section 3.3.4 in the thesis / the newsvendor idea).
    ratio = val.num_orders.to_numpy() / np.clip(val_predictions[best_name], 1, None)
    safety = {str(q): round(float(np.quantile(ratio, q)), 4) for q in config.SERVICE_LEVELS}
    print("Safety factors:", safety)

    # --- refit everything on train+validation, score once on TEST ----------------
    final_models = {}
    for name, factory in CANDIDATES.items():
        model = factory()
        model.fit(trainval[ALL_FEATURES], trainval["num_orders"])
        pred = model.predict(test[ALL_FEATURES])
        comparison[name]["test"] = metrics(test.num_orders, pred)
        final_models[name] = model
        print(f"TEST {name}: {comparison[name]['test']}")

    best_model = final_models[best_name]
    test_pred = np.clip(best_model.predict(test[ALL_FEATURES]), 0, None)

    # --- feature importance (on validation rows, using the train-only model) --------
    # Permutation importance: for each input column in turn, shuffle its values
    # (so it becomes useless) and see how much worse the model's error gets. A
    # big increase in error means the model relies heavily on that column; a tiny
    # increase means it barely matters. Repeated 3 times per column and averaged,
    # since shuffling is random and gives a slightly different answer each time.
    probe = CANDIDATES[best_name]()
    probe.fit(train[ALL_FEATURES], train["num_orders"])
    sample = val.sample(min(len(val), 8000), random_state=1)
    imp = permutation_importance(probe, sample[ALL_FEATURES], sample["num_orders"],
                                 n_repeats=3, random_state=1, scoring="neg_mean_absolute_error", n_jobs=-1)
    importance = pd.Series(imp.importances_mean, index=ALL_FEATURES).sort_values(ascending=False)

    # --- save test predictions for the waste study -----------------------------------
    out = test[["week", "center_id", "meal_id", "lag_mean_4", "lag_1", "emailer_for_promotion", "homepage_featured",
                "category", "cuisine", "center_type", "discount_pct"]].copy()
    out["actual"] = test["num_orders"].to_numpy()
    out["predicted"] = test_pred
    out.to_csv(config.PROCESSED_DIR / "test_predictions.csv", index=False)
    save_charts(out, comparison, importance, best_name)

    # --- save the model file used by the web app -------------------------------------
    artefact = {
        "model": best_model,
        "model_name": best_name,
        "features": ALL_FEATURES,
        "safety_factors": safety,
        "default_service_level": config.DEFAULT_SERVICE_LEVEL,
        "split": split,
        "trained_on_synthetic_data": synthetic,
        "test_metrics": comparison[best_name]["test"],
    }
    joblib.dump(artefact, config.MODEL_FILE)

    import platform, sklearn
    report = {
        "environment": {"python": platform.python_version(), "scikit_learn": sklearn.__version__,
                        "pandas": pd.__version__, "numpy": np.__version__, "os": platform.platform()},
        "trained_on_synthetic_data": synthetic,
        "data_quality": quality,
        "split": split,
        "best_model": best_name,
        "comparison": comparison,
        "safety_factors": safety,
        "feature_importance": {k: round(float(v), 4) for k, v in importance.items()},
    }
    config.METRICS_FILE.write_text(json.dumps(report, indent=2))
    print(f"\nSaved model to {config.MODEL_FILE}\nSaved report to {config.METRICS_FILE}")


if __name__ == "__main__":
    sys.exit(main())
