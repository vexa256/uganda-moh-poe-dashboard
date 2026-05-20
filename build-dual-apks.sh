#!/usr/bin/env bash
# build-dual-apks.sh — build BOTH ECSA Uganda POE signed APKs from one tree.
#
# Produces:
#   apk-details/ug-poe-training-v<ver>-signed.apk      → con-dev2 staging
#   apk-details/ug-poe-production-v<ver>-signed.apk    → MOH production
#
# Mechanics (see memory: project_dual_apk_build_canon.md):
#   • CAP_APP_ID / CAP_APP_NAME      → consumed by capacitor.config.ts
#   • -PappIdOverride / -PappNameOverride → consumed by android/app/build.gradle
#   • VITE_SERVER_URL                → baked into dist/ by vite per-flavor
#   • applicationIds differ so both APKs install side-by-side on one device
#   • --no-daemon + low -Xmx so gradle never lingers eating RAM
#
# Idempotent. Re-uses existing apk-details/release.keystore.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APK_DIR="$ROOT/apk-details"
ANDROID_DIR="$ROOT/android"
PUBLISH_DIR="$ROOT/api/public/apks"
KEYSTORE="$APK_DIR/release.keystore"
KEYSTORE_PROPS="$ANDROID_DIR/keystore.properties"

export JAVA_HOME="${JAVA_HOME:-/usr/lib/jvm/java-21-openjdk-amd64}"
export ANDROID_HOME="${ANDROID_HOME:-$HOME/Android/Sdk}"
export ANDROID_SDK_ROOT="$ANDROID_HOME"
export PATH="$JAVA_HOME/bin:$ANDROID_HOME/cmdline-tools/latest/bin:$ANDROID_HOME/platform-tools:$PATH"

# Low-memory gradle profile — keep WSL footprint small, no lingering daemon.
export GRADLE_OPTS="-Dorg.gradle.jvmargs=-Xmx1280m -XX:MaxMetaspaceSize=384m -Dorg.gradle.daemon=false -Dorg.gradle.parallel=false -Dorg.gradle.workers.max=2"

log()  { printf "\n\033[1;36m==> %s\033[0m\n" "$*"; }
fail() { printf "\n\033[1;31m[FAIL] %s\033[0m\n" "$*" >&2; exit 1; }

[[ -f "$KEYSTORE"       ]] || fail "Keystore missing at $KEYSTORE"
[[ -f "$KEYSTORE_PROPS" ]] || fail "keystore.properties missing at $KEYSTORE_PROPS"

VERSION="$(grep -oP 'versionName\s+"\K[^"]+' "$ANDROID_DIR/app/build.gradle" || echo 1.0.0)"
log "Building Uganda POE dual APKs — versionName=$VERSION"

# Single npm install for both flavors.
log "npm install (shared dependencies)"
cd "$ROOT"
npm install --no-audit --no-fund --loglevel=error >/dev/null

build_one() {
  local FLAVOR_LABEL="$1"   # e.g. "Training"
  local APP_ID="$2"         # applicationId for this APK
  local APP_NAME="$3"       # user-visible name
  local SERVER_URL="$4"     # baked into JS bundle as VITE_SERVER_URL
  local OUT_NAME="$5"       # final filename in apk-details/

  log "[$FLAVOR_LABEL] vite build → SERVER=$SERVER_URL"
  rm -rf "$ROOT/dist"
  VITE_SERVER_URL="$SERVER_URL" VITE_COUNTRY_CODE=UG \
    npx --no-install vite build >/dev/null

  log "[$FLAVOR_LABEL] cap sync android → appId=$APP_ID  appName=\"$APP_NAME\""
  CAP_APP_ID="$APP_ID" CAP_APP_NAME="$APP_NAME" \
    npx --no-install cap sync android >/dev/null

  log "[$FLAVOR_LABEL] gradle clean + assembleRelease (low-memory, signed in-gradle)"
  cd "$ANDROID_DIR"
  ./gradlew --stop >/dev/null 2>&1 || true
  ./gradlew \
    -PappIdOverride="$APP_ID" \
    -PappNameOverride="$APP_NAME" \
    clean assembleRelease \
    --no-daemon -x lintVitalRelease --console=plain --warning-mode=summary \
    2>&1 | tail -8
  cd "$ROOT"

  local SIGNED_SRC="$ANDROID_DIR/app/build/outputs/apk/release/app-release.apk"
  [[ -f "$SIGNED_SRC" ]] || fail "[$FLAVOR_LABEL] expected signed APK not produced at $SIGNED_SRC"

  local DEST="$APK_DIR/$OUT_NAME"
  cp "$SIGNED_SRC" "$DEST"

  log "[$FLAVOR_LABEL] verifying signature"
  local APKSIGNER
  APKSIGNER="$(find "$ANDROID_HOME/build-tools" -maxdepth 2 -name apksigner -executable 2>/dev/null | sort -V | tail -1)"
  [[ -x "$APKSIGNER" ]] || fail "apksigner not found under $ANDROID_HOME/build-tools"
  "$APKSIGNER" verify "$DEST" || fail "[$FLAVOR_LABEL] signature verification failed"

  # Reclaim disk + RAM before next flavor.
  ./gradlew --stop >/dev/null 2>&1 || true
  rm -rf "$ANDROID_DIR/.gradle" "$ANDROID_DIR/app/build" "$ANDROID_DIR/build"

  printf "\033[1;32m[OK] %s → %s (%s)\033[0m\n" "$FLAVOR_LABEL" "$DEST" "$(du -h "$DEST" | cut -f1)"
}

# Pre-flight: nuke any stale APKs anywhere in the repo.
log "Pre-flight: removing all *.apk / *.idsig files in repo"
find "$ROOT" -name "*.apk"       -not -path "*/node_modules/*" -print -delete
find "$ROOT" -name "*.apk.idsig" -not -path "*/node_modules/*" -print -delete

# Build #1 — Training (con-dev2 staging)
build_one "Training" \
  "ug.moh.poesentinel.training" \
  "Training Uganda POE Screening" \
  "https://ug-poe.ecsahc.com/api" \
  "ug-poe-training-v${VERSION}-signed.apk"

# Build #2 — Production (MOH live)
build_one "Production" \
  "ug.moh.poesentinel" \
  "Uganda POE Screening" \
  "https://poes.health.go.ug/api" \
  "ug-poe-production-v${VERSION}-signed.apk"

# Publish — copy both into api/public/apks so they're downloadable from
# https://ug-poe.ecsahc.com/apks/<file> once con-dev2 syncs from main.
log "Publishing APKs → $PUBLISH_DIR"
mkdir -p "$PUBLISH_DIR"
cp "$APK_DIR/ug-poe-training-v${VERSION}-signed.apk"   "$PUBLISH_DIR/"
cp "$APK_DIR/ug-poe-production-v${VERSION}-signed.apk" "$PUBLISH_DIR/"

# Final cleanup: no lingering gradle, no .env clobber.
log "Final cleanup — stop gradle, clear caches"
cd "$ANDROID_DIR"; ./gradlew --stop >/dev/null 2>&1 || true
rm -rf "$ANDROID_DIR/.gradle" "$ANDROID_DIR/app/build" "$ANDROID_DIR/build"

log "Summary"
ls -lh "$APK_DIR"/ug-poe-*-signed.apk
echo
ls -lh "$PUBLISH_DIR"/ug-poe-*-signed.apk
echo
log "Checksums (sha256)"
sha256sum "$APK_DIR"/ug-poe-*-signed.apk
