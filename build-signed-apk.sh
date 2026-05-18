#!/usr/bin/env bash
# Build a signed release APK for the ECSA PoE 2026 Capacitor app.
# Idempotent: creates the keystore once, re-uses it on subsequent runs.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APK_DIR="$ROOT/apk-details"
ANDROID_DIR="$ROOT/android"
KEYSTORE="$APK_DIR/release.keystore"
KEY_ALIAS="release"
KEY_PASS="256@__Kamukama"
STORE_PASS="256@__Kamukama"
KEY_DNAME="CN=ECSA PoE 2026, OU=Mobile, O=UnitedNations, L=Unknown, ST=Unknown, C=US"

export JAVA_HOME="${JAVA_HOME:-/usr/lib/jvm/java-21-openjdk-amd64}"
export ANDROID_HOME="${ANDROID_HOME:-$HOME/Android/Sdk}"
export ANDROID_SDK_ROOT="$ANDROID_HOME"
BUILD_TOOLS_DIR="$ANDROID_HOME/build-tools/36.0.0"
export PATH="$JAVA_HOME/bin:$ANDROID_HOME/cmdline-tools/latest/bin:$ANDROID_HOME/platform-tools:$BUILD_TOOLS_DIR:$PATH"

log()  { printf "\n\033[1;36m==> %s\033[0m\n" "$*"; }
fail() { printf "\n\033[1;31m[FAIL] %s\033[0m\n" "$*" >&2; exit 1; }

log "Checking toolchain"
command -v java           >/dev/null || fail "java missing"
command -v keytool        >/dev/null || fail "keytool missing"
[[ -x "$BUILD_TOOLS_DIR/zipalign"   ]] || fail "zipalign missing ($BUILD_TOOLS_DIR)"
[[ -x "$BUILD_TOOLS_DIR/apksigner"  ]] || fail "apksigner missing ($BUILD_TOOLS_DIR)"
java -version 2>&1 | head -1

mkdir -p "$APK_DIR"

log "1/6  Ensuring release keystore at $KEYSTORE"
if [[ ! -f "$KEYSTORE" ]]; then
  keytool -genkeypair -v \
    -keystore "$KEYSTORE" \
    -alias "$KEY_ALIAS" \
    -keyalg RSA -keysize 2048 -validity 10000 \
    -storepass "$STORE_PASS" -keypass "$KEY_PASS" \
    -dname "$KEY_DNAME"
  chmod 600 "$KEYSTORE"
  log "Keystore created."
else
  log "Keystore already exists — reusing."
fi

log "2/6  npm install (ensures @capacitor/android present)"
cd "$ROOT"
npm install --no-audit --no-fund --loglevel=error

log "3/6  Building web assets (vite build)"
npx vite build

log "4/6  Capacitor sync android"
if [[ ! -d "$ANDROID_DIR" ]]; then
  npx cap add android
  echo "sdk.dir=$ANDROID_HOME" > "$ANDROID_DIR/local.properties"
fi
[[ -f "$ANDROID_DIR/local.properties" ]] || echo "sdk.dir=$ANDROID_HOME" > "$ANDROID_DIR/local.properties"
npx cap sync android

log "5/6  Gradle assembleRelease (unsigned)"
cd "$ANDROID_DIR"
./gradlew clean assembleRelease --no-daemon -x lintVitalRelease

UNSIGNED="$ANDROID_DIR/app/build/outputs/apk/release/app-release-unsigned.apk"
[[ -f "$UNSIGNED" ]] || fail "unsigned release APK not produced at $UNSIGNED"

ALIGNED="$APK_DIR/app-release-aligned.apk"
SIGNED="$APK_DIR/app-release-signed.apk"
rm -f "$ALIGNED" "$SIGNED" "$SIGNED.idsig"

log "6/6  zipalign + apksigner (v1 + v2 + v3)"
zipalign -v -p 4 "$UNSIGNED" "$ALIGNED" | tail -5

apksigner sign \
  --ks "$KEYSTORE" \
  --ks-key-alias "$KEY_ALIAS" \
  --ks-pass "pass:$STORE_PASS" \
  --key-pass "pass:$KEY_PASS" \
  --v1-signing-enabled true \
  --v2-signing-enabled true \
  --v3-signing-enabled true \
  --out "$SIGNED" \
  "$ALIGNED"

log "Verifying signed APK"
apksigner verify --verbose --print-certs "$SIGNED" | head -30

log "Done"
APK_SIZE=$(du -h "$SIGNED" | cut -f1)
SHA256=$(sha256sum "$SIGNED" | awk '{print $1}')
cat <<EOF

=============== SIGNED APK ===============
Path:   $SIGNED
Size:   $APK_SIZE
SHA256: $SHA256
Keystore: $KEYSTORE (alias: $KEY_ALIAS)
==========================================
EOF
