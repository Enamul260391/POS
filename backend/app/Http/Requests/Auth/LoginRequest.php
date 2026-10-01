<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\LoginThrottle;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoginRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Enforce the lockout *before* the payload is validated, then charge a
     * malformed payload to the failed-sign-in budget.
     *
     * The lockout itself lives in `AuthController`, which never runs for a
     * payload this request rejects. Checking it here as well is what keeps the
     * old `throttle:login` guarantee that a caller cannot hammer the endpoint
     * with junk forever: the middleware ran ahead of the form request, so a
     * `422` was charged to the budget and eventually became a `429`.
     *
     * Two hooks, because two different things are being preserved:
     *
     *   prepareForValidation()  refuses an *already locked out* caller with
     *                           `429`, so they cannot keep probing for free by
     *                           sending payloads that never validate.
     *   failedValidation()      charges a *newly rejected* payload, so junk
     *                           still counts against the budget it is burning.
     *
     * A `422` is a refused sign-in and is treated exactly like a wrong password:
     * same buckets, same counters. The response is still a `422` for as long as
     * the caller is within budget — the throttle must not change what a
     * well-formed client sees, only how often it may ask.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->loginEmail();
        $ip = (string) $this->ip();

        if (LoginThrottle::tooManyAttempts($email, $ip)) {
            // Thrown as an exception so it travels the same path as any other
            // early rejection and is rendered by ApiExceptionRenderer into the
            // standard envelope.
            throw new HttpResponseException(
                LoginThrottle::tooManyAttemptsResponse($email, $ip),
            );
        }

        parent::prepareForValidation();
    }

    /**
     * Charge a rejected payload to the failed-sign-in budget, then let
     * validation report normally.
     *
     * The address is read defensively: it is unvalidated input at this point,
     * so it may be absent or not even a string. {@see LoginThrottle} trims and
     * hashes whatever it is given, so a junk probe still lands in a bounded
     * bucket instead of raising a second error on top of the `422`.
     */
    protected function failedValidation(Validator $validator): void
    {
        LoginThrottle::recordFailure($this->loginEmail(), (string) $this->ip());

        parent::failedValidation($validator);
    }

    /**
     * The submitted address as a string, whatever the payload contained.
     *
     * An absent or non-string value becomes `''`, which is itself a valid
     * bucket key: a caller sending no address at all shares one budget with
     * every other caller sending no address, which is the desired behaviour for
     * a probe.
     */
    private function loginEmail(): string
    {
        $email = $this->input('email');

        return is_string($email) ? $email : '';
    }
}
