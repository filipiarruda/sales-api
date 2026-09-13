<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class CustomerPointsController extends Controller
{
    public function show(Customer $customer): JsonResponse
    {
        return response()->json([
            'customer_id' => $customer->id,
            'points' => $customer->points_balance,
        ]);
    }
}
