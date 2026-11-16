@echo off
REM Deploys the current working tree over SSH.
REM
REM   bin\deploy.bat          production (ytan.pesr.org)
REM   bin\deploy.bat test     test system (test.pesr.org) - bin\deploy_test.bat does the same
REM
REM There is no composer on the remote host, so production dependencies are
REM built locally (in a throwaway temp copy, so the developer's own vendor\
REM with dev dependencies like phpunit is left untouched) and shipped as
REM part of the upload. The upload is tar.exe piped into PuTTY's plink.exe;
REM connection settings and the password file come from bin\ssh-setup.bat
REM (shared with the publish scripts).
REM
REM The remote .env is never touched: it's excluded from the package, and
REM this script never deletes anything on the remote side (except docs/user,
REM see below), it only extracts on top of what's already there. That also
REM means files removed locally are NOT removed from the remote - this is a
REM simple "copy newer files over", not a mirror.
REM
REM Only what the PHP app actually needs at runtime is uploaded: the list of
REM what stays local is bin\deploy-excludes.txt (one tar pattern per line) -
REM the Android/Capacitor project and npm tooling, tests, the Garmin watch
REM sources, the HA add-on, Python venvs, storage\ and every per-host file
REM such as .env and public\.user.ini. Until 2026-11-15 each deploy script
REM had its own copy of that list, and they had drifted apart (the test
REM deploy uploaded the local storage\).
REM
REM Override any of the settings by setting the env var before running, e.g.:
REM   set REMOTE_PHP=php8.3
REM   bin\deploy.bat
REM NOTE: never put an unescaped less-than, greater-than or pipe character
REM in a REM line of this file - cmd.exe parses them even in comments.

REM Delayed expansion (!var!) stays OFF on purpose: the password could
REM contain a "!" and delayed expansion would mangle it.
setlocal

set "SITE=ytan.pesr.org"
if /i "%~1"=="test" set "SITE=test.pesr.org"
call "%~dp0ssh-setup.bat"
if errorlevel 1 goto :error

REM PHP/Composer used LOCALLY to build the production vendor\ directory.
REM Plain `php`/`composer` on PATH resolve to PHP 7.4 on this machine, which
REM fails composer.json's ">=8.1" platform check, so point at the 8.x
REM install + composer.phar directly instead.
if not defined LOCAL_PHP set "LOCAL_PHP=C:\dev\php8\php.exe"
if not defined LOCAL_COMPOSER_PHAR set "LOCAL_COMPOSER_PHAR=C:\ProgramData\ComposerSetup\bin\composer.phar"

REM PHP used on the REMOTE host to run bin/migrate.php after upload (8.1+).
if not defined REMOTE_PHP set "REMOTE_PHP=php"

set "ROOT_DIR=%~dp0.."
set "BUILD_DIR=%TEMP%\ytan-deploy-%RANDOM%%RANDOM%"

echo ==^> Packaging working tree for %SITE% (without bin\deploy-excludes.txt)
mkdir "%BUILD_DIR%" || goto :error
tar -C "%ROOT_DIR%" -X "%~dp0deploy-excludes.txt" -cf - . | tar -C "%BUILD_DIR%" -xf -
if errorlevel 1 goto :error

REM Locale files travel as resources\i18n-incoming so the upload never overwrites the
REM server's resources\i18n; bin/merge-i18n.php merges them there (new keys in,
REM removed keys out, texts changed in the repository updated, server edits kept -
REM three-way against storage\i18n-baseline on the server).
if exist "%BUILD_DIR%\resources\i18n" move "%BUILD_DIR%\resources\i18n" "%BUILD_DIR%\resources\i18n-incoming" >nul
if errorlevel 1 goto :error

echo ==^> Installing production dependencies (composer install --no-dev)
"%LOCAL_PHP%" "%LOCAL_COMPOSER_PHAR%" install --no-dev --optimize-autoloader --working-dir="%BUILD_DIR%"
if errorlevel 1 goto :error

echo ==^> Uploading to %DEPLOY_USER%@%DEPLOY_HOST%:%DEPLOY_PATH% and running migrations
REM docs/user is removed first: tar only adds/overwrites, so a renamed or
REM deleted chapter (e.g. the renumbering of 2026-11-10) would otherwise stay
REM on the server and show up twice in the user guide.
tar -C "%BUILD_DIR%" -cf - . | "%DEPLOY_SSH_CLIENT%" %SSH_TARGET% "mkdir -p '%DEPLOY_PATH%' && rm -rf '%DEPLOY_PATH%/docs/user' && tar -C '%DEPLOY_PATH%' -xf - && cd '%DEPLOY_PATH%' && %REMOTE_PHP% bin/merge-i18n.php && %REMOTE_PHP% bin/migrate.php"
if errorlevel 1 goto :error

echo ==^> Deploy finished.
date /t
time /t
call "%~dp0ssh-setup.bat" cleanup
rmdir /s /q "%BUILD_DIR%" 2>nul
endlocal
exit /b 0

:error
echo ==^> Deploy FAILED.
call "%~dp0ssh-setup.bat" cleanup
if exist "%BUILD_DIR%" rmdir /s /q "%BUILD_DIR%" 2>nul
endlocal
exit /b 1
