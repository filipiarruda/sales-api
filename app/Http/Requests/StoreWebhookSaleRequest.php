<?php

namespace App\Http\Requests;

use App\Services\CreateSaleService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StoreWebhookSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return CreateSaleService::validationRules();
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('Payload do webhook de venda é inválido.', [
            'source' => 'webhook',
            'external_id' => $this->input('external_id'),
            'validation_errors' => $validator->errors()->toArray(),
        ]);

        parent::failedValidation($validator);
    }
}
