<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class ProfileOrdersRequest extends FormRequest
{
    /**
     * Get validation rules for profile order filters.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string> Validation rules.
     */
    public function rules(): array
    {
        return [
            'symbol' => ['sometimes', 'string', 'max:16'],
            'status' => ['sometimes', 'in:open,filled,canceled,all'],
        ];
    }

    /**
     * Get validation error messages for profile order filters.
     *
     * @return array<string, string> Validation error messages.
     */
    public function messages(): array
    {
        return [
            'symbol.string' => 'Symbol must be a string.',
            'symbol.max' => 'Symbol may not be greater than 16 characters.',
            'status.in' => 'Status must be open, filled, canceled, or all.',
        ];
    }
}
