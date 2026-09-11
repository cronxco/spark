<?php

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationFeedRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', Rule::in(['active', 'history'])],
            'stream' => ['sometimes', Rule::in(['updates', 'activity', 'attention', 'system'])],
            'search' => ['sometimes', 'string', 'max:120'],
            'cursor' => ['sometimes', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
