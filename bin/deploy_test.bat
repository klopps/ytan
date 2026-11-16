@echo off
REM Deploys to the test system (test.pesr.org) - the same as "bin\deploy.bat test".
REM Kept as its own file because it is the command people (and the "cpdtest"
REM shortcut) know; everything else lives in bin\deploy.bat.
call "%~dp0deploy.bat" test %*
exit /b %errorlevel%
