"""
config.py - one place for the paths and settings used by every ML script.
Changing a value here changes it everywhere, so the scripts never disagree.
"""

from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
RAW_DIR = BASE_DIR / "data" / "raw"
PROCESSED_DIR = BASE_DIR / "data" / "processed"
MODEL_DIR = BASE_DIR / "ml" / "models"
REPORT_DIR = BASE_DIR / "ml" / "reports"

MODEL_FILE = MODEL_DIR / "demand_model.joblib"
METRICS_FILE = REPORT_DIR / "metrics.json"

# ---- Time-based split -------------------------------------------------------
# We NEVER split randomly. Demand is a time series: if we trained on next week
# and tested on last week we would be cheating (data leakage). So:
#   train      = oldest weeks
#   validation = the next block of weeks (used to choose the model)
#   test       = the newest weeks (used ONCE at the end, for the final score)
# The numbers below are fractions of the total number of weeks in the data.
TEST_WEEKS = 10          # last 10 weeks are the test set
VALIDATION_WEEKS = 10    # the 10 weeks before them are the validation set

# ---- Feature settings -------------------------------------------------------
LAGS = [1, 2, 3, 4]      # "orders 1, 2, 3, 4 weeks ago" are used as inputs

# ---- Waste-simulation assumptions (must be stated in the thesis) ------------
# The public dataset has orders but no waste. To study waste we simulate a
# kitchen that plans preparation from experience:
#     manual_prep = average of the last 4 weeks of orders * (1 + MANUAL_BUFFER)
# A 15 % safety buffer is a plausible "cook a bit extra to be safe" habit.
MANUAL_BUFFER = 0.15

# The AI policy prepares  predicted_demand * (a safety factor learned from the
# validation errors) so that it covers demand this often (its "service level").
SERVICE_LEVELS = [0.50, 0.70, 0.80, 0.90, 0.95]
DEFAULT_SERVICE_LEVEL = 0.80

RANDOM_STATE = 42    # fixed seed so re-running training gives the same split/results each time (reproducibility)
