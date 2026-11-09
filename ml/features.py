"""
features.py - turns raw rows into the numbers the model learns from.

IMPORTANT DESIGN RULE: the SAME function is used for training and for the web
app's live predictions. If we wrote the feature code twice, the two copies could
slowly drift apart and the live predictions would silently become wrong
("training/serving skew"). One function = one truth.
"""

import numpy as np
import pandas as pd

from config import LAGS

# Columns the model sees. Order matters and is stored inside the saved model.
NUMERIC_FEATURES = [
    "checkout_price", "base_price", "price_ratio", "discount_pct",
    "emailer_for_promotion", "homepage_featured",
    "week_sin", "week_cos",
    "op_area",
] + [f"lag_{k}" for k in LAGS] + ["lag_mean_2", "lag_mean_4", "lag_trend"]

CATEGORICAL_FEATURES = ["category", "cuisine", "center_type", "region_code", "city_code"]

ALL_FEATURES = NUMERIC_FEATURES + CATEGORICAL_FEATURES

KEYS = ["center_id", "meal_id", "week"]


def add_lag_features(df: pd.DataFrame) -> pd.DataFrame:
    """
    Add 'orders k weeks ago' columns.

    We look the lag up by WEEK NUMBER (not by row position) because some
    weeks are missing for some meal/centre pairs. Shifting by row position
    would silently pick the wrong week.
    """
    out = df.copy()
    for k in LAGS:
        past = df[KEYS + ["num_orders"]].copy()
        past["week"] = past["week"] + k          # week 10's orders become "week 11, lag k=1"
        past = past.rename(columns={"num_orders": f"lag_{k}"})
        out = out.merge(past, on=KEYS, how="left")

    out["lag_mean_2"] = out[["lag_1", "lag_2"]].mean(axis=1)          # NaN ignored
    out["lag_mean_4"] = out[[f"lag_{k}" for k in LAGS]].mean(axis=1)
    # Is demand rising or falling? (last week vs the average of the older weeks)
    # Values > 1 mean last week was busier than the weeks before it.
    older = out[["lag_2", "lag_3", "lag_4"]].mean(axis=1)
    out["lag_trend"] = out["lag_1"] / older
    return out


def build_features(orders: pd.DataFrame, meals: pd.DataFrame, centers: pd.DataFrame) -> pd.DataFrame:
    """
    orders  : rows with week, center_id, meal_id, checkout_price, base_price,
              emailer_for_promotion, homepage_featured, num_orders
              (num_orders may be NaN for the week we want to predict)
    Returns the same rows with all model input columns added.
    """
    df = orders.merge(meals, on="meal_id", how="left").merge(centers, on="center_id", how="left")

    df["price_ratio"] = df["checkout_price"] / df["base_price"]       # < 1 means the meal is discounted
    df["discount_pct"] = (1 - df["price_ratio"]) * 100

    # Encode "which week of the year" as a point on a circle (sine + cosine) instead
    # of a single number 1-52. A single number would make the model think week 52
    # and week 1 are far apart, when they're actually next to each other (both near
    # the end/start of the year); sine+cosine wrap around correctly.
    week_of_year = (df["week"] - 1) % 52
    df["week_sin"] = np.sin(2 * np.pi * week_of_year / 52)
    df["week_cos"] = np.cos(2 * np.pi * week_of_year / 52)

    df = add_lag_features(df)
    # Categorical columns (meal category, cuisine, kitchen type/region/city) are
    # treated as LABELS, not numbers -> store them as text so scikit-learn one-hot
    # encodes them, rather than wrongly treating e.g. category "3" as bigger than "1".
    for col in CATEGORICAL_FEATURES:
        df[col] = df[col].astype(str)
    return df


def has_some_history(df: pd.DataFrame) -> pd.Series:
    """True for rows where at least one of the previous 4 weeks is known."""
    return df[[f"lag_{k}" for k in LAGS]].notna().any(axis=1)
