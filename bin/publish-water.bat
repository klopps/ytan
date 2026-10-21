@echo off
REM Uploads the water distance index (storage\water\water-index.bin and
REM water-index.json, built by tools\water\build_index.py) to the server's
REM storage/water/, where WaterDistanceService reads it: new shelters and the
REM other POI types enabled on /admin/settings get their distances to sea,
REM river and lake right away on the server, without any external request.
REM
REM   bin\publish-water.bat          publishes to production (ytan.pesr.org)
REM   bin\publish-water.bat test     publishes to the test host (test.pesr.org)
REM
REM storage/water is excluded from every deploy script, so the index survives
REM later deploys until it is replaced here. The .bin goes up as a .tmp file
REM and is renamed once complete (a request never reads a half-uploaded
REM index); the .json follows afterwards. Then, on the server, optionally:
REM   php bin/poi-water-distance.php        (POIs from an older index)
REM Same connection settings and .env password lookup as bin\deploy.bat.
REM
REM NOTE: never put an unescaped less-than, greater-than or pipe character
REM in a REM line of this file - cmd.exe parses them even in comments.
setlocal
for %%I in ("%~dp0..") do set "ROOT_DIR=%%~fI"
set "INDEX=%ROOT_DIR%\storage\water\water-index.bin"
set "META=%ROOT_DIR%\storage\water\water-index.json"
if not exist "%INDEX%" (
    echo ==^> %INDEX% not found - run tools\water\build_index.py first.
    goto :error
)
if not exist "%META%" (
    echo ==^> %META% not found - run tools\water\build_index.py first.
    goto :error
)
set "SITE=ytan.pesr.org"
if /i "%~1"=="test" set "SITE=test.pesr.org"
if not defined DEPLOY_USER set "DEPLOY_USER=csteindorff"
if not defined DEPLOY_HOST set "DEPLOY_HOST=steindorff.de"
if not defined DEPLOY_PORT set "DEPLOY_PORT=22"
if not defined DEPLOY_PATH set "DEPLOY_PATH=/home/www/doc/9769/%SITE%/www"
if not defined DEPLOY_SSH_CLIENT set "DEPLOY_SSH_CLIENT=plink"
if not defined DEPLOY_SSH_PASSWORD (
    for /f "usebackq eol=# tokens=1,* delims==" %%A in ("%ROOT_DIR%\.env") do (
        if "%%A"=="DEPLOY_SSH_PASSWORD" set "DEPLOY_SSH_PASSWORD=%%B"
    )
)
if not defined DEPLOY_SSH_PASSWORD (
    echo ==^> DEPLOY_SSH_PASSWORD is not set and not found in .env.
    goto :error
)

echo ==^> Index:
type "%META%"
echo.
echo ==^> Uploading water-index.bin to %SITE%
"%DEPLOY_SSH_CLIENT%" -ssh -P %DEPLOY_PORT% -l %DEPLOY_USER% -pw %DEPLOY_SSH_PASSWORD% %DEPLOY_HOST% "mkdir -p '%DEPLOY_PATH%/storage/water' && cat > '%DEPLOY_PATH%/storage/water/water-index.bin.tmp' && mv '%DEPLOY_PATH%/storage/water/water-index.bin.tmp' '%DEPLOY_PATH%/storage/water/water-index.bin' && ls -l '%DEPLOY_PATH%/storage/water/water-index.bin'" < "%INDEX%"
if errorlevel 1 goto :error

echo ==^> Uploading water-index.json
"%DEPLOY_SSH_CLIENT%" -ssh -P %DEPLOY_PORT% -l %DEPLOY_USER% -pw %DEPLOY_SSH_PASSWORD% %DEPLOY_HOST% "cat > '%DEPLOY_PATH%/storage/water/water-index.json.tmp' && mv '%DEPLOY_PATH%/storage/water/water-index.json.tmp' '%DEPLOY_PATH%/storage/water/water-index.json'" < "%META%"
if errorlevel 1 goto :error

echo ==^> Published the water index to %SITE%.
endlocal
exit /b 0

:error
echo ==^> Publishing the water index FAILED.
endlocal
exit /b 1
