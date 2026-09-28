<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'comp_name' => ['required', 'string', 'max:255'],
            'comp_number' => ['required', 'string', 'max:255'],
            'adress' => ['required', 'string', 'max:255'],
            'comp_phone' => ['required', 'string', 'max:30'],
            'comp_email' => ['required', 'email', 'max:255'],
            'region_id' => ['required', 'exists:regions,id'],
        ];
    }

    /**
     * @return array{name: string, registration_number: string, address: string, phone: string, email: string, region_id: int}
     */
    public function companyData(): array
    {
        return [
            'name' => $this->string('comp_name')->trim()->toString(),
            'registration_number' => $this->string('comp_number')->trim()->toString(),
            'address' => $this->string('adress')->trim()->toString(),
            'phone' => $this->string('comp_phone')->trim()->toString(),
            'email' => $this->string('comp_email')->trim()->toString(),
            'region_id' => $this->integer('region_id'),
        ];
    }
}
