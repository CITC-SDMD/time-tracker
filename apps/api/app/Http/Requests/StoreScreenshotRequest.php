<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

// POST /api/v1/agent/screenshots (docs/DEVELOPMENT_PLAN.md phase 10). Only the fields named here are
// used, so `uid`, `userId` and friends in the body are ignored: the person always comes from the token.
class StoreScreenshotRequest extends FormRequest
{
    /** the largest picture the server accepts, in kilobytes (the desktop app sends about 150) */
    public const MAX_KILOBYTES = 1536;

    public const MAX_PIXELS = 3840;

    /** how far back a screenshot may be dated (a laptop can be offline for a long weekend) */
    public const MAX_AGE_DAYS = 30;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('deviceId') && $this->header('X-Device-Id')) {
            $this->merge(['deviceId' => $this->header('X-Device-Id')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'takenAt' => ['required', 'date', function (string $attribute, mixed $value, \Closure $fail) {
                $time = strtotime((string) $value);
                if ($time === false) {
                    return;
                }
                $now = Carbon::now()->getTimestamp();
                if ($time > $now + 120) {
                    $fail('The time is in the future.');
                }
                if ($time < $now - self::MAX_AGE_DAYS * 86400) {
                    $fail('The screenshot is too old to accept.');
                }
            }],
            'deviceId' => ['nullable', 'uuid'],
            'width' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PIXELS],
            'height' => ['required', 'integer', 'min:1', 'max:'.self::MAX_PIXELS],
            'image' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, function (string $attribute, mixed $value, \Closure $fail) {
                if (! $value instanceof UploadedFile) {
                    return;
                }
                // the real content, not the name or the type the sender claims
                $info = @getimagesize($value->getRealPath());
                if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
                    $fail('The image must be a JPEG.');

                    return;
                }
                if ($info[0] > self::MAX_PIXELS || $info[1] > self::MAX_PIXELS) {
                    $fail('The image is too large in pixels.');
                }
            }],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => ['code' => 'VALIDATION_FAILED', 'message' => $validator->errors()->first(), 'fields' => $validator->errors()->toArray()],
        ], 422));
    }
}
