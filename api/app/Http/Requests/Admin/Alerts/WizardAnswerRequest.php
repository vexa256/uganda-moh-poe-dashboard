<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Alerts;

final class WizardAnswerRequest extends BaseAdminAlertsRequest
{
    public function rules(): array
    {
        return [
            'step_code'    => ['required', 'string', 'max:80'],
            'option_code'  => ['required', 'string', 'in:YES_DONE,IN_PROGRESS,NOT_APPLICABLE,NEED_HELP'],
            'reason'       => ['nullable', 'string', 'max:500'],
            'evidence_ref' => ['nullable', 'string', 'max:190'],
            'note'         => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'step_code.required'   => 'We lost track of which step you were on. Please refresh the page.',
            'option_code.required' => 'Please pick one of the answer options.',
            'option_code.in'       => 'That is not one of the answer options.',
            'reason.max'           => 'Keep the reason under 500 characters.',
            'note.max'             => 'Keep the note under 500 characters.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('option_code') === 'NOT_APPLICABLE' && trim((string) $this->input('reason', '')) === '') {
                $v->errors()->add('reason', 'Please tell us briefly why this does not apply.');
            }
        });
    }
}
