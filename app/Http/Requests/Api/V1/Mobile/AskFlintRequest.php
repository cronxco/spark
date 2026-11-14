<?php

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AskFlintRequest extends FormRequest
{
    public const MAX_MESSAGE_LENGTH = 4000;

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
            'message' => ['required', 'string', 'max:' . self::MAX_MESSAGE_LENGTH],
            'conversation_id' => ['nullable', 'uuid'],
            'stream' => ['nullable', 'boolean'],
            'read_only' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Ask Flint something.',
            'message.max' => 'That is too long to ask in one go. Keep it under ' . self::MAX_MESSAGE_LENGTH . ' characters.',
            'conversation_id.uuid' => 'That conversation id is not valid.',
        ];
    }

    /** Whether the caller asked for the answer as a server-sent event stream. */
    public function wantsStream(): bool
    {
        return $this->has('stream')
            ? $this->boolean('stream')
            : str_contains((string) $this->header('Accept'), 'text/event-stream');
    }
}
