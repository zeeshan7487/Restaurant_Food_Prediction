@echo off
REM Starts the prediction service. Keep this window open while you use the website.
cd /d "%~dp0"
if exist "..\.venv\Scripts\activate.bat" call "..\.venv\Scripts\activate.bat"
python service.py
pause
