<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class IndexOrdersRequest extends FormRequest
{
    /**
     * Get validation rules for listing orders.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string> Validation rules.
     */
    public function rules(): array
    {
        return [
            'symbol' => ['required', 'string', 'max:16', 'regex:/^[\w\d]+$/'],
        ];
    }

    /**
     * Get validation error messages for listing orders.
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
        ];
    }
}
