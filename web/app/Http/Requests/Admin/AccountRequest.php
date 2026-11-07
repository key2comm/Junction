<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountStatus;
use App\Enums\BillingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'currency' => strtoupper(trim((string) $this->input('currency', 'USD'))),
            'is_customer' => $this->boolean('is_customer'),
            'is_vendor' => $this->boolean('is_vendor'),
        ]);
    }

    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('accounts', 'code')->ignore($account)],
            'is_customer' => ['boolean'],
            'is_vendor' => ['boolean'],
            'status' => ['required', Rule::enum(AccountStatus::class)],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'billing_type' => ['required', Rule::enum(BillingType::class)],
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:9999999999'],
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

    public function messages(): array
    {
        return [
            'code.regex' => 'Use letters, digits, dashes and underscores only.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->boolean('is_customer') && ! $this->boolean('is_vendor')) {
                    $validator->errors()->add('is_customer', 'An account must be a customer, a vendor, or both.');
                }
            },
        ];
    }
}
