// Stub. Original (untracked) serverTime.js was lost. This implementation
// works correctly as long as the device clock is right; the original also
// tracked a server-vs-device offset captured from API responses so it
// remained correct when the device clock was wrong. Restore from editor
// history for that drift correction.

let _offsetMs = 0;

export function noteServerTime(serverIsoString) {
  if (!serverIsoString) return;
  try {
    const serverMs = new Date(serverIsoString).getTime();
    if (Number.isFinite(serverMs)) {
      _offsetMs = serverMs - Date.now();
    }
  } catch {
    // ignore parse errors
  }
}

export function serverIsoNow() {
  return new Date(Date.now() + _offsetMs).toISOString();
}

export function parseServerTimestamp(s) {
  if (!s) return null;
  try {
    const d = new Date(s);
    return Number.isFinite(d.getTime()) ? d : null;
  } catch {
    return null;
  }
}

export function getServerOffsetMs() {
  return _offsetMs;
}
