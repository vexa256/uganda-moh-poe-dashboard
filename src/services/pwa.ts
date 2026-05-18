// PWA install/standalone helpers. Functional stub written to replace the
// original (untracked) pwa.ts that was lost during a git stash/checkout
// dance in the IDSR verification session. Restore the original from editor
// history when convenient.
//
// Surface (used by App.vue + main.ts):
//   initPwa()                — capture beforeinstallprompt, dispatch events
//   isIos()                  — iOS UA detection
//   isStandalone()           — running as installed PWA
//   shouldShowIosInstallHint() — local-flag check (true unless dismissed)
//   dismissIosInstallHint()  — persist dismissal
//   canPromptInstall()       — Android Chrome/Edge: deferred prompt captured
//   promptInstall()          — fire deferred prompt; returns outcome string
//
// Custom events dispatched on window:
//   'poe:install-available'  — beforeinstallprompt captured
//   'poe:installed'          — appinstalled fired

const IOS_HINT_KEY = 'poe.pwa.ios_hint_dismissed';

let _deferredPrompt: any = null;
let _initialized = false;

function _isClient(): boolean {
  return typeof window !== 'undefined' && typeof document !== 'undefined';
}

function _isCapacitor(): boolean {
  if (!_isClient()) return false;
  const w: any = window as any;
  return !!(w.Capacitor?.isNativePlatform?.() || w.Capacitor);
}

export function isIos(): boolean {
  if (!_isClient()) return false;
  const ua = navigator.userAgent || '';
  // iPad on iOS 13+ reports as Mac — also detect via maxTouchPoints
  const isIpadOnMac = /Macintosh/.test(ua) && (navigator as any).maxTouchPoints > 1;
  return /iPhone|iPad|iPod/i.test(ua) || isIpadOnMac;
}

export function isStandalone(): boolean {
  if (!_isClient()) return false;
  try {
    if ((navigator as any).standalone === true) return true; // iOS Safari
    if (window.matchMedia?.('(display-mode: standalone)').matches) return true;
    if (window.matchMedia?.('(display-mode: fullscreen)').matches) return true;
  } catch {
    // ignore
  }
  return false;
}

export function shouldShowIosInstallHint(): boolean {
  if (!_isClient()) return false;
  try {
    return localStorage.getItem(IOS_HINT_KEY) !== '1';
  } catch {
    return true;
  }
}

export function dismissIosInstallHint(): void {
  if (!_isClient()) return;
  try {
    localStorage.setItem(IOS_HINT_KEY, '1');
  } catch {
    // storage may be blocked in private mode — silent
  }
}

export function canPromptInstall(): boolean {
  return _deferredPrompt != null;
}

export async function promptInstall(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
  if (!_deferredPrompt) return 'unavailable';
  try {
    _deferredPrompt.prompt?.();
    const choice = await _deferredPrompt.userChoice;
    _deferredPrompt = null;
    return choice?.outcome === 'accepted' ? 'accepted' : 'dismissed';
  } catch {
    _deferredPrompt = null;
    return 'unavailable';
  }
}

export function initPwa(): void {
  if (_initialized) return;
  _initialized = true;
  if (!_isClient()) return;
  if (_isCapacitor()) return; // native shell — never register browser SW

  try {
    window.addEventListener('beforeinstallprompt', (e: any) => {
      try { e.preventDefault?.(); } catch { /* ignore */ }
      _deferredPrompt = e;
      try { window.dispatchEvent(new CustomEvent('poe:install-available')); } catch { /* ignore */ }
    });

    window.addEventListener('appinstalled', () => {
      _deferredPrompt = null;
      try { window.dispatchEvent(new CustomEvent('poe:installed')); } catch { /* ignore */ }
    });
  } catch {
    // listener wiring failed — silent
  }

  // The real module also registered /sw.js. The SW file is missing in this
  // working tree (also untracked + lost), so registration is intentionally
  // omitted here. Restore /public/sw.js and re-add navigator.serviceWorker
  // registration when convenient.
}
