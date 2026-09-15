<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StoreSalesCsvImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('Payload do upload do CSV é inválido.', [
            'source' => 'csv',
            'validation_errors' => $validator->errors()->toArray(),
        ]);

        parent::failedValidation($validator);
    }
}
