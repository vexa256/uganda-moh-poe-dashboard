package io.ionic.starter.sentinel;

import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Matrix;
import android.util.Base64;
import android.util.Log;

import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.google.mlkit.vision.common.InputImage;
import com.google.mlkit.vision.text.Text;
import com.google.mlkit.vision.text.TextRecognition;
import com.google.mlkit.vision.text.TextRecognizer;
import com.google.mlkit.vision.text.latin.TextRecognizerOptions;

import java.io.File;
import java.io.IOException;

/**
 * TextRecognition — bundled ML Kit Latin-script OCR for offline passport MRZ.
 *
 * Restored 2026-05-04: passport MRZ scanning is an operational requirement.
 *
 * Hardening: every PluginMethod resolves (never rejects). Failure shapes
 * carry ok:false + reason so JS falls back to manual entry cleanly.
 *
 * Edge cases:
 *  1. Null / empty path
 *  2. file:// prefix must be stripped before File()
 *  3. content:// URIs not supported (camera gives us file:// on ≥API26)
 *  4. File does not exist / is zero bytes
 *  5. Corrupt JPEG → null from BitmapFactory → reason:"decode-failed"
 *  6. Image too large → OOM: two-pass sub-sample decode before ML Kit
 *  7. rotation 0/90/180/270 applied as Matrix.postRotate
 *  8. OOM during rotation → continue with unrotated bitmap
 *  9. ML Kit recognizer init failure (GMS unavailable on very old ROMs)
 * 10. ML Kit returns null Text object → treated as empty, ok:true
 * 11. Task failure → reason extracted from exception message
 * 12. PluginCall.setKeepAlive(true) used for async; always cleared on exit
 * 13. Recognizer.close() called after every call to release GMS session
 * 14. Any uncaught exception caught at top level so call is never dangling
 */
@CapacitorPlugin(name = "TextRecognition")
public class TextRecognitionPlugin extends Plugin {

    private static final String TAG = "TextRecognitionPlugin";

    // Decode threshold: sub-sample if either dimension exceeds this. 3000px
    // is 3-4× the resolution needed for MRZ but keeps us well under OOM on
    // low-end devices (2 GB RAM @ RGB_565 = ~17 MB for 3000×3000 image).
    private static final int MAX_DECODE_PX = 3000;

    @PluginMethod
    public void recognizeImage(PluginCall call) {
        String rawPath = call.getString("path", "");
        int rotation   = call.getInt("rotation", 0);

        // ── 1. Validate path ───────────────────────────────────────────
        if (rawPath == null || rawPath.trim().isEmpty()) {
            ok(call, false, "missing-path"); return;
        }
        String filePath = rawPath.trim();
        if (filePath.startsWith("file://")) filePath = filePath.substring(7);
        if (filePath.startsWith("content://")) { ok(call, false, "content-uri-unsupported"); return; }

        File imageFile = new File(filePath);
        if (!imageFile.exists() || !imageFile.isFile()) { ok(call, false, "file-not-found"); return; }
        if (imageFile.length() == 0)                    { ok(call, false, "file-empty");     return; }

        // ── 2. Decode bitmap with sub-sampling ─────────────────────────
        Bitmap bitmap = decodeSampled(imageFile);
        if (bitmap == null) { ok(call, false, "decode-failed"); return; }

        // ── 3. Apply rotation ──────────────────────────────────────────
        int rot = ((rotation % 360) + 360) % 360;
        if (rot != 0) {
            try {
                Matrix m = new Matrix();
                m.postRotate(rot);
                Bitmap rotated = Bitmap.createBitmap(bitmap, 0, 0, bitmap.getWidth(), bitmap.getHeight(), m, true);
                bitmap.recycle();
                bitmap = rotated;
            } catch (OutOfMemoryError oom) {
                Log.w(TAG, "OOM rotating bitmap at " + rot + "°; using unrotated");
            } catch (Exception e) {
                Log.w(TAG, "Rotation threw; using unrotated: " + e.getMessage());
            }
        }

        // ── 4. Build ML Kit recognizer ─────────────────────────────────
        TextRecognizer recognizer;
        try {
            recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS);
        } catch (Exception e) {
            Log.e(TAG, "Recognizer init failed", e);
            bitmap.recycle();
            ok(call, false, "recognizer-init-failed"); return;
        }

