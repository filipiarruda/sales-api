<?php

namespace App\Services;

use App\Jobs\ProcessSalePoints;
use App\Models\Sale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class CreateSaleService
{

    public static function validationRules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:100'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'occurred_at' => ['required', 'date'],
        ];
    }

    public function create(array $data, string $source): Sale
    {
        try {
            $sale = Sale::create([
                'external_id' => $data['external_id'],
                'customer_id' => $data['customer_id'],
                'amount' => $this->amountToCents((string) $data['amount']),
                'occurred_at' => $data['occurred_at'],
                'source' => $source,
            ]);
        } catch (UniqueConstraintViolationException) {
            $sale = Sale::query()->where('external_id', $data['external_id'])->firstOrFail();

            Log::info('Sale duplicate ignored.', [
                'sale_id' => $sale->id,
                'external_id' => $sale->external_id,
                'customer_id' => $sale->customer_id,
                'source' => $source,
            ]);

            return $sale;
        }

        ProcessSalePoints::dispatch($sale->id);

        return $sale;
    }

    private function amountToCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
