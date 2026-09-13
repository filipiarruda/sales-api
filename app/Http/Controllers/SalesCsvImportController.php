<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSalesCsvImportRequest;
use App\Jobs\ImportSalesCsv;
use App\Models\CsvImport;
use Illuminate\Http\JsonResponse;

class SalesCsvImportController extends Controller
{
    public function store(StoreSalesCsvImportRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $import = CsvImport::create([
            'filename' => $file->getClientOriginalName(),
            'path' => '',
        ]);

        $path = $file->storeAs('imports/sales', $import->id.'.csv');
        $import->update(['path' => $path]);

        ImportSalesCsv::dispatch($import->id);

        return response()->json([
            'import_id' => $import->id,
            'status' => $import->status,
        ], 202);
    }
}
