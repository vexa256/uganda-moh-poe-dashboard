import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'ug.moh.poesentinel',
  appName: 'Uganda POE Screening',
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
