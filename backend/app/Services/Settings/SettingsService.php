<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\BusinessSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business settings stored as `(business_id, key, value)` rows.
 *
 * `business_settings` has no dedicated columns for credentials, but the key is
 * free-form, so this service is the chokepoint that decides which keys may be
 * written and which values may be read back:
 *
 *  - **Ownership** — every query is scoped to the authenticated user's
 *    `business_id`; `business_id` is never accepted from the client.
 *  - **Sensitive keys** — keys that look like credentials (tokens, secrets,
 *    passwords, API keys) are write-protected and their values are never
 *    returned, even if a row exists in the table. They can only be provisioned
 *    out of band (deployment/CLI), never through the API.
 *  - **Reserved keys** — identity and ownership columns are refused so a
 *    setting key can never shadow internal data.
 *
 * Updates are upserts inside a single transaction, so a partially applied batch
 * is impossible.
 */
final class SettingsService
{
    /**
     * Substrings that mark a key as credential-shaped.
     *
     * Matching is case-insensitive and substring-based, so `api_token`,
     * `stripeSecretKey` and `smtp_password` are all covered without having to
     * enumerate every provider.
     *
     * @var array<int, string>
     */
    public const SENSITIVE_KEY_FRAGMENTS = [
        'secret',
        'password',
        'passwd',
        'token',
        'api_key',
        'apikey',
        'private_key',
        'credential',
        'signature',
    ];

    /**
     * Keys the API refuses to write, because they collide with internal
     * columns or with tenancy itself.
     *
     * @var array<int, string>
     */
    public const RESERVED_KEYS = [
        'business_id',
        'id',
        'key',
        'value',
        'created_at',
        'updated_at',
    ];

    /**
     * All readable settings for one business, as key/value pairs.
     *
     * Sensitive keys are filtered out of the result entirely.
     *
     * @return Collection<int, BusinessSetting>
     */
    public function all(int $businessId): Collection
    {
        return BusinessSetting::query()
            ->forBusiness($businessId)
            ->orderBy('key')
            ->get()
            ->reject(fn (BusinessSetting $setting): bool => $this->isSensitive((string) $setting->key))
            ->values();
    }

    /**
     * Apply a batch of key/value updates for one business.
     *
     * @param  array<string, string|null>  $values
     * @return Collection<int, BusinessSetting>
     *
     * @throws ValidationException
     */
    public function update(int $businessId, array $values): Collection
    {
        $values = $this->assertUpdatableKeys($values);

        if ($values === []) {
            return $this->all($businessId);
        }

        DB::transaction(function () use ($businessId, $values): void {
            foreach ($values as $key => $value) {
                // `updateOrCreate` keys off (business_id, key), which carries a
                // unique index — so this can never create a duplicate row and
                // `business_id` is written from the server-side scope only.
                BusinessSetting::query()->updateOrCreate(
                    [
                        'business_id' => $businessId,
                        'key' => $key,
                    ],
                    ['value' => $value],
                );
            }
        });

        return $this->all($businessId);
    }

    /**
     * Does the key look like it holds a credential?
     */
    public function isSensitive(string $key): bool
    {
        $normalised = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reject reserved and credential-shaped keys before anything is written.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     *
     * @throws ValidationException
     */
    private function assertUpdatableKeys(array $values): array
    {
        $errors = [];
        $clean = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (in_array(strtolower($key), self::RESERVED_KEYS, true)) {
                $errors[$key] = ['This setting key is reserved and cannot be modified.'];

                continue;
            }

            if ($this->isSensitive($key)) {
                $errors[$key] = ['This setting key is write-protected.'];

                continue;
            }

            $clean[$key] = $value === null ? null : (string) $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}
