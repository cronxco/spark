<?php

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SetIntegrationPausedRequest extends FormRequest
{
    /**
     * Ownership is enforced by the controller's user-scoped lookup.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'paused' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paused.required' => 'Say whether the integration should be paused.',
            'paused.boolean' => 'Paused must be true or false.',
        ];
    }
}
