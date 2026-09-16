package org.pesr.ytan;

import android.Manifest;
import android.content.Context;
import android.content.pm.PackageManager;
import android.location.LocationManager;
import android.os.Build;

import androidx.core.content.ContextCompat;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * App-local plugin (not an npm package, lives directly in this Capacitor
 * project) used by public/js/track-recorder.js to warn the user, before a
 * recording is started, if Android permissions the background-geolocation
 * plugin needs are missing. @capacitor-community/background-geolocation's
 * own addWatcher() only requests/checks foreground
 * ACCESS_FINE_LOCATION/ACCESS_COARSE_LOCATION (its Java source's "location"
 * permission alias) - it never checks ACCESS_BACKGROUND_LOCATION or
 * POST_NOTIFICATIONS at all, so a user who only grants "while using the
 * app" location still sails through addWatcher() and only discovers
 * recording silently stopped once the screen is locked and they're back
 * on the water. This plugin fills that gap by querying the actual Android
 * permission/settings state directly.
 */
@CapacitorPlugin(name = "LocationPermissions")
public class LocationPermissionsPlugin extends Plugin {

    @PluginMethod
    public void checkStatus(PluginCall call) {
        Context context = getContext();

        boolean fineLocation = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION)
                == PackageManager.PERMISSION_GRANTED;
        boolean coarseLocation = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION)
                == PackageManager.PERMISSION_GRANTED;
        boolean foregroundLocation = fineLocation || coarseLocation;

        // ACCESS_BACKGROUND_LOCATION only exists as a distinct, separately
        // granted permission from Android 10 (API 29) onward - on older
        // versions foreground location already implies background access.
        boolean backgroundLocation = Build.VERSION.SDK_INT < Build.VERSION_CODES.Q
                || ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_BACKGROUND_LOCATION)
                        == PackageManager.PERMISSION_GRANTED;

        // POST_NOTIFICATIONS is a runtime permission only from Android 13
        // (API 33) onward - without it the mandatory "recording" foreground
        // service notification can't be shown, which on many OEM builds
        // gets the whole background watcher killed by the system.
        boolean notifications = Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU
                || ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS)
                        == PackageManager.PERMISSION_GRANTED;

        LocationManager locationManager = (LocationManager) context.getSystemService(Context.LOCATION_SERVICE);
        boolean locationServicesEnabled = locationManager != null && locationManager.isLocationEnabled();

        JSObject result = new JSObject();
        result.put("foregroundLocation", foregroundLocation);
        result.put("backgroundLocation", backgroundLocation);
        result.put("notifications", notifications);
        result.put("locationServicesEnabled", locationServicesEnabled);
        result.put("allGranted", foregroundLocation && backgroundLocation && notifications && locationServicesEnabled);
        call.resolve(result);
    }
}
