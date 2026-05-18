package io.ionic.starter.sentinel;

import android.content.Context;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import com.google.android.gms.common.api.OptionalModuleApi;
import com.google.android.gms.common.moduleinstall.InstallStatusListener;
import com.google.android.gms.common.moduleinstall.ModuleInstall;
import com.google.android.gms.common.moduleinstall.ModuleInstallClient;
import com.google.android.gms.common.moduleinstall.ModuleInstallRequest;
import com.google.android.gms.common.moduleinstall.ModuleInstallResponse;
import com.google.android.gms.common.moduleinstall.ModuleInstallStatusUpdate;

/**
 * ModuleInstall — Capacitor plugin wrapping Google Play Services'
 * {@link ModuleInstallClient}.
 *
 * The JS side (`src/services/plugins/mlkitInstaller.js`) hands us a stable
 * {@code moduleId} string; we resolve it to the appropriate
 * {@link OptionalModuleApi} client instance and ask Play Services to install
 * the underlying module. Progress updates are streamed back to JS via the
 * "progress" plugin event so the ModelManagerView can render a live
 * bytes-downloaded bar.
 *
 * Contract: never throws back to JS. On error — missing Play Services,
 * unknown module id, install failure — the plugin resolves the call with a
 * {@code {ok: false, reason: ...}} payload. The JS-side model manager
 * treats each reason string differently (see {@code runDownload} in
 * modelManager.js).
 *
 * APK-size notes (2026-04-26)
 * --------------------------------------------------------------------------
 * Four ML Kit feature libraries (text-recognition, face-detection, translate,
 * entity-extraction) were UNBUNDLED from this build to reclaim ~151 MB of
 * native lib weight. None of them had production call-sites — only the
 * scaffolding for sentinel-plan features that are not shipped yet. See
 * docs/sentinel-plan/UNBUNDLED.md.
 *
 * To re-enable any of those features:
 *   1. add the implementation line back in android/app/build.gradle
 *   2. re-add the matching imports + handler branch in this file
 *      (see git history at the commit that introduced UNBUNDLED.md for
 *      the exact bodies of installTranslate / installEntityExtraction /
 *      the resolveApi() vision cases)
 *
 * Recognised module ids (POST-2026-04-26 — bundled set)
 * --------------------------------------------------------------------------
 *   "doc-scanner"             → Play Services built-in, no install needed
 *   "smart-reply"             → Play Services built-in, no install needed
 *   anything else             → {ok:false, reason:"module-not-bundled"}
 *
 * The "module-not-bundled" path is non-retriable in modelManager.js
 * (NON_RETRIABLE_REASONS). The ModelManagerView surfaces this as
 * "feature not installed in this build" so the operator does not loop.
 */
@CapacitorPlugin(name = "ModuleInstall")
public class ModuleInstallPlugin extends Plugin {

    private static final String TAG = "SentinelModuleInstall";

    @PluginMethod
    public void install(final PluginCall call) {
        final String moduleId = call.getString("moduleId");
        if (moduleId == null || moduleId.isEmpty()) {
            resolveWith(call, false, "missing-module-id");
            return;
        }
        try {
            // NLP modules (translate, entity-extraction) used to be handled
            // here via their own downloadModelIfNeeded() APIs. After the
            // 2026-04-26 unbundling those libraries no longer ship in the
            // APK, so any moduleId that targets them resolves with
            // "module-not-bundled" and JS short-circuits without retry.
            if (moduleId.startsWith("translate-")) {
                resolveWith(call, false, "module-not-bundled");
                return;
            }
            if ("entity-extraction".equals(moduleId)) {
                resolveWith(call, false, "module-not-bundled");
                return;
            }
            // Vision modules + built-ins use the ModuleInstall path.
            installViaModuleInstall(call, moduleId);
        } catch (Throwable t) {
            resolveWith(call, false, "install-dispatch-failed:" + safeMessage(t));
        }
    }

