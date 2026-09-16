@echo off
REM Builds the signed Android release variant of the Capacitor app shell
REM (android/) from the current working tree: a Play-Store-ready .aab plus
REM a plain, directly installable .apk (also copied to a version-named
REM filename, e.g. ytan_0.5.0.apk, for easy sideloading/sharing).
REM
REM Steps: npm install (Capacitor CLI + @capacitor-community/
REM background-geolocation), then npx cap sync android (pushes
REM capacitor.config.json and npm-package native plugins into android/ -
REM app-local plugins like LocationPermissionsPlugin.java already live
REM directly in android/app/src, so they need no sync step), then gradlew
REM bundleRelease assembleRelease, using JDK 21 explicitly.
REM
REM NOTE: a REM comment line is not fully inert in cmd.exe - a literal
REM "<", ">" or "|" in one is still parsed as real redirection/piping
REM before the line is recognized as a comment. Never put those characters
REM (unescaped) in a REM line in this file - confirmed live: an earlier
REM draft's "->" arrows in the comments above silently created empty
REM "gradlew"/"npx" files in the repo root on every run.
REM
REM JDK 21, NOT Android Studio's bundled JBR: see todo.md "Capacitor-
REM App-Huelle fuer Android" - JBR 25 broke Gradle's own Groovy compiler
REM ("Unsupported class file major version 69") the moment build.gradle
REM actually changes and needs recompiling, confirmed live. Builds where
REM build.gradle happens to be unchanged can silently get away with JBR
REM because Gradle reuses its cached compiled version - this script always
REM forces JDK 21 so that isn't a trap that depends on what changed.
REM
REM Requires android\keystore.properties (gitignored - see its own comment
REM next to /android/keystore.properties in .gitignore) already pointing at
REM a real release keystore. This script does not create one.
REM
REM Usage: bin\build-app-release.bat
REM Override any of these by setting the env var before running, e.g.:
REM   set ANDROID_BUILD_JDK_HOME=C:\some\other\jdk-21
REM   bin\build-app-release.bat

setlocal

REM %%~fI collapses "...\bin\.." down to the real absolute path, purely
REM so the summary printed at the end reads cleanly - functionally
REM "%~dp0.." would have worked everywhere else too.
for %%I in ("%~dp0..") do set "ROOT_DIR=%%~fI"

if not exist "%ROOT_DIR%\android\keystore.properties" (
    echo ==^> android\keystore.properties not found - the release build needs a
    echo      signing keystore configured there (storeFile/storePassword/keyAlias/
    echo      keyPassword^), read by android\app\build.gradle's signingConfigs.release.
    echo      See .gitignore's comment next to /android/keystore.properties for why
    echo      it isn't checked into the repo. Nothing was built.
    goto :error
)

if not defined ANDROID_BUILD_JDK_HOME set "ANDROID_BUILD_JDK_HOME=C:\Program Files\Eclipse Adoptium\jdk-21.0.12.101-hotspot"
if not exist "%ANDROID_BUILD_JDK_HOME%\bin\java.exe" (
    echo ==^> ANDROID_BUILD_JDK_HOME ^(%ANDROID_BUILD_JDK_HOME%^) has no bin\java.exe -
    echo      set it to a real JDK 21 install before running ^(see comment above
    echo      about Android Studio's bundled JBR breaking release builds^).
    goto :error
)
set "JAVA_HOME=%ANDROID_BUILD_JDK_HOME%"

pushd "%ROOT_DIR%" || goto :error

echo ==^> Installing npm dependencies (Capacitor CLI + plugins)
call npm install
if errorlevel 1 goto :error_in_root

echo ==^> Syncing web assets/config/plugins into the native Android project (cap sync android)
call node_modules\.bin\cap.cmd sync android
if errorlevel 1 goto :error_in_root

echo ==^> Building signed release .aab + .apk (JAVA_HOME=%JAVA_HOME%)
pushd android || goto :error_in_root
call .\gradlew.bat :app:bundleRelease :app:assembleRelease
if errorlevel 1 (
    popd
    goto :error_in_root
)
popd

REM Read versionName back out of build.gradle rather than hardcoding it,
REM so the renamed .apk always matches whatever version was actually built.
set "VERSION_NAME="
for /f "tokens=2 delims=	 " %%V in ('findstr /r "versionName" "android\app\build.gradle"') do set "VERSION_NAME=%%~V"

set "APK_DIR=android\app\build\outputs\apk\release"
set "AAB_DIR=android\app\build\outputs\bundle\release"
if defined VERSION_NAME (
    copy /y "%APK_DIR%\app-release.apk" "%APK_DIR%\ytan_%VERSION_NAME%.apk" >nul
)

echo.
echo ==^> Release build finished (version %VERSION_NAME%).
echo      AAB (Play Store upload):        %ROOT_DIR%\%AAB_DIR%\app-release.aab
echo      APK (direct/sideload install):  %ROOT_DIR%\%APK_DIR%\app-release.apk
if defined VERSION_NAME echo                                       %ROOT_DIR%\%APK_DIR%\ytan_%VERSION_NAME%.apk

popd
endlocal
exit /b 0

:error_in_root
popd

:error
echo ==^> Release build FAILED.
endlocal
exit /b 1
