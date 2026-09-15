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

    public function messages(): array
    {
        return [
            'file.required' => 'O arquivo CSV é obrigatório.',
            'file.file' => 'O arquivo enviado é inválido.',
            'file.mimes' => 'O arquivo deve estar no formato CSV.',
            'file.max' => 'O arquivo CSV não pode ser maior que 10 MB.',
        ];
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