    private void installViaModuleInstall(final PluginCall call, final String moduleId) {
        final Context ctx = getContext();
        final OptionalModuleApi api;
        try {
            api = resolveApi(ctx, moduleId);
        } catch (UnsupportedModuleException e) {
            resolveWith(call, false, e.getMessage());
            return;
        } catch (Throwable t) {
            resolveWith(call, false, "library-init-failed:" + safeMessage(t));
            return;
        }

        if (api == null) {
            resolveWith(call, true, null);
            return;
        }

        final ModuleInstallClient client;
        try {
            client = ModuleInstall.getClient(ctx);
        } catch (Throwable t) {
            resolveWith(call, false, "module-install-client-unavailable:" + safeMessage(t));
            return;
        }

        final InstallStatusListener listener = new InstallStatusListener() {
            @Override
            public void onInstallStatusUpdated(ModuleInstallStatusUpdate update) {
                try {
                    JSObject evt = new JSObject();
                    evt.put("moduleId", moduleId);
                    evt.put("installState", update.getInstallState());
                    ModuleInstallStatusUpdate.ProgressInfo info = update.getProgressInfo();
                    if (info != null) {
                        evt.put("bytesSoFar",  info.getBytesDownloaded());
                        evt.put("bytesTotal",  info.getTotalBytesToDownload());
                    }
                    notifyListeners("progress", evt);
                } catch (Throwable ignored) { /* listener errors swallowed */ }
            }
        };

        ModuleInstallRequest request;
        try {
            request = ModuleInstallRequest.newBuilder()
                    .addApi(api)
                    .setListener(listener)
                    .build();
        } catch (Throwable t) {
            resolveWith(call, false, "request-build-failed:" + safeMessage(t));
            return;
        }

        client.installModules(request)
                .addOnSuccessListener(new com.google.android.gms.tasks.OnSuccessListener<ModuleInstallResponse>() {
                    @Override
                    public void onSuccess(ModuleInstallResponse response) {
                        JSObject result = new JSObject();
                        result.put("ok", true);
                        result.put("alreadyInstalled", response.areModulesAlreadyInstalled());
                        call.resolve(result);
                    }
                })
                .addOnFailureListener(new com.google.android.gms.tasks.OnFailureListener() {
                    @Override
                    public void onFailure(Exception err) {
                        resolveWith(call, false, "install-failed:" + safeMessage(err));
                    }
                });
    }

    @PluginMethod
    public void isAvailable(final PluginCall call) {
        final String moduleId = call.getString("moduleId");
        if (moduleId == null || moduleId.isEmpty()) {
            resolveAvailability(call, false, "missing-module-id");
            return;
        }
        // NLP unbundled — answer "not available" without touching Play Services.
        if (moduleId.startsWith("translate-") || "entity-extraction".equals(moduleId)) {
            resolveAvailability(call, false, "module-not-bundled");
            return;
        }
        final Context ctx = getContext();
        final OptionalModuleApi api;
        try {
            api = resolveApi(ctx, moduleId);
        } catch (UnsupportedModuleException e) {
            resolveAvailability(call, false, e.getMessage());
            return;
        } catch (Throwable t) {
            resolveAvailability(call, false, "library-init-failed:" + safeMessage(t));
            return;
        }
        if (api == null) {
            // Play Services built-in — treat as always available.
            resolveAvailability(call, true, null);
            return;
        }
        ModuleInstallClient client;
        try {
            client = ModuleInstall.getClient(ctx);
        } catch (Throwable t) {
            resolveAvailability(call, false, "module-install-client-unavailable");
            return;
        }
        client.areModulesAvailable(api)
                .addOnSuccessListener(resp -> {
                    JSObject result = new JSObject();
                    result.put("ok", true);
                    result.put("available", resp.areModulesAvailable());
                    call.resolve(result);
                })
                .addOnFailureListener(err ->
                        resolveAvailability(call, false, "check-failed:" + safeMessage(err)));
    }

    // ── internal helpers ────────────────────────────────────────────────

    private OptionalModuleApi resolveApi(Context ctx, String moduleId) {
        switch (moduleId) {
            // Vision modules (text-recognition-latin, face-detection) were
            // unbundled on 2026-04-26 — see header docblock + UNBUNDLED.md.
            case "text-recognition-latin":
            case "face-detection":
                throw new UnsupportedModuleException("module-not-bundled");
            case "doc-scanner":
            case "smart-reply":
                return null;  // Play Services ships these; no install handshake.
            default:
                throw new UnsupportedModuleException("library-not-bundled");
        }
    }

    private static void resolveWith(PluginCall call, boolean ok, String reason) {
        JSObject result = new JSObject();
        result.put("ok", ok);
        if (reason != null) result.put("reason", reason);
        call.resolve(result);
    }

    private static void resolveAvailability(PluginCall call, boolean ok, String reason) {
        JSObject result = new JSObject();
        result.put("ok", ok);
        result.put("available", false);
        if (reason != null) result.put("reason", reason);
        call.resolve(result);
    }

    private static String safeMessage(Throwable t) {
        if (t == null) return "";
        String m = t.getMessage();
        if (m == null) return t.getClass().getSimpleName();
        // Guard against absurdly long messages from nested causes.
        return m.length() > 240 ? m.substring(0, 240) : m;
    }

    private static class UnsupportedModuleException extends RuntimeException {
        UnsupportedModuleException(String reason) { super(reason); }
    }
}
