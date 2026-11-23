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
REM The remote .env is never touched: it's excluded from the package. The
REM upload is extracted on top of what's already there; afterwards
REM bin/deploy-prune.sh (on the server) deletes the files an EARLIER deploy
REM uploaded that this one no longer contains (removed/renamed in the
REM repository, dropped Composer packages). It compares the file list tar
REM prints while extracting with the one kept from the last deploy in
REM .deploy-manifest - anything no deploy ever uploaded (storage, .env,
REM public/.user.ini, public/app, caches, venvs) is never in that list and so
REM never deleted. Until 2026-11-17 nothing was deleted at all and leftovers
REM piled up on the servers.
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

call "%~dp0stamp.bat" "start"
echo ==^> Packaging working tree for %SITE% (without bin\deploy-excludes.txt)
mkdir "%BUILD_DIR%" || goto :error
tar -C "%ROOT_DIR%" -X "%~dp0deploy-excludes.txt" -cf - . | tar -C "%BUILD_DIR%" -xf -
if errorlevel 1 goto :error
call "%~dp0stamp.bat" "packaged"

REM Locale files travel as resources\i18n-incoming so the upload never overwrites the
REM server's resources\i18n; bin/merge-i18n.php merges them there (new keys in,
REM removed keys out, texts changed in the repository updated, server edits kept -
REM three-way against storage\i18n-baseline on the server).
if exist "%BUILD_DIR%\resources\i18n" move "%BUILD_DIR%\resources\i18n" "%BUILD_DIR%\resources\i18n-incoming" >nul
if errorlevel 1 goto :error

REM The production vendor\ only changes with composer.json / composer.lock, but
REM building it takes about 2 minutes on this machine (5,800 files). The finished
REM directory is therefore kept under %LOCALAPPDATA%\ytan-deploy-cache, named by
REM a hash of both files, and copied from there while they are unchanged.
REM The optimized class map inside it also lists the application's own classes
REM as of the build. It is deliberately NOT regenerated on a cache hit (composer
REM dump-autoload --optimize rescans all 5,800 files and was the slowest step):
REM a class added since is found through the PSR-4 rule (the autoloader is not
REM classmap-authoritative), and a class file that is gone is gone from the
REM code too. Set YTAN_NO_VENDOR_CACHE=1 to build from scratch (and refresh the cache).
set "CACHE_ROOT=%LOCALAPPDATA%\ytan-deploy-cache"
set "VENDOR_KEY="
set "HASH_JSON="
set "HASH_LOCK="
for /f "skip=1 tokens=1" %%H in ('certutil -hashfile "%ROOT_DIR%\composer.json" SHA256') do if not defined HASH_JSON set "HASH_JSON=%%H"
for /f "skip=1 tokens=1" %%H in ('certutil -hashfile "%ROOT_DIR%\composer.lock" SHA256') do if not defined HASH_LOCK set "HASH_LOCK=%%H"
if defined HASH_JSON if defined HASH_LOCK set "VENDOR_KEY=%HASH_JSON:~0,16%%HASH_LOCK:~0,16%"
set "VENDOR_CACHE=%CACHE_ROOT%\vendor-%VENDOR_KEY%"

set "VENDOR_HIT="
if defined VENDOR_KEY if not defined YTAN_NO_VENDOR_CACHE if exist "%VENDOR_CACHE%\autoload.php" set "VENDOR_HIT=1"

REM No label is jumped to from here on (goto/call :label) on purpose: this file
REM has Unix line endings and cmd.exe then finds labels unreliably depending on
REM their byte offset - a goto :vendor_ready once landed in the wrong place and
REM the deploy ran its upload after the cleanup. Hence if/else and stamp.bat.
REM Does the server already have exactly this vendor/? Every upload that brings
REM a vendor/ also brings vendor\.ytan-vendor-key (= the cache key); if the
REM server's copy says the same, vendor/ is neither copied here (11-14 s) nor
REM hashed on the server (sha1sum over 5,800 files). Needs the cached hashes
REM (its file list goes into .deploy-full.list). YTAN_FULL_UPLOAD=1 skips this.
set "VENDOR_SAME="
set "SERVER_KEY="
if defined VENDOR_HIT if not defined YTAN_FULL_UPLOAD if exist "%VENDOR_CACHE%\.vendor-sha1" "%DEPLOY_SSH_CLIENT%" %SSH_TARGET% "cat '%DEPLOY_PATH%/vendor/.ytan-vendor-key' 2>/dev/null" > "%BUILD_DIR%.key" < nul
if exist "%BUILD_DIR%.key" set /p SERVER_KEY=<"%BUILD_DIR%.key"
del "%BUILD_DIR%.key" 2>nul
if defined SERVER_KEY if "%SERVER_KEY%"=="%VENDOR_KEY%" set "VENDOR_SAME=1"

if defined VENDOR_SAME echo ==^> The server has this vendor\ already - not copied
if defined VENDOR_HIT if not defined VENDOR_SAME (
    echo ==^> Production dependencies unchanged - using the cached vendor\
    robocopy "%VENDOR_CACHE%" "%BUILD_DIR%\vendor" /E /XF .vendor-sha1 /NFL /NDL /NJH /NJS /NP >nul
    if errorlevel 8 goto :error
)
if not defined VENDOR_HIT (
    echo ==^> Installing production dependencies, composer install --no-dev
    "%LOCAL_PHP%" "%LOCAL_COMPOSER_PHAR%" install --no-dev --optimize-autoloader --working-dir="%BUILD_DIR%"
    if errorlevel 1 goto :error
)

