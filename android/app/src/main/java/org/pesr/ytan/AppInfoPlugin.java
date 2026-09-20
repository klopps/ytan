package org.pesr.ytan;

import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.os.Build;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * App-local plugin (not an npm package, lives directly in this Capacitor
 * project - same convention as LocationPermissionsPlugin) exposing the
 * installed APK's own versionCode/versionName (android/app/build.gradle).
 * capacitor.config.json's server.url means the JS layer itself is always
 * fetched live from the server and thus always current, but the native
 * plugins bundled into an already-installed APK are fixed at install time
 * - public/js/capacitor-bridge.js's getAppVersionCode() uses this so the
 * live JS can detect an app-shell build too old for a feature the backend
 * now requires (see todo.md "App-Backend-Kompatibilität").
 */
@CapacitorPlugin(name = "AppInfo")
public class AppInfoPlugin extends Plugin {

    @PluginMethod
    public void getInfo(PluginCall call) {
        try {
            PackageInfo packageInfo = getContext().getPackageManager()
                    .getPackageInfo(getContext().getPackageName(), 0);

            long versionCode = (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P)
                    ? packageInfo.getLongVersionCode()
                    : packageInfo.versionCode;

            JSObject result = new JSObject();
            result.put("versionName", packageInfo.versionName);
            result.put("versionCode", versionCode);
            call.resolve(result);
        } catch (PackageManager.NameNotFoundException e) {
            call.reject("Could not read package info", e);
        }
    }
}
