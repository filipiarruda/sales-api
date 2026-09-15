<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSalePoints implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $saleId) {}

    public function handle(): void
    {
        try {
            DB::transaction(function (): void {
                $sale = Sale::query()->lockForUpdate()->findOrFail($this->saleId);

                if ($sale->points_processed_at !== null) {
                    Log::info('O processamento dos pontos de venda foi ignorado porque já havia sido processado.', [
                        'sale_id' => $sale->id,
                        'external_id' => $sale->external_id,
                        'customer_id' => $sale->customer_id,
                        'points_processed_at' => $sale->points_processed_at->toIso8601String(),
                    ]);

                    return;
                }

                $points = intdiv($sale->amount, 1000);

                if ($points > 0) {
                    Customer::query()->whereKey($sale->customer_id)->increment('points_balance', $points);
                }

                $sale->update([
                    'points_awarded' => $points,
                    'points_processed_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $sale = Sale::query()->find($this->saleId);

            Log::error('Tentativa de processamento dos pontos da venda falhou.', [
                'sale_id' => $this->saleId,
                'external_id' => $sale?->external_id,
                'customer_id' => $sale?->customer_id,
                'attempt' => $this->attempts(),
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 60];
    }

    public function failed(Throwable $exception): void
    {
        $sale = Sale::query()->find($this->saleId);

        Log::error('A Job de pontos da venda esgotou todas as tentativas.', [
            'sale_id' => $this->saleId,
            'external_id' => $sale?->external_id,
            'customer_id' => $sale?->customer_id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