REM After a fresh build keep this vendor\ for the next deploy (copied under a
REM temporary name and renamed, so an interrupted copy is never taken for a
REM finished one); older cache entries are dropped.
if not defined VENDOR_HIT if defined VENDOR_KEY (
    if exist "%VENDOR_CACHE%" rmdir /s /q "%VENDOR_CACHE%"
    if exist "%VENDOR_CACHE%.tmp" rmdir /s /q "%VENDOR_CACHE%.tmp"
    robocopy "%BUILD_DIR%\vendor" "%VENDOR_CACHE%.tmp" /E /NFL /NDL /NJH /NJS /NP >nul
    if not errorlevel 8 (
        ren "%VENDOR_CACHE%.tmp" "vendor-%VENDOR_KEY%"
        for /d %%D in ("%CACHE_ROOT%\vendor-*") do if /i not "%%~nxD"=="vendor-%VENDOR_KEY%" rmdir /s /q "%%D"
    )
)
REM The key file travels inside vendor/ (written without a line break).
if not defined VENDOR_SAME if defined VENDOR_KEY <nul set /p "=%VENDOR_KEY%"> "%BUILD_DIR%\vendor\.ytan-vendor-key"
call "%~dp0stamp.bat" "vendor ready"

REM Only files that differ from the server are uploaded (until 2026-11-23 all
REM ~6,500 went up every time: 2 of 2.5 minutes for a small change). 1) a
REM manifest of the package (sha1 per file, bin\deploy-manifest.php); 2) it is
REM piped to the server, where sha1sum -c prints the files that are missing or
REM different there - no state file, so a hand-edited or deleted server file is
REM repaired too; "*FULL*" (no deploy directory yet, no sha1sum, the call failed,
REM YTAN_FULL_UPLOAD=1) = upload everything; 3) tar -T packs just those, plus
REM resources\i18n-incoming and .deploy-full.list (the complete file list, which
REM bin/deploy-prune.sh turns into the new .deploy-manifest, deleting what
REM dropped out since the last deploy - e.g. renamed user guide chapters).
set "MANIFEST=%BUILD_DIR%.manifest"
set "RAW=%BUILD_DIR%.raw"
set "UPLOAD_LIST=%BUILD_DIR%.upload"
del "%RAW%" 2>nul
REM vendor/'s hashes live inside the vendor cache (hashing its 5,800 files takes
REM ~100 s here); the file is not part of the package: robocopy skips it below.
set "VENDOR_HASHES=-"
if defined VENDOR_KEY set "VENDOR_HASHES=%VENDOR_CACHE%\.vendor-sha1"
"%LOCAL_PHP%" "%~dp0deploy-manifest.php" manifest "%BUILD_DIR%" "%MANIFEST%" "%VENDOR_HASHES%"
if errorlevel 1 goto :error
set "COMPARE=%MANIFEST%"
if defined VENDOR_SAME set "COMPARE=%MANIFEST%.app"
if defined YTAN_FULL_UPLOAD (
    echo *FULL*> "%RAW%"
) else (
    type "%COMPARE%" | "%DEPLOY_SSH_CLIENT%" %SSH_TARGET% "cd '%DEPLOY_PATH%' 2>/dev/null && command -v sha1sum >/dev/null 2>&1 && sha1sum -c 2>/dev/null | sed -n 's/: FAILED.*$//p' || echo '*FULL*'" > "%RAW%"
    if errorlevel 1 echo *FULL*> "%RAW%"
)
"%LOCAL_PHP%" "%~dp0deploy-manifest.php" select "%BUILD_DIR%" "%MANIFEST%" "%RAW%" "%UPLOAD_LIST%"
if errorlevel 1 goto :error
call "%~dp0stamp.bat" "compared with the server"

echo ==^> Uploading to %DEPLOY_USER%@%DEPLOY_HOST%:%DEPLOY_PATH% and running migrations
REM The date +%%T calls print the SERVER's clock between the remote steps, so a
REM slow step shows up in the output (upload+extract, prune, i18n merge, migrate).
tar -C "%BUILD_DIR%" -cf - -T "%UPLOAD_LIST%" | "%DEPLOY_SSH_CLIENT%" %SSH_TARGET% "date +'remote start %%T' && mkdir -p '%DEPLOY_PATH%' && tar -C '%DEPLOY_PATH%' -xf - && date +'remote extracted %%T' && cd '%DEPLOY_PATH%' && sh bin/deploy-prune.sh .deploy-full.list && date +'remote pruned %%T' && %REMOTE_PHP% bin/merge-i18n.php && date +'remote i18n merged %%T' && %REMOTE_PHP% bin/migrate.php && date +'remote migrated %%T'"
if errorlevel 1 goto :error
del "%MANIFEST%" "%MANIFEST%.app" "%RAW%" "%UPLOAD_LIST%" 2>nul
call "%~dp0stamp.bat" "uploaded and finished on the server"

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
del "%BUILD_DIR%.manifest" "%BUILD_DIR%.manifest.app" "%BUILD_DIR%.raw" "%BUILD_DIR%.upload" "%BUILD_DIR%.key" 2>nul
endlocal
exit /b 1
