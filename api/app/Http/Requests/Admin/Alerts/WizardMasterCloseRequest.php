<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Alerts;

use App\Http\Controllers\AlertsController as CanonicalAlertsController;

final class WizardMasterCloseRequest extends BaseAdminAlertsRequest
{
    public function rules(): array
    {
        $categories = array_keys(CanonicalAlertsController::CLOSE_CATEGORIES);

        return [
            'override_reason'      => ['required', 'string', 'min:30', 'max:1000'],
            'close_category'       => ['required', 'string', 'in:' . implode(',', $categories)],
            'close_note'           => ['nullable', 'string', 'max:1000'],
            'merged_into_alert_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'override_reason.required' => 'Please give us a longer explanation.',
            'override_reason.min'      => 'Please give us at least 30 characters of explanation.',
            'override_reason.max'      => 'Keep the explanation under 1000 characters.',
            'close_category.required'  => 'Please choose a reason for closing this case.',
            'close_category.in'        => 'That is not a recognised closing reason.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('close_category') === 'OTHER' && trim((string) $this->input('close_note', '')) === '') {
                $v->errors()->add('close_note', 'Please add a short note explaining the reason.');
            }
            if ($this->input('close_category') === 'DUPLICATE' && !$this->input('merged_into_alert_id')) {
                $v->errors()->add('merged_into_alert_id', 'Tell us which alert this one is a duplicate of.');
            }
        });
    }
}
