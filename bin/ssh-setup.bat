@echo off
REM Shared SSH settings for bin\deploy.bat and bin\publish-*.bat - used to be
REM copied into each of them.
REM
REM   call "%~dp0ssh-setup.bat"           sets DEPLOY_USER/HOST/PORT/PATH and
REM                                       SSH_TARGET for the site in SITE
REM                                       (ytan.pesr.org if unset)
REM   call "%~dp0ssh-setup.bat" cleanup   deletes the password file again
REM
REM Afterwards run:  "%DEPLOY_SSH_CLIENT%" %SSH_TARGET% "remote command"
REM
REM The password (DEPLOY_SSH_PASSWORD from the environment or .env, never
REM committed) is handed to plink with -pwfile, not -pw: an argument shows in
REM every process list (Task Manager, wmic, Sysinternals) for as long as the
REM upload runs. The file sits in the user's own TEMP and is deleted by the
REM cleanup call (callers do it on success and on error). It is written by
REM PowerShell from the environment variable, so no shell ever parses the
REM password (characters like an ampersand or a percent sign stay intact).
REM
REM Runs in the caller's environment (call, no setlocal here). Exit code 1 =
REM no password found.
REM NOTE: never put an unescaped less-than, greater-than or pipe character
REM in a REM line of this file - cmd.exe parses them even in comments.

if /i "%~1"=="cleanup" (
    if defined YTAN_PWFILE del /q "%YTAN_PWFILE%" 2>nul
    set "YTAN_PWFILE="
    exit /b 0
)

if not defined SITE set "SITE=ytan.pesr.org"
if not defined DEPLOY_USER set "DEPLOY_USER=csteindorff"
if not defined DEPLOY_HOST set "DEPLOY_HOST=steindorff.de"
if not defined DEPLOY_PORT set "DEPLOY_PORT=22"
if not defined DEPLOY_PATH set "DEPLOY_PATH=/home/www/doc/9769/%SITE%/www"
if not defined DEPLOY_SSH_CLIENT set "DEPLOY_SSH_CLIENT=plink"

if not defined DEPLOY_SSH_PASSWORD (
    for /f "usebackq eol=# tokens=1,* delims==" %%A in ("%~dp0..\.env") do (
        if "%%A"=="DEPLOY_SSH_PASSWORD" set "DEPLOY_SSH_PASSWORD=%%B"
    )
)
if not defined DEPLOY_SSH_PASSWORD (
    echo ==^> DEPLOY_SSH_PASSWORD is not set and not found in .env - add a line
    echo      DEPLOY_SSH_PASSWORD=... to .env, or set the env var before running.
    exit /b 1
)

set "YTAN_PWFILE=%TEMP%\ytan-ssh-%RANDOM%%RANDOM%.txt"
powershell -NoProfile -NonInteractive -Command "[IO.File]::WriteAllText($env:YTAN_PWFILE, $env:DEPLOY_SSH_PASSWORD)"
if errorlevel 1 (
    echo ==^> Could not write the temporary password file.
    exit /b 1
)

REM No -batch on purpose: plink keeps its own host-key cache, separate from
REM ssh.exe's known_hosts, so the very first run against a host may show a
REM one-time "store this host key?" prompt - accept it once.
set "SSH_TARGET=-ssh -P %DEPLOY_PORT% -l %DEPLOY_USER% -pwfile "%YTAN_PWFILE%" %DEPLOY_HOST%"
exit /b 0
