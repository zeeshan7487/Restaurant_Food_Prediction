"""
service.py - the prediction service (a tiny web API written with Flask).

The PHP website does not contain any machine learning. When a user asks for a
prediction, PHP collects the numbers from MySQL and sends them to this service
as JSON; this service loads the trained model, calculates the prediction and
sends JSON back. Keeping ML in Python and the website in PHP means each part
uses the tool it is best at, and either can be changed without touching the other.

Start it with:   python service.py
It listens ONLY on 127.0.0.1 (this computer), so nobody on the network can call it.
"""

import os
import sys
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
from flask import Flask, jsonify, request

sys.path.insert(0, str(Path(__file__).resolve().parent))
import config
from features import ALL_FEATURES, build_features
from config import LAGS

app = Flask(__name__)
ARTEFACT = joblib.load(config.MODEL_FILE)
MODEL = ARTEFACT["model"]
API_KEY = os.environ.get("ML_API_KEY", "")     # optional shared secret


def error(message, status=422):
    return jsonify({"ok": False, "error": message}), status


@app.before_request
def check_key():
    if API_KEY and request.headers.get("X-API-Key") != API_KEY:
        return error("Invalid or missing API key.", 401)


@app.get("/health")
def health():
    return jsonify({
        "ok": True,
        "model_name": ARTEFACT["model_name"],
        "trained_on_synthetic_data": ARTEFACT["trained_on_synthetic_data"],
        "test_metrics": ARTEFACT["test_metrics"],
        "split": ARTEFACT["split"],
        "service_levels": list(ARTEFACT["safety_factors"].keys()),
    })


def require(payload, path, kind):
    """Read payload[a][b]... and convert to a number/string, or raise ValueError."""
    node = payload
    for key in path.split("."):
        if not isinstance(node, dict) or key not in node or node[key] is None:
            raise ValueError(f"Missing field: {path}")
        node = node[key]
    try:
        return kind(node)
    except (TypeError, ValueError):
        raise ValueError(f"Field {path} must be a {kind.__name__}.")


@app.post("/predict")
def predict():
    p = request.get_json(silent=True)
    if not isinstance(p, dict):
        return error("Request body must be JSON.", 400)
    try:
        week = require(p, "week", int)
        price = require(p, "checkout_price", float)
        base = require(p, "base_price", float)
        promo = require(p, "emailer_for_promotion", int)
        featured = require(p, "homepage_featured", int)
        center = {"center_id": require(p, "center.center_id", int),
                  "city_code": require(p, "center.city_code", str),
                  "region_code": require(p, "center.region_code", str),
                  "center_type": require(p, "center.center_type", str),
                  "op_area": require(p, "center.op_area", float)}
        meal = {"meal_id": require(p, "meal.meal_id", int),
                "category": require(p, "meal.category", str),
                "cuisine": require(p, "meal.cuisine", str)}
    except ValueError as e:
        return error(str(e))

    if price <= 0 or base <= 0:
        return error("Prices must be greater than zero.")
    if promo not in (0, 1) or featured not in (0, 1):
        return error("Promotion and featured flags must be 0 or 1.")

    # ---- the last 4 weeks of sales for this meal in this kitchen ----------------
    history = p.get("history", [])
    if not isinstance(history, list):
        return error("history must be a list.")
    hist_rows = []
    for h in history:
        try:
            hw, ho = int(h["week"]), float(h["num_orders"])
        except (KeyError, TypeError, ValueError):
            return error("Each history item needs week and num_orders.")
        # Only keep history that's actually usable: strictly before the week we're
        # predicting, and no older than the furthest-back lag the model uses.
        if week - max(LAGS) <= hw < week and ho >= 0:
            hist_rows.append((hw, ho))
    if not hist_rows:
        # Mirrors the rule in has_some_history() (features.py): the web app must
        # refuse a prediction rather than silently guess from nothing.
        return error(f"No sales history found for the {max(LAGS)} weeks before week {week}. "
                     "Enter recent weekly sales first.")

    # Build a tiny table that looks exactly like a slice of the training data: the
    # past weeks (with their real order counts) plus one row for the week we want
    # to predict, with num_orders left blank. Then call the SAME build_features()
    # function used by train.py. This is what guarantees "no training/serving
    # skew" (Section 3.3.5): the lag features, price ratio, seasonality etc. are
    # computed by identical code whether the model is being trained offline or
    # answering a live request here.
    rows = [{"week": hw, "center_id": center["center_id"], "meal_id": meal["meal_id"],
             "checkout_price": price, "base_price": base, "emailer_for_promotion": 0,
             "homepage_featured": 0, "num_orders": ho} for hw, ho in hist_rows]
    rows.append({"week": week, "center_id": center["center_id"], "meal_id": meal["meal_id"],
                 "checkout_price": price, "base_price": base, "emailer_for_promotion": promo,
                 "homepage_featured": featured, "num_orders": np.nan})   # <- the week we predict

    meals = pd.DataFrame([meal])
    centers = pd.DataFrame([center])
    feats = build_features(pd.DataFrame(rows), meals, centers)
    target_row = feats[feats["week"] == week]       # pull out just the row we actually want a prediction for
    predicted = float(np.clip(MODEL.predict(target_row[ALL_FEATURES])[0], 0, None))

    # Work out the recommended quantity at every service level (50/70/80/90/95%),
    # not just the one the user asked for, so the web page can show all of them
    # side by side and let the user compare.
    levels = {}
    for level, factor in ARTEFACT["safety_factors"].items():
        levels[level] = {"safety_factor": factor, "recommended_prep": int(round(predicted * factor))}

    chosen = str(p.get("service_level", ARTEFACT["default_service_level"]))
    if chosen not in levels:
        chosen = str(ARTEFACT["default_service_level"])

    # Simple "how sure are we" hint: fewer weeks of history = less reliable. This
    # is a plain rule of thumb for the UI, not a statistical confidence interval.
    weeks_used = len(hist_rows)
    reliability = "high" if weeks_used >= 4 else "medium" if weeks_used >= 2 else "low"

    return jsonify({
        "ok": True,
        "predicted_demand": round(predicted, 1),
        "service_level": chosen,
        "recommended_prep": levels[chosen]["recommended_prep"],
        "all_levels": levels,
        "history_weeks_used": weeks_used,
        "reliability": reliability,
        "model_name": ARTEFACT["model_name"],
        "trained_on_synthetic_data": ARTEFACT["trained_on_synthetic_data"],
    })


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=int(os.environ.get("ML_PORT", "5000")), debug=False)
