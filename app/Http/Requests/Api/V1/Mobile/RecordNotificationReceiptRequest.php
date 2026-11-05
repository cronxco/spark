<?php

namespace App\Http\Requests\Api\V1\Mobile;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * One notification receipt from the iOS app (decision N-8). The notification
 * is named in the path; the body carries only the event, time and action.
 */
class RecordNotificationReceiptRequest extends FormRequest
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
        return RecordNotificationReceiptsRequest::receiptRules();
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return RecordNotificationReceiptsRequest::onlyKeys($this, ['event', 'occurred_at', 'action']);
    }
}