        // ── 5. Build InputImage ────────────────────────────────────────
        InputImage image;
        try {
            image = InputImage.fromBitmap(bitmap, 0);
        } catch (Exception e) {
            Log.e(TAG, "InputImage.fromBitmap failed", e);
            bitmap.recycle();
            closeQuietly(recognizer);
            ok(call, false, "input-image-failed"); return;
        }

        // ── 6. Async OCR ───────────────────────────────────────────────
        final Bitmap fb  = bitmap;
        final TextRecognizer fr = recognizer;
        call.setKeepAlive(true);

        try {
            fr.process(image)
                .addOnSuccessListener(visionText -> {
                    fb.recycle();
                    closeQuietly(fr);
                    call.setKeepAlive(false);

                    if (visionText == null) {
                        // Null from ML Kit = nothing detected; report ok:true + empty
                        JSObject r = new JSObject();
                        r.put("ok", true); r.put("text", ""); r.put("blocks", 0); r.put("lines", new JSArray());
                        call.resolve(r);
                        return;
                    }

                    JSArray lines = new JSArray();
                    int blockCount = 0;
                    for (Text.TextBlock block : visionText.getTextBlocks()) {
                        blockCount++;
                        for (Text.Line line : block.getLines()) {
                            String t = line.getText();
                            if (t != null && !t.isEmpty()) lines.put(t);
                        }
                    }
                    JSObject r = new JSObject();
                    r.put("ok",     true);
                    r.put("text",   visionText.getText() != null ? visionText.getText() : "");
                    r.put("blocks", blockCount);
                    r.put("lines",  lines);
                    call.resolve(r);
                })
                .addOnFailureListener(e -> {
                    Log.e(TAG, "ML Kit process failed", e);
                    fb.recycle();
                    closeQuietly(fr);
                    call.setKeepAlive(false);
                    String reason = "ocr-failed";
                    if (e.getMessage() != null) {
                        if (e.getMessage().contains("CANCELED"))      reason = "ocr-canceled";
                        else if (e.getMessage().contains("INVALID"))  reason = "ocr-invalid-image";
                        else if (e.getMessage().contains("INTERNAL")) reason = "ocr-internal-error";
                    }
                    ok(call, false, reason);
                });
        } catch (Exception e) {
            Log.e(TAG, "process() threw synchronously", e);
            fb.recycle();
            closeQuietly(fr);
            call.setKeepAlive(false);
            ok(call, false, "process-error:" + e.getMessage());
        }
    }

    /**
     * recognizeBytes — base64 JPEG → ML Kit OCR.
     * Used by the live-streaming scanner so JS can push captured camera
     * frames directly without round-tripping through the filesystem.
     *
     * Args: { bytes: base64-string, rotation: int }
     * Returns: same shape as recognizeImage.
     */
    @PluginMethod
    public void recognizeBytes(PluginCall call) {
        String b64    = call.getString("bytes", "");
        int rotation  = call.getInt("rotation", 0);

        if (b64 == null || b64.trim().isEmpty()) { ok(call, false, "missing-bytes"); return; }

        // Strip data-URL prefix (data:image/jpeg;base64,…) if present.
        String payload = b64.trim();
        int comma = payload.indexOf(',');
        if (payload.startsWith("data:") && comma > 0) payload = payload.substring(comma + 1);

        byte[] raw;
        try {
            raw = Base64.decode(payload, Base64.DEFAULT);
        } catch (Throwable t) {
            ok(call, false, "base64-decode-failed");
            return;
        }
        if (raw == null || raw.length == 0) { ok(call, false, "empty-bytes"); return; }

        Bitmap bitmap;
        try {
            BitmapFactory.Options o = new BitmapFactory.Options();
            o.inPreferredConfig = Bitmap.Config.RGB_565;
            bitmap = BitmapFactory.decodeByteArray(raw, 0, raw.length, o);
        } catch (OutOfMemoryError oom) {
            // Retry with aggressive subsample.
            try {
                BitmapFactory.Options o = new BitmapFactory.Options();
                o.inSampleSize = 4;
                o.inPreferredConfig = Bitmap.Config.RGB_565;
                bitmap = BitmapFactory.decodeByteArray(raw, 0, raw.length, o);
            } catch (Throwable t) {
                ok(call, false, "decode-oom"); return;
            }
        } catch (Throwable t) {
            ok(call, false, "decode-failed"); return;
        }
        if (bitmap == null) { ok(call, false, "decode-null"); return; }

        int rot = ((rotation % 360) + 360) % 360;
        if (rot != 0) {
            try {
                Matrix m = new Matrix();
                m.postRotate(rot);
                Bitmap rotated = Bitmap.createBitmap(bitmap, 0, 0, bitmap.getWidth(), bitmap.getHeight(), m, true);
                bitmap.recycle();
                bitmap = rotated;
            } catch (Throwable t) {
                Log.w(TAG, "Rotation failed; using unrotated: " + t.getMessage());
            }
        }

        TextRecognizer recognizer;
        try {
            recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS);
        } catch (Exception e) {
            bitmap.recycle();
            ok(call, false, "recognizer-init-failed"); return;
        }

        InputImage image;
        try {
            image = InputImage.fromBitmap(bitmap, 0);
        } catch (Exception e) {
            bitmap.recycle(); closeQuietly(recognizer);
            ok(call, false, "input-image-failed"); return;
        }

        final Bitmap fb  = bitmap;
        final TextRecognizer fr = recognizer;
        call.setKeepAlive(true);

        try {
            fr.process(image)
                .addOnSuccessListener(visionText -> {
                    fb.recycle(); closeQuietly(fr); call.setKeepAlive(false);
                    JSArray lines = new JSArray();
                    int blockCount = 0;
                    if (visionText != null) {
                        for (Text.TextBlock block : visionText.getTextBlocks()) {
                            blockCount++;
                            for (Text.Line line : block.getLines()) {
                                String t = line.getText();
                                if (t != null && !t.isEmpty()) lines.put(t);
                            }
                        }
                    }
                    JSObject r = new JSObject();
                    r.put("ok", true);
                    r.put("text", visionText != null && visionText.getText() != null ? visionText.getText() : "");
                    r.put("blocks", blockCount);
                    r.put("lines", lines);
                    call.resolve(r);
                })
                .addOnFailureListener(e -> {
                    Log.e(TAG, "ML Kit process bytes failed", e);
                    fb.recycle(); closeQuietly(fr); call.setKeepAlive(false);
                    ok(call, false, "ocr-failed");
                });
        } catch (Exception e) {
            fb.recycle(); closeQuietly(fr); call.setKeepAlive(false);
            ok(call, false, "process-error:" + e.getMessage());
        }
    }

    @PluginMethod
    public void isAvailable(PluginCall call) {
        JSObject r = new JSObject();
        r.put("available", true);
        r.put("reason",    "bundled");
        call.resolve(r);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private static Bitmap decodeSampled(File file) {
        try {
            // Pass 1: read dimensions only
            BitmapFactory.Options o = new BitmapFactory.Options();
            o.inJustDecodeBounds = true;
            BitmapFactory.decodeFile(file.getAbsolutePath(), o);
            if (o.outWidth <= 0 || o.outHeight <= 0) return null;

            // Pass 2: decode at calculated sample size
            o.inSampleSize       = calcSample(o.outWidth, o.outHeight);
            o.inJustDecodeBounds = false;
            o.inPreferredConfig  = Bitmap.Config.RGB_565; // 50% less memory than ARGB_8888
            return BitmapFactory.decodeFile(file.getAbsolutePath(), o);
        } catch (OutOfMemoryError oom) {
            Log.e(TAG, "OOM decoding; trying 8× sub-sample", oom);
            try {
                BitmapFactory.Options e = new BitmapFactory.Options();
                e.inSampleSize = 8; e.inPreferredConfig = Bitmap.Config.RGB_565;
                return BitmapFactory.decodeFile(file.getAbsolutePath(), e);
            } catch (Throwable t) { Log.e(TAG, "Emergency decode also failed", t); return null; }
        } catch (Exception e) {
            Log.e(TAG, "decodeFile threw", e); return null;
        }
    }

    private static int calcSample(int w, int h) {
        int s = 1;
        while ((w / (s * 2)) > MAX_DECODE_PX || (h / (s * 2)) > MAX_DECODE_PX) s *= 2;
        return s;
    }

    private static void closeQuietly(TextRecognizer r) {
        try { r.close(); } catch (Exception ignored) {}
    }

    private static void ok(PluginCall call, boolean ok, String reason) {
        try {
            call.setKeepAlive(false);
            JSObject r = new JSObject();
            r.put("ok", ok);
            if (reason != null) r.put("reason", reason);
            r.put("lines", new JSArray());
            if (!ok) { r.put("text", ""); r.put("blocks", 0); }
            call.resolve(r);
        } catch (Exception ignored) {}
    }
}
