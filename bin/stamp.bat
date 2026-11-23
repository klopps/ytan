@echo off
REM Prints the clock time and the seconds since the first call, for bin\deploy.bat
REM (called with "call", so T0 stays set in the deploy's environment; a deploy
REM across midnight shows nonsense - not worth handling). No labels in here.
for /f "tokens=1-3 delims=:.," %%a in ("%TIME: =0%") do set /a "NOW=(1%%a-100)*3600+(1%%b-100)*60+(1%%c-100)"
if not defined T0 set "T0=%NOW%"
set /a "EL=NOW-T0"
echo [%TIME:~0,8% +%EL%s] %~1
