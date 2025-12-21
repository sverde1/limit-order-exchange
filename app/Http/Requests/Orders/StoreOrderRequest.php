<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    /**
     * Get validation rules for creating an order.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string> Validation rules.
     */
    public function rules(): array
    {
        return [
            'symbol' => ['required', 'string', 'max:16', 'regex:/^[\w\d]+$/'],
            'side' => ['required', 'in:buy,sell'],
            'price' => ['required', 'decimal:0,8', 'gt:0', 'max:9999999999.99999999'],
            'amount' => ['required', 'decimal:0,18', 'gt:0', 'max:999999999999999999.999999999999999999'],
        ];
    }

    /**
     * Get validation error messages for creating an order.
     *
     * @return array<string, string> Validation error messages.
     */
    public function messages(): array
    {
        return [
            'symbol.required' => 'Symbol is required.',
            'symbol.string' => 'Symbol must be a string.',
            'symbol.max' => 'Symbol may not be greater than 16 characters.',
            'symbol.regex' => 'Symbol must be alphanumeric.',
            'side.required' => 'Side is required.',
            'side.in' => 'Side must be buy or sell.',
            'price.required' => 'Price is required.',
            'price.decimal' => 'Price must have up to 8 decimal places.',
            'price.gt' => 'Price must be greater than zero.',
            'price.max' => 'Price exceeds the maximum allowed value.',
            'amount.required' => 'Amount is required.',
            'amount.decimal' => 'Amount must have up to 18 decimal places.',
            'amount.gt' => 'Amount must be greater than zero.',
            'amount.max' => 'Amount exceeds the maximum allowed value.',
        ];
    }
}
