<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
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
            'customer_name' => [
                'required',
                'string',
                'max:150',
            ],
            'customer_phone' => [
                'required',
                'string',
                'max:50',
                'regex:/^[0-9+\-()\s]{7,25}$/',
            ],
            'customer_email' => [
                'nullable',
                'email:rfc',
                'max:255',
            ],
            'delivery_method' => [
                'required',
                Rule::in([
                    'delivery',
                    'pickup',
                ]),
            ],
            'delivery_address' => [
                'nullable',
                'required_if:delivery_method,delivery',
                'string',
                'max:1000',
            ],
            'payment_method' => [
                'required',
                Rule::in([
                    'cash',
                    'bank_transfer',
                ]),
            ],
            'customer_comment' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_name.required' =>
                'Укажите имя получателя.',
            'customer_phone.required' =>
                'Укажите номер телефона.',
            'customer_phone.regex' =>
                'Проверьте формат номера телефона.',
            'customer_email.email' =>
                'Проверьте адрес электронной почты.',
            'delivery_method.required' =>
                'Выберите способ получения.',
            'delivery_method.in' =>
                'Выбран неизвестный способ получения.',
            'delivery_address.required_if' =>
                'Укажите адрес доставки.',
            'payment_method.required' =>
                'Выберите способ оплаты.',
            'payment_method.in' =>
                'Выбран неизвестный способ оплаты.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'customer_name' => trim(
                (string) $this->input('customer_name')
            ),
            'customer_phone' => trim(
                (string) $this->input('customer_phone')
            ),
            'customer_email' => trim(
                (string) $this->input('customer_email')
            ),
            'delivery_address' => trim(
                (string) $this->input('delivery_address')
            ),
            'customer_comment' => trim(
                (string) $this->input('customer_comment')
            ),
        ]);
    }
}
