<?php

namespace App\Http\Requests\Api\V1\Mobile;

use App\Services\Notifications\NotificationReceiptRecorder;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A batch of notification receipts from the iOS app (decision N-8).
 *
 * Receipts are telemetry only: any key beyond the id, event, time and action
 * identifier is rejected, so message content can never be stored.
 */
class RecordNotificationReceiptsRequest extends FormRequest
{
    public const MAX_RECEIPTS = 50;

    /**
     * The rules every receipt shares, under the given key prefix.
     *
     * @return array<string, array<mixed>>
     */
    public static function receiptRules(string $prefix = ''): array
    {
        return [
            $prefix . 'event' => ['required', 'string', Rule::in(NotificationReceiptRecorder::EVENTS)],
            $prefix . 'occurred_at' => ['required', 'date'],
            $prefix . 'action' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    /**
     * Reject any top-level field the contract does not define.
     *
     * @param  array<int, string>  $allowed
     * @return array<int, Closure(Validator): void>
     */
    public static function onlyKeys(FormRequest $request, array $allowed): array
    {
        return [
            function (Validator $validator) use ($request, $allowed): void {
                foreach (array_diff(array_keys($request->all()), $allowed) as $key) {
                    $validator->errors()->add((string) $key, 'Receipts carry only an id, event, time and action.');
                }
            },
        ];
    }

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
            'receipts' => ['required', 'array', 'min:1', 'max:' . self::MAX_RECEIPTS],
            'receipts.*' => ['required', 'array:notification_id,event,occurred_at,action'],
            'receipts.*.notification_id' => ['required', 'string', 'uuid'],
            ...self::receiptRules('receipts.*.'),
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return self::onlyKeys($this, ['receipts']);
    }
}
