<?php

namespace App\Http\Requests\Api\V1\Mobile;

use App\Integrations\Untappd\AlcoholUnits;
use App\Services\ManualLog\ManualLogService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLogRequest extends FormRequest
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
            'kind' => ['required', Rule::in(array_keys(ManualLogService::KINDS))],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'title' => ['required', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:+5 minutes'],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'unit' => ['nullable', 'string', 'max:30'],
            'serving' => ['nullable', Rule::in(array_keys(AlcoholUnits::SERVING_ML))],
            'volume_ml' => ['nullable', 'integer', 'between:10,5000'],
            'rating' => ['nullable', 'numeric', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'season' => ['nullable', 'integer', 'between:1,99'],
            'episodes' => ['required_if:kind,tv', 'prohibited_unless:kind,tv', 'array', 'min:1', 'max:50'],
            'episodes.*' => ['integer', 'between:1,999'],
            'tvdb_id' => ['nullable', 'integer', 'min:1', 'prohibited_unless:kind,tv'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.regex' => 'The idempotency key may only contain letters, numbers, dots, colons, underscores and dashes.',
            'episodes.required_if' => 'Say which episodes were watched.',
            'occurred_at.before_or_equal' => 'Nothing can be logged in the future.',
        ];
    }
}
