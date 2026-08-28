@echo off
REM Deploys the current working tree to the test system over SSH.
REM
REM There is no composer on the remote host, so production dependencies are
REM built locally (in a throwaway temp copy, so the developer's own vendor\
REM with dev dependencies like phpunit is left untouched) and shipped as
REM part of the upload. Uses the native Windows tar.exe/ssh.exe (both ship
REM with Windows 10/11) piped together - no rsync/Git-Bash required.
REM
REM The remote .env is never touched: it's excluded from the package, and
REM this script never deletes anything on the remote side, it only extracts
REM on top of what's already there. That also means files removed locally
REM are NOT removed from the remote - this is a simple "copy newer files
REM over", not a mirror.
REM
REM Usage: bin\deploy.bat
REM Override any of these by setting the env var before running, e.g.:
REM   set REMOTE_PHP=php8.1
REM   bin\deploy.bat

setlocal enabledelayedexpansion

if not defined DEPLOY_USER set "DEPLOY_USER=csteindorff"
if not defined DEPLOY_HOST set "DEPLOY_HOST=steindorff.de"
if not defined DEPLOY_PORT set "DEPLOY_PORT=22"
if not defined DEPLOY_PATH set "DEPLOY_PATH=/home/www/doc/9769/ytan.pesr.org/www"

REM PHP/Composer used LOCALLY to build the production vendor\ directory.
REM Plain `php`/`composer` on PATH resolve to PHP 7.4 on this machine, which
REM fails composer.json's ">=8.1" platform check, so point at the 8.x
REM install + composer.phar directly instead.
if not defined LOCAL_PHP set "LOCAL_PHP=C:\dev\php8\php.exe"
if not defined LOCAL_COMPOSER_PHAR set "LOCAL_COMPOSER_PHAR=C:\ProgramData\ComposerSetup\bin\composer.phar"

REM PHP used on the REMOTE host to run bin/migrate.php after upload. Must be
REM 8.1+; adjust if the server's default `php` on PATH is older (shared
REM hosts often expose specific versions as e.g. php8.1/php8.2).
if not defined REMOTE_PHP set "REMOTE_PHP=php"

set "ROOT_DIR=%~dp0.."
set "BUILD_DIR=%TEMP%\ytan-deploy-%RANDOM%%RANDOM%"

echo ==^> Packaging working tree (excluding .git, .env, vendor, .claude, wsi cache)
mkdir "%BUILD_DIR%" || goto :error
tar -C "%ROOT_DIR%" ^
    --exclude=".git" ^
    --exclude=".env" ^
    --exclude=".claude" ^
    --exclude="vendor" ^
    --exclude="public/images/wsi/*.svg" ^
    -cf - . | tar -C "%BUILD_DIR%" -xf -
if errorlevel 1 goto :error

echo ==^> Installing production dependencies (composer install --no-dev)
"%LOCAL_PHP%" "%LOCAL_COMPOSER_PHAR%" install --no-dev --optimize-autoloader --working-dir="%BUILD_DIR%"
if errorlevel 1 goto :error

echo ==^> Uploading to %DEPLOY_USER%@%DEPLOY_HOST%:%DEPLOY_PATH% and running migrations
tar -C "%BUILD_DIR%" -cf - . | ssh -p %DEPLOY_PORT% %DEPLOY_USER%@%DEPLOY_HOST% "mkdir -p '%DEPLOY_PATH%' && tar -C '%DEPLOY_PATH%' -xf - && cd '%DEPLOY_PATH%' && %REMOTE_PHP% bin/migrate.php"
if errorlevel 1 goto :error

echo ==^> Deploy finished.
rmdir /s /q "%BUILD_DIR%" 2>nul
endlocal
exit /b 0

:error
echo ==^> Deploy FAILED.
if exist "%BUILD_DIR%" rmdir /s /q "%BUILD_DIR%" 2>nul
endlocal
exit /b 1
