package io.ionic.starter;

import android.os.Bundle;

import androidx.core.view.WindowCompat;

import com.getcapacitor.BridgeActivity;

import io.ionic.starter.sentinel.ModuleInstallPlugin;
import io.ionic.starter.sentinel.TextRecognitionPlugin;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Register custom plugins BEFORE the Bridge initialises. Capacitor
        // discovers @CapacitorPlugin-annotated classes automatically, but
        // programmatic registration is kept as belt-and-braces so the bridge
        // resolves the plugin even if annotation processing is disabled in
        // some build variants (minified release, etc.).
        registerPlugin(ModuleInstallPlugin.class);
        registerPlugin(TextRecognitionPlugin.class);
        super.onCreate(savedInstanceState);
        // Belt-and-braces alongside android:windowOptOutEdgeToEdgeEnforcement
        // in styles.xml. Android 15+ (SDK 35+) defaults to edge-to-edge for
        // apps targeting SDK 35+, which makes the WebView paint underneath
        // the status bar and the 3-button / gesture navigation bar.
        // Restoring the classic decor-fits-system-windows behaviour pushes
        // the WebView back into the safe area so existing content layouts
        // and the StatusBar plugin's overlaysWebView:false setting still
        // produce the expected result.
        WindowCompat.setDecorFitsSystemWindows(getWindow(), true);
    }
}
