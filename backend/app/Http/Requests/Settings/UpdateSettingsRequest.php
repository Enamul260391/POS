<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Http\Requests\Concerns\ResolvesBusiness;
use App\Models\BusinessSetting;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Payload of `PUT|PATCH /api/v1/settings`.
 *
 * The body is a flat `{ "<key>": "<value>" }` map of settings to write. Wrapping
 * it in an object was rejected deliberately: the table is a free-form key/value
 * store, so a flat map keeps `PUT {"receipt_footer": "Thanks"}` natural, and
 * the reserved-key list in `SettingsService` blocks any key that could shadow
 * an internal column.
 *
 * `business_id` is never accepted — the owning business always comes from the
 * authenticated user.
 */
class UpdateSettingsRequest extends FormRequest
{
    use ResolvesBusiness;

    /**
     * Authorization is enforced by the `can:` route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `required` would reject a legitimately empty update, so the batch
            // is checked in `withValidator()` instead.
            '*' => ['nullable', 'string', 'max:'.BusinessSetting::MAX_VALUE_LENGTH],
        ];
    }

    /**
     * At least one key must be supplied, and no key may collide with an
     * internal column.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $payload = $this->json()->all();

            if ($payload === []) {
                $validator->errors()->add('settings', 'At least one setting must be provided.');
            }

            foreach (array_keys($payload) as $key) {
                if (! $this->isValidKey((string) $key)) {
                    $validator->errors()->add((string) $key, 'The setting key format is invalid.');
                }
            }
        });
    }

    /**
     * Key format accepted by the API.
     *
     * Deliberately narrow — dots, dashes, underscores, colons and spaces are
     * allowed so existing conventions (`receipt.footer`, `pos-v2`, `tax_rate`)
     * keep working, while control characters and oversized keys do not.
     */
    private function isValidKey(string $key): bool
    {
        if ($key === '' || strlen($key) > BusinessSetting::MAX_KEY_LENGTH) {
            return false;
        }

        return (bool) preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\._\-: ]*$/u', $key);
    }

    /**
     * Normalised key/value pairs ready for the service.
     *
     * @return array<string, string|null>
     */
    public function settings(): array
    {
        $settings = [];

        foreach ($this->json()->all() as $key => $value) {
            $settings[(string) $key] = $value === null ? null : (string) $value;
        }

        return $settings;
    }

    /**
     * Keys that must never be written, surfaced here so the request document
     * itself is explicit about the rule it enforces.
     *
     * @return array<int, string>
     */
    public static function reservedKeys(): array
    {
        return SettingsService::RESERVED_KEYS;
    }
}
