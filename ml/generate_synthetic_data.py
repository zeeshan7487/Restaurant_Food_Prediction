"""
generate_synthetic_data.py
--------------------------
DEVELOPMENT STAND-IN ONLY.

This script creates fake data that has exactly the same three files and the
same column names as the public Kaggle "Food Demand Forecasting" dataset:

    train.csv, meal_info.csv, fulfilment_center_info.csv

We use it so the whole system (database, model, web app) can be built and
tested before the real dataset is available. When the real files are placed in
data/raw/ the rest of the project runs unchanged.

Results produced from this file MUST NOT be reported as real-world results.
A marker file (data/raw/SYNTHETIC_STAND_IN.txt) is written so the training
script can warn you if you forget to swap in the real data.
"""

from pathlib import Path

import numpy as np
import pandas as pd

RAW_DIR = Path(__file__).resolve().parent.parent / "data" / "raw"
RNG = np.random.default_rng(42)  # fixed seed = same data every time

N_CENTERS = 12
N_MEALS = 20
N_WEEKS = 145

CATEGORIES = ["Beverages", "Biryani", "Desert", "Extras", "Fish", "Other Snacks",
              "Pasta", "Pizza", "Rice Bowl", "Salad", "Sandwich", "Seafood",
              "Soup", "Starters"]
CUISINES = ["Continental", "Indian", "Italian", "Thai"]


def make_meals():
    meal_ids = RNG.choice(np.arange(1000, 3000), size=N_MEALS, replace=False)
    return pd.DataFrame({
        "meal_id": np.sort(meal_ids),
        "category": RNG.choice(CATEGORIES, size=N_MEALS),
        "cuisine": RNG.choice(CUISINES, size=N_MEALS),
    })


def make_centers():
    center_ids = RNG.choice(np.arange(10, 180), size=N_CENTERS, replace=False)
    return pd.DataFrame({
        "center_id": np.sort(center_ids),
        "city_code": RNG.choice([456, 526, 590, 613, 638, 647, 675, 699], size=N_CENTERS),
        "region_code": RNG.choice([23, 34, 56, 71, 77, 85], size=N_CENTERS),
        "center_type": RNG.choice(["TYPE_A", "TYPE_B", "TYPE_C"], size=N_CENTERS, p=[0.5, 0.3, 0.2]),
        "op_area": np.round(RNG.uniform(2.0, 7.0, size=N_CENTERS), 1),
    })


def make_orders(meals, centers):
    rows = []
    row_id = 1_000_000

    # Each meal has its own typical popularity and list price.
    meal_popularity = dict(zip(meals.meal_id, RNG.lognormal(mean=5.3, sigma=0.5, size=N_MEALS)))
    meal_base_price = dict(zip(meals.meal_id, np.round(RNG.uniform(120, 500, size=N_MEALS), 2)))
    # Each centre is bigger or smaller than average.
    centre_scale = dict(zip(centers.center_id,
                            centers.op_area / centers.op_area.mean() * RNG.uniform(0.8, 1.2, size=N_CENTERS)))

    for c in centers.center_id:
        for m in meals.meal_id:
            # A meal is not sold at every centre for the whole period,
            # like in real data.
            start = int(RNG.integers(1, 20))
            # Most meals are still on the menu in the final week, as in the real data.
            end = N_WEEKS if RNG.random() < 0.92 else int(RNG.integers(60, N_WEEKS))
            noise = 0.0  # AR(1) noise: this week's noise depends on last week's
            for week in range(start, end + 1):
                if RNG.random() < 0.04:      # occasional missing week
                    continue
                base_price = meal_base_price[m] * (1 + 0.0008 * week)
                discount = RNG.choice([0.0, 0.05, 0.10, 0.20], p=[0.55, 0.2, 0.15, 0.10])
                checkout = base_price * (1 - discount)
                emailer = int(RNG.random() < 0.08)
                featured = int(RNG.random() < 0.10)

                season = 1 + 0.18 * np.sin(2 * np.pi * week / 52)
                trend = 1 + 0.002 * week
                price_effect = (checkout / base_price) ** -1.8
                promo_effect = 1.35 if emailer else 1.0
                feature_effect = 1.25 if featured else 1.0
                noise = 0.6 * noise + RNG.normal(0, 0.12)

                mean_orders = (meal_popularity[m] * centre_scale[c] * season * trend
                               * price_effect * promo_effect * feature_effect)
                orders = max(13, int(round(mean_orders * np.exp(noise))))
                rows.append((row_id, week, c, m, round(checkout, 2), round(base_price, 2),
                             emailer, featured, orders))
                row_id += 1

    return pd.DataFrame(rows, columns=[
        "id", "week", "center_id", "meal_id", "checkout_price", "base_price",
        "emailer_for_promotion", "homepage_featured", "num_orders"])


def main():
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    meals = make_meals()
    centers = make_centers()
    orders = make_orders(meals, centers)

    meals.to_csv(RAW_DIR / "meal_info.csv", index=False)
    centers.to_csv(RAW_DIR / "fulfilment_center_info.csv", index=False)
    orders.to_csv(RAW_DIR / "train.csv", index=False)
    (RAW_DIR / "SYNTHETIC_STAND_IN.txt").write_text(
        "The CSV files in this folder are SYNTHETIC development data.\n"
        "Delete this file when you replace them with the real Kaggle files.\n")
    print(f"Wrote {len(orders):,} order rows, {len(meals)} meals, {len(centers)} centres to {RAW_DIR}")


if __name__ == "__main__":
    main()
