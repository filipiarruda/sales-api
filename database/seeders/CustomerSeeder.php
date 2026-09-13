<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Customer::query()->updateOrCreate(['id' => 145], ['name' => 'Cliente Demonstração 145', 'points_balance' => 0]);
        Customer::query()->updateOrCreate(['id' => 178], ['name' => 'Cliente Demonstração 178', 'points_balance' => 0]);
    }
}
