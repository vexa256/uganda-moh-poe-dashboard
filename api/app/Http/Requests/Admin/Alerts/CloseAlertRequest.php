<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Alerts;

use App\Http\Controllers\AlertsController as CanonicalAlertsController;

/**
 * Validation for PATCH /admin/alerts/{id}/close.
 *
 * Mirrors the inline rules currently in Admin\Alerts\AlertsController::close()
 * so the controller can adopt this without behaviour drift. Stage 4 swaps
 * the controller over.
 */
final class CloseAlertRequest extends BaseAdminAlertsRequest
{
    public function rules(): array
    {
        $categories = array_keys(CanonicalAlertsController::CLOSE_CATEGORIES);

        return [
            'close_category'        => ['required', 'string', 'in:' . implode(',', $categories)],
            'close_note'            => ['nullable', 'string', 'max:1000'],
            'merged_into_alert_id'  => ['nullable', 'integer', 'min:1'],
            'override'              => ['nullable', 'boolean'],
            'override_reason'       => ['nullable', 'string', 'min:30', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'close_category.required'     => 'Please choose a reason for closing this case.',
            'close_category.in'           => 'That is not a recognised closing reason.',
            'close_note.max'              => 'The note is too long. Keep it under 1000 characters.',
            'merged_into_alert_id.integer'=> 'Pick the alert this one is a duplicate of.',
            'override_reason.min'         => 'Please give us a longer explanation (at least 30 characters).',
            'override_reason.max'         => 'The explanation is too long. Keep it under 1000 characters.',
        ];
    }
}
