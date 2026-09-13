<?php

namespace Database\Factories;

use App\Models\CsvImport;
use App\Models\CsvImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CsvImportRow>
 */
class CsvImportRowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'csv_import_id' => CsvImport::factory(),
            'line_number' => 2,
            'external_id' => fake()->bothify('SALE-#####'),
            'status' => 'processed',
            'error_message' => null,
        ];
    }
}
