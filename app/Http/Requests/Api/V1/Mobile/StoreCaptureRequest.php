<?php

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCaptureRequest extends FormRequest
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
            'kind' => ['required', Rule::in(['url', 'text', 'image'])],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'url' => ['required_if:kind,url', 'prohibited_unless:kind,url', 'url', 'max:2048'],
            'text' => ['required_if:kind,text', 'prohibited_unless:kind,text', 'string', 'max:20000'],
            'image' => ['required_if:kind,image', 'prohibited_unless:kind,image', 'string', 'max:21000000'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.regex' => 'The idempotency key may only contain letters, numbers, dots, colons, underscores and dashes.',
            'image.max' => 'The image is too large.',
        ];
    }
}
