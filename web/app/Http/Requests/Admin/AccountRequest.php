<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountStatus;
use App\Enums\BillingType;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Account validation, shared by the admin UI (as a FormRequest) and the REST
 * API (via validateData()).
 */
class AccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(self::normalise($this->all()));
    }

    public function rules(): array
    {
        return self::rulesFor($this->route('account'));
    }

    public function messages(): array
    {
        return self::messagesFor();
    }

    public function after(): array
    {
        return [fn (Validator $v) => self::checkRoles($v)];
    }

    /** @return array<string, mixed> */
    public static function normalise(array $input): array
    {
        $bool = fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        return [
            'code' => strtoupper(trim((string) ($input['code'] ?? ''))),
            'currency' => strtoupper(trim((string) ($input['currency'] ?? 'USD'))),
            'is_customer' => $bool($input['is_customer'] ?? false),
            'is_vendor' => $bool($input['is_vendor'] ?? false),
        ];
    }

    public static function rulesFor(?Account $account): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('accounts', 'code')->ignore($account)],
            'is_customer' => ['boolean'],
            'is_vendor' => ['boolean'],
            'status' => ['required', Rule::enum(AccountStatus::class)],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'billing_type' => ['required', Rule::enum(BillingType::class)],
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'low_balance_threshold' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'timezone' => ['required', 'timezone:all'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'noc_email' => ['nullable', 'email', 'max:255'],
            'sell_rate_deck_id' => ['nullable', 'integer', 'exists:rate_decks,id'],
            'routing_plan_id' => ['nullable', 'integer', 'exists:routing_plans,id'],
            'max_channels' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_cps' => ['nullable', 'numeric', 'min:0.1', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public static function messagesFor(): array
    {
        return ['code.regex' => 'Use letters, digits, dashes and underscores only.'];
    }

    public static function checkRoles(Validator $validator): void
    {
        $data = $validator->getData();
        if (empty($data['is_customer']) && empty($data['is_vendor'])) {
            $validator->errors()->add('is_customer', 'An account must be a customer, a vendor, or both.');
        }
    }

    /** Validate plain input (REST API). Throws ValidationException. */
    public static function validateData(array $input, ?Account $account = null): array
    {
        $data = [...$input, ...self::normalise($input)];
        $validator = ValidatorFactory::make($data, self::rulesFor($account), self::messagesFor());
        $validator->after(fn (Validator $v) => self::checkRoles($v));

        return $validator->validate();
    }
}
