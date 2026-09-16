package org.pesr.ytan;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        // LocationPermissionsPlugin is app-local source (not an npm
        // package under node_modules), so unlike @capacitor-community/
        // background-geolocation it isn't auto-registered by `cap sync` -
        // must be registered here before super.onCreate().
        registerPlugin(LocationPermissionsPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
