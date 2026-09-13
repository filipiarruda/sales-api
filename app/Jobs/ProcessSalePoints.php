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

    /**
     * Create a new job instance.
     */
    public function __construct(public int $saleId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::transaction(function (): void {
            $sale = Sale::query()->lockForUpdate()->findOrFail($this->saleId);

            if ($sale->points_processed_at !== null) {
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
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 60];
    }

    public function failed(Throwable $exception): void
    {
        $sale = Sale::query()->find($this->saleId);

        Log::error('Sale points processing failed.', [
            'sale_id' => $this->saleId,
            'external_id' => $sale?->external_id,
            'customer_id' => $sale?->customer_id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
