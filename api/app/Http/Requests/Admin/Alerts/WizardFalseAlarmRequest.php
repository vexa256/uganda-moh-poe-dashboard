<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Alerts;

final class WizardFalseAlarmRequest extends BaseAdminAlertsRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Please tell us briefly why this is a false alarm.',
            'reason.min'      => 'Please give us a slightly longer explanation — at least 10 characters.',
            'reason.max'      => 'Keep the reason under 1000 characters.',
        ];
    }
}
