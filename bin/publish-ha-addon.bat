@echo off
REM Copies the YTAN worker add-on (ha-addon\ytan-worker + the tools it runs) into the
REM "addons" share of the Home Assistant system (Samba add-on) and installs/rebuilds and
REM starts it through the Supervisor API (bin\ha-supervisor.js).
REM Needs in .env: HA_URL, HA_TOKEN (long-lived token), HA_SAMBA_USER, HA_SAMBA_PASSWORD.
setlocal
set "ROOT_DIR=%~dp0.."
for /f "usebackq tokens=1,* delims==" %%A in ("%ROOT_DIR%\.env") do (
    if "%%A"=="HA_URL" set "HA_URL=%%B"
    if "%%A"=="HA_SAMBA_USER" set "HA_SAMBA_USER=%%B"
    if "%%A"=="HA_SAMBA_PASSWORD" set "HA_SAMBA_PASSWORD=%%B"
)
if not defined HA_URL (echo HA_URL missing in .env & exit /b 1)
for /f "tokens=2 delims=/:" %%H in ("%HA_URL%") do set "HA_HOST=%%H"
set "SHARE=\\%HA_HOST%\addons"
set "TARGET=%SHARE%\ytan-worker"

echo ==^> Connecting %SHARE%
net use "%SHARE%" /delete /y >nul 2>&1
net use "%SHARE%" "%HA_SAMBA_PASSWORD%" /user:%HA_SAMBA_USER% >nul || (echo Cannot connect %SHARE% & exit /b 1)

echo ==^> Copying the add-on to %TARGET%
robocopy "%ROOT_DIR%\ha-addon\ytan-worker" "%TARGET%" /MIR /XD tools /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 goto copyfail
robocopy "%ROOT_DIR%\tools" "%TARGET%\tools" ytan_jobs.py ytan_worker.py ytanlog.py /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 goto copyfail
robocopy "%ROOT_DIR%\tools\radar" "%TARGET%\tools\radar" /MIR /XD .venv tests __pycache__ /XF *.pyc /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 goto copyfail
robocopy "%ROOT_DIR%\tools\wind" "%TARGET%\tools\wind" /MIR /XD tests __pycache__ /XF *.pyc /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 goto copyfail
net use "%SHARE%" /delete /y >nul 2>&1

echo ==^> Installing / rebuilding and starting (building the image on the Pi takes a few minutes)
node "%ROOT_DIR%\bin\ha-supervisor.js" deploy local_ytan_worker || exit /b 1
echo ==^> Done. Log: node bin\ha-supervisor.js logs local_ytan_worker
exit /b 0

:copyfail
echo Copying to %TARGET% failed.
net use "%SHARE%" /delete /y >nul 2>&1
exit /b 1
