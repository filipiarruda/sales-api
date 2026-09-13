<?php

namespace Database\Factories;

use App\Models\CsvImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CsvImport>
 */
class CsvImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filename' => 'sales.csv',
            'path' => 'imports/sales/example.csv',
            'status' => 'pending',
            'total_rows' => 0,
            'processed_rows' => 0,
            'ignored_rows' => 0,
            'failed_rows' => 0,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
