<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Alerts;

use App\Http\Responses\AlertEnvelope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base FormRequest for admin alerts endpoints.
 *
 * Pipes validation failures through AlertEnvelope::validation so the user
 * sees the unified envelope rather than Laravel's default 422 shape.
 *
 * Authorisation is left to per-route role middleware — this base returns
 * true so children only need to define rules() and (optionally) messages().
 */
abstract class BaseAdminAlertsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            AlertEnvelope::validation($validator->errors()->toArray())
        );
    }
}
