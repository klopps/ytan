@echo off
REM Shows the YTAN watch data field in the Connect IQ simulator.
REM
REM   bin\sim-watch.bat [DEVICE]      default fenix7pro
REM
REM Builds a simulator-only variant (watch\sim.jungle, output
REM watch\bin\simulator\ytan-DEVICE-sim.prg) in which the data field, lacking a GPS
REM fix, fast-forwards a simulated position along the real route - then
REM starts the simulator (if not already running) and loads it. Uses the
REM watch key from the last bin\build-watch.bat run (watch\source\Config.mc).
REM This window stays open while the data field runs and shows its log;
REM Ctrl+C ends it. Works from cmd and PowerShell alike.
REM
REM NOTE: never put an unescaped less-than, greater-than or pipe character
REM in a REM line of this file - cmd.exe parses them even in comments.

setlocal

for %%I in ("%~dp0..") do set "ROOT_DIR=%%~fI"

set "DEVICE=%~1"
if "%DEVICE%"=="" set "DEVICE=fenix7pro"
REM Own folder, so it can't be mistaken for the watch build in watch\bin.
set "PRG=%ROOT_DIR%\watch\bin\simulator\ytan-%DEVICE%-sim.prg"
if not exist "%ROOT_DIR%\watch\source\Config.mc" (
    echo ==^> watch\source\Config.mc not found - build once with your watch key:
    echo      bin\build-watch.bat WATCH-KEY %DEVICE%
    goto :error
)

if not defined GARMIN_DEVELOPER_KEY (
    if exist "%USERPROFILE%\.garmin\developer_key" (
        set "GARMIN_DEVELOPER_KEY=%USERPROFILE%\.garmin\developer_key"
    ) else (
        set "GARMIN_DEVELOPER_KEY=%USERPROFILE%\.garmin\developer_key.der"
    )
)

if not defined CIQ_SDK_HOME (
    if exist "%APPDATA%\Garmin\ConnectIQ\current-sdk.cfg" (
        set /p CIQ_SDK_HOME=<"%APPDATA%\Garmin\ConnectIQ\current-sdk.cfg"
    )
)
if not defined CIQ_SDK_HOME (
    echo ==^> Connect IQ SDK not found - install it with Garmin's SDK Manager.
    goto :error
)
if "%CIQ_SDK_HOME:~-1%"=="\" set "CIQ_SDK_HOME=%CIQ_SDK_HOME:~0,-1%"


tasklist /fi "imagename eq simulator.exe" | find /i "simulator.exe" 1>nul 2>nul
if errorlevel 1 (
    echo ==^> Starting the simulator
    start "" "%CIQ_SDK_HOME%\bin\simulator.exe"
    REM Give it time to open before loading the app. ping instead of
    REM timeout, which aborts when the console has no keyboard input.
    ping -n 7 127.0.0.1 1>nul
)

echo ==^> Loading %PRG% on %DEVICE% (Ctrl+C to stop)
call "%CIQ_SDK_HOME%\bin\monkeydo.bat" "%PRG%" %DEVICE%
endlocal
exit /b 0

:error
endlocal
exit /b 1
