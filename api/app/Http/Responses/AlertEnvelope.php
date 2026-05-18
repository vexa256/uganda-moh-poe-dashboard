<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Support\Alerts\HumanLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single response envelope for every admin-alerts JSON endpoint.
 *
 * Contract:
 *   ok  → { success:true,  message, data, meta? }
 *   err → { success:false, message, error: { code, human, detail } }
 *
 * The mobile-facing canonical AlertsController has its own envelope shape;
 * this class is for the admin panel only. Existing per-controller helpers
 * keep working — controllers can adopt this incrementally.
 */
final class AlertEnvelope
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $meta
     */
    public static function ok(array $data, string $message = 'OK', array $meta = []): JsonResponse
    {
        $body = [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

        if (!empty($meta)) {
            $body['meta'] = $meta;
        }

        return response()->json($body);
    }

    /**
     * Build an error response from a known lang key.
     *
     * Looks up `alerts.error.{$key}` for the canonical code + human string.
     * Unknown keys fall back to the generic envelope so a typo never leaks
     * a raw exception message to the user.
     *
     * @param array<string,mixed> $detail
     * @param array<string,mixed> $replace
     */
    public static function err(
        string $key,
        int $status = 422,
        array $detail = [],
        array $replace = []
    ): JsonResponse {
        $entry = (array) trans("alerts.error.{$key}");
        if (empty($entry['code'])) {
            $entry = (array) trans('alerts.error.generic');
        }

        $human = (string) ($entry['human'] ?? '');
        if ($replace) {
            foreach ($replace as $k => $v) {
                $human = str_replace(":{$k}", (string) $v, $human);
            }
        }

        return response()->json([
            'success' => false,
            'message' => $human,
            'error'   => [
                'code'   => (string) $entry['code'],
                'human'  => $human,
                'detail' => $detail,
            ],
        ], $status);
    }

    /**
     * Validation failures — surfaces field-level humans without leaking
     * raw rule names (e.g. 'required', 'integer').
     *
     * @param array<string,array<string>> $errors
     */
    public static function validation(array $errors, string $message = ''): JsonResponse
    {
        $entry = (array) trans('alerts.error.validation');
        $human = $message !== '' ? $message : (string) ($entry['human'] ?? '');

        return response()->json([
            'success' => false,
            'message' => $human,
            'error'   => [
                'code'   => (string) ($entry['code'] ?? 'INPUT_NEEDS_FIXING'),
                'human'  => $human,
                'detail' => ['fields' => $errors],
            ],
        ], 422);
    }

    /**
     * Last-line fallback for unexpected exceptions. Logs the technical
     * detail server-side; the user only ever sees the generic envelope.
     */
    public static function fromThrowable(Throwable $e, string $context = ''): JsonResponse
    {
        Log::error("[AlertEnvelope][{$context}] " . $e->getMessage(), [
            'file'  => $e->getFile() . ':' . $e->getLine(),
            'class' => $e::class,
        ]);

        return self::err('generic', 500);
    }

    /**
     * Convenience for blocked-closure responses — surfaces the human-friendly
     * blocking-step list rather than a wall of action codes.
     *
     * @param iterable<array<string,mixed>|object> $blockingFollowups raw rows
     */
    public static function closureBlocked(iterable $blockingFollowups): JsonResponse
    {
        $list = HumanLabels::wrapMany(
            $blockingFollowups,
            static fn ($row) => HumanLabels::wrapFollowup($row)
        );

        return self::err('closure_blocked', 409, ['blocking' => $list]);
    }
}
