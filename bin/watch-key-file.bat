@echo off
REM Writes the settings file (.SET) that gives the YTAN data field on a
REM sideloaded watch its watch key - no new build needed, see
REM bin\watch-key-file.php.
REM
REM   bin\watch-key-file.bat WATCH-KEY [NAME]
REM
REM   WATCH-KEY  the key from YTAN: Profile, Garmin watch, Create watch key
REM   NAME       name of the settings file the watch created for the data
REM              field in GARMIN\Apps\SETTINGS after its first start, without .SET (the
REM              name of the first installed .prg, e.g. ytan-fenix7pro;
REM              default ytan-fenix7x)
REM
REM Result: watch\bin\NAME.SET - copy it into the watch's GARMIN\Apps\SETTINGS
REM folder (replace the existing file), then start the activity again.

setlocal
for %%I in ("%~dp0..") do set "ROOT_DIR=%%~fI"
if not defined LOCAL_PHP set "LOCAL_PHP=C:\dev\php8\php.exe"
"%LOCAL_PHP%" "%ROOT_DIR%\bin\watch-key-file.php" %*
endlocal
exit /b %ERRORLEVEL%
