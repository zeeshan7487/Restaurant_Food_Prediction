"""
prepare_import.py - prepares the CSV files that get loaded into MySQL.

Run:  python prepare_import.py
Then: php web/tools/import_data.php      (loads the CSVs into MySQL)

The public dataset only contains orders. For each historical row we also fill
in a SIMULATED "prepared" and "waste" figure, using the manual-planning policy
described in config.py (average of the previous 4 weeks + buffer). These rows are
tagged waste_source = 'simulated' in the database, so simulated numbers can never
be mistaken for real records. Rows entered later through the web app are tagged
'recorded'.
"""

import numpy as np
import pandas as pd

import config
from features import add_lag_features


def main():
    config.PROCESSED_DIR.mkdir(parents=True, exist_ok=True)
    orders = pd.read_csv(config.RAW_DIR / "train.csv")
    meals = pd.read_csv(config.RAW_DIR / "meal_info.csv")
    centers = pd.read_csv(config.RAW_DIR / "fulfilment_center_info.csv")

    df = add_lag_features(orders)
    # np.ceil (round UP) because a kitchen can't prepare half a portion -- always
    # round the prepared quantity to a whole number in the kitchen's favour.
    prepared = np.ceil(df["lag_mean_4"] * (1 + config.MANUAL_BUFFER))
    df["prepared_qty"] = prepared.astype("Int64")                       # empty when no history
    df["waste_qty"] = (prepared - df["num_orders"]).clip(lower=0).astype("Int64")
    df["waste_source"] = np.where(df["prepared_qty"].notna(), "simulated", "")

    out = df[["week", "center_id", "meal_id", "checkout_price", "base_price",
              "emailer_for_promotion", "homepage_featured", "num_orders",
              "prepared_qty", "waste_qty", "waste_source"]].rename(
        columns={"week": "week_no", "num_orders": "orders_sold"})
    out = out.sort_values(["week_no", "center_id", "meal_id"])

    out.to_csv(config.PROCESSED_DIR / "weekly_records.csv", index=False)
    centers.to_csv(config.PROCESSED_DIR / "centers.csv", index=False)
    meals.to_csv(config.PROCESSED_DIR / "meals.csv", index=False)
    print(f"Wrote {len(out):,} weekly records, {len(centers)} centres, {len(meals)} meals to {config.PROCESSED_DIR}")


if __name__ == "__main__":
    main()
