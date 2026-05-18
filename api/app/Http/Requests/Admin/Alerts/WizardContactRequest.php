<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Alerts;

final class WizardContactRequest extends BaseAdminAlertsRequest
{
    public function rules(): array
    {
        return [
            'action'        => ['required', 'string', 'in:RESEND_EMAIL,MARKED_CALLED,ASKED_NEW'],
            'message'       => ['nullable', 'string', 'max:1000'],
            'contact_email' => ['nullable', 'string', 'email', 'max:190'],
            'contact_name'  => ['nullable', 'string', 'max:190'],
            'contact_id'    => ['nullable', 'integer', 'min:1'],
            'contact_kind'  => ['nullable', 'string', 'in:external_responder,notification_contact,assigned_user'],
            'template_code' => ['nullable', 'string', 'in:WIZARD_ASK_LAB,WIZARD_ASK_FIELD_TEAM,WIZARD_ASK_PHEOC,WIZARD_REMIND_RESPONDER'],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => 'Please tell us what kind of contact this was.',
            'action.in'       => 'That is not a recognised contact action.',
            'message.max'     => 'Keep the message under 1000 characters.',
        ];
    }
}
