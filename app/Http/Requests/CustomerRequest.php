<?php

namespace App\Http\Requests;

use App\Support\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'document' => Cpf::normalize($this->input('document')),
            'email' => $this->input('email') ? strtolower(trim($this->input('email'))) : null,
            'state' => $this->input('state') ? strtoupper($this->input('state')) : null,
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('customer')?->id;

        return [
            'name' => 'required|string|max:255',
            'document' => ['nullable', 'digits:11', function ($attr, $value, $fail) {
                if ($value && ! Cpf::isValid($value)) {
                    $fail('CPF inválido.');
                }
            }, Rule::unique('customers', 'document_normalized')->ignore($id)],
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'zip_code' => 'nullable|string|max:9',
            'street' => 'nullable|string|max:255',
            'number' => 'nullable|string|max:20',
            'complement' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|size:2',
            'marketing_opt_in' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return ['document.unique' => 'Já existe um tutor com este CPF. Pesquise o cadastro existente em vez de criar outro.'];
    }

    /** @return array<string, mixed> */
    public function attributesForModel(): array
    {
        $d = $this->validated();

        return [
            'name' => $d['name'],
            'document_normalized' => $d['document'] ?? null,
            'email' => $d['email'] ?? null,
            'phone' => $d['phone'] ?? null,
            'zip_code' => $d['zip_code'] ?? null,
            'street' => $d['street'] ?? null,
            'number' => $d['number'] ?? null,
            'complement' => $d['complement'] ?? null,
            'district' => $d['district'] ?? null,
            'city' => $d['city'] ?? null,
            'state' => $d['state'] ?? null,
        ];
    }
}
