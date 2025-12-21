<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class AddDemoAssetsRequest extends FormRequest
{
    /**
     * Get validation rules for demo asset top-ups.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string> Validation rules.
     */
    public function rules(): array
    {
        return [
            'balance' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'symbol' => ['nullable', 'string', 'max:16', 'regex:/^[\w\d]{1,16}$/i'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999999999.999999999999999999'],
        ];
    }

    /**
     * Get validation error messages for demo asset top-ups.
     *
     * @return array<string, string> Validation error messages.
     */
    public function messages(): array
    {
        return [
            'balance.numeric' => 'Balance must be a number.',
            'balance.min' => 'Balance must be at least 0.',
            'balance.max' => 'Balance exceeds the maximum allowed value.',
            'symbol.string' => 'Symbol must be a string.',
            'symbol.max' => 'Symbol may not be greater than 16 characters.',
            'symbol.regex' => 'Symbol must be alphanumeric.',
            'amount.numeric' => 'Amount must be a number.',
            'amount.min' => 'Amount must be at least 0.',
            'amount.max' => 'Amount exceeds the maximum allowed value.',
        ];
    }
}
