<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'csv_import_id',
        'line_number',
        'external_id',
        'status',
        'error_message',
    ];

    public function csvImport(): BelongsTo
    {
        return $this->belongsTo(CsvImport::class);
    }
}
