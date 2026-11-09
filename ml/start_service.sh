#!/usr/bin/env bash
# Starts the prediction service (Linux / macOS). Keep it running while you use the website.
cd "$(dirname "$0")"
[ -f ../.venv/bin/activate ] && source ../.venv/bin/activate
python3 service.py
