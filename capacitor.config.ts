import type { CapacitorConfig } from '@capacitor/cli';

// Dual-flavor build canon (see memory: project_dual_apk_build_canon.md).
// CAP_APP_ID / CAP_APP_NAME let build-dual-apks.sh produce Training and
// Production APKs from one source tree. Defaults preserve production.
const config: CapacitorConfig = {
  appId: process.env.CAP_APP_ID || 'ug.moh.poesentinel',
  appName: process.env.CAP_APP_NAME || 'Uganda POE Screening',
  webDir: 'dist',

  // Background that matches splash + status bar — prevents white flash
  // between native splash hiding and WebView loading.
  backgroundColor: '#0B2545',

  android: {
    // Verbose Capacitor bridge logging in adb logcat — only effective on
    // debuggable APKs (release builds have logging stripped by R8). Pair
    // with `adb logcat -s Capacitor:V Console:V SystemWebViewClient:V` to
    // see WebView console + JS bridge calls live during phone debugging.
    loggingBehavior: 'debug',
    webContentsDebuggingEnabled: true,
  },

  plugins: {
    // Self-hosted Capgo OTA — every JS/HTML/CSS-only change ships via
    // `capgo publish` instead of rebuilding the APK. Native code changes
    // (capacitor plugins, gradle deps) still need a fresh APK build.
    //
    // The backend at updates.ecsahc.com is keyed on cap_app_id from this
    // config, so Training (ug.moh.poesentinel.training) and Production
    // (ug.moh.poesentinel) get separately-versioned bundle streams
    // automatically — no extra config needed here.
    //
    // notifyAppReady() MUST fire inside appReadyTimeout (10 s) or the
    // plugin rolls back to the previous bundle. main.ts calls it right
    // after app.mount() in router.isReady().then(...).
    CapacitorUpdater: {
      autoUpdate:         true,
      autoDeleteFailed:   true,
      autoDeletePrevious: true,
      resetWhenUpdate:    true,
      appReadyTimeout:    10000,
      responseTimeout:    20,
      updateUrl:          'https://updates.ecsahc.com/api/v1/updates',
      statsUrl:           'https://updates.ecsahc.com/api/v1/stats',
      channelUrl:         'https://updates.ecsahc.com/api/v1/channel',
    },
    SplashScreen: {
      // Android 12+ system splash is always shown by the OS; this config
      // tunes the short post-launch splash screen Capacitor displays while
      // the WebView boots.
      launchShowDuration: 2000,
      launchAutoHide: true,
      launchFadeOutDuration: 250,
      backgroundColor: '#0B2545',
      androidSplashResourceName: 'splash',
      androidScaleType: 'CENTER_CROP',
      showSpinner: true,
      androidSpinnerStyle: 'large',
      spinnerColor: '#00B4A6',
      splashFullScreen: false,
      splashImmersive: false,
    },
    StatusBar: {
      // Deep navy bar (#0B2545). Capacitor StatusBar.Style.Light selects
      // LIGHT-coloured icons (clock, battery, signal) — i.e. the foreground
      // is light, intended for use on a dark background. The previous value
      // 'DARK' produced dark icons on the dark navy bar — practically
      // invisible. Don't confuse Style with bar tint: Style names the
      // foreground, not the bar.
      style: 'LIGHT',
      backgroundColor: '#0B2545',
      // overlaysWebView=false → Android reserves the status-bar height
      // outside the WebView, so the WebView starts below the inset and
      // content can never sit under the clock. ion-header still applies
      // --ion-safe-area-top defensively for landscape / notch / iOS.
      overlaysWebView: false,
    },
    Keyboard: {
      // 'ionic' mode lets Ionic manage keyboard insets via --keyboard-offset
      // instead of resizing the WebView viewport. 'native' caused a stuck
      // white gap on Android when the IME animated away faster than the
      // WebView resize could repaint.
      resize: 'ionic',
      resizeOnFullScreen: true,
    },
  },
};

export default config;
