@echo off
REM Builds the YTAN data field WITHOUT a watch key (bin\build-watch.bat app)
REM for every watch model of watch\manifest.xml and uploads the builds to the
REM server as public/app/watch/ytan-DEVICE.prg, plus public/app/watch/index.json
REM for the download list in Profile, Garmin watch, Data fields (watch.js's
REM showWatchDatafields()).
REM
REM   bin\publish-watch.bat          publishes to production (ytan.pesr.org)
REM   bin\publish-watch.bat test     publishes to the test host (test.pesr.org)
REM
REM The key-less build works for every user: the YTAN Android app sends the
REM route, and a settings file (.SET) with a watch key makes it fetch the
REM route from the server itself - the server URL is compiled in, hence one
REM set of builds per host. Like the APK the files live in public/app/,
REM gitignored and excluded from every deploy script. The whole folder is
REM replaced at once (uploaded to watch.tmp and renamed). Overwrites the
REM gitignored watch\source\Config.mc like every build-watch.bat app run.
REM Same connection settings and .env password lookup as bin\deploy.bat.
REM
REM NOTE: never put an unescaped less-than, greater-than or pipe character
REM in a REM line of this file - cmd.exe parses them even in comments.

setlocal

for %%I in ("%~dp0..") do set "ROOT_DIR=%%~fI"

set "SITE=ytan.pesr.org"
set "HOST_ARG="
if /i "%~1"=="test" (
    set "SITE=test.pesr.org"
    set "HOST_ARG=test"
)

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
if not defined LOCAL_PHP set "LOCAL_PHP=C:\dev\php8\php.exe"

set "STAGE=%TEMP%\ytan-watch-%RANDOM%"
mkdir "%STAGE%"

REM call: without it cmd strips the quotes of a command that starts AND ends with one.
for /f %%D in ('call "%LOCAL_PHP%" "%ROOT_DIR%\bin\watch-datafields-json.php" --devices') do (
    call "%ROOT_DIR%\bin\build-watch.bat" app %%D %HOST_ARG% 1>nul
    if errorlevel 1 (
        echo ==^> Build for %%D FAILED.
        goto :error
    )
    copy /y "%ROOT_DIR%\watch\bin\ytan-%%D.prg" "%STAGE%\" 1>nul
    echo ==^> Built ytan-%%D.prg
)

"%LOCAL_PHP%" "%ROOT_DIR%\bin\watch-datafields-json.php" "%STAGE%" > "%STAGE%\index.json"
if errorlevel 1 goto :error
type "%STAGE%\index.json"

set "TARGET=%DEPLOY_PATH%/public/app/watch"
echo ==^> Uploading to https://%SITE%/app/watch/
tar -cf - -C "%STAGE%" . | "%DEPLOY_SSH_CLIENT%" -ssh -P %DEPLOY_PORT% -l %DEPLOY_USER% -pw %DEPLOY_SSH_PASSWORD% %DEPLOY_HOST% "rm -rf '%TARGET%.tmp' '%TARGET%.old' && mkdir -p '%TARGET%.tmp' && tar -xf - -C '%TARGET%.tmp' && { [ ! -d '%TARGET%' ] || mv '%TARGET%' '%TARGET%.old'; } && mv '%TARGET%.tmp' '%TARGET%' && rm -rf '%TARGET%.old' && ls -l '%TARGET%'"
if errorlevel 1 goto :error

rmdir /s /q "%STAGE%"
echo ==^> Published: https://%SITE%/app/watch/index.json
endlocal
exit /b 0

:error
if defined STAGE if exist "%STAGE%" rmdir /s /q "%STAGE%"
echo ==^> Publishing the data fields FAILED.
endlocal
exit /b 1
