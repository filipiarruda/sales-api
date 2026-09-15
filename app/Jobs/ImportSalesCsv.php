<?php

namespace App\Jobs;

use App\Models\CsvImport;
use App\Models\CsvImportRow;
use App\Services\CreateSaleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use SplFileObject;
use Throwable;

class ImportSalesCsv implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $csvImportId) {}

    public function handle(CreateSaleService $createSale): void
    {
        $import = CsvImport::query()->findOrFail($this->csvImportId);
        $import->update(['status' => 'processing', 'started_at' => $import->started_at ?? now()]);

        Log::info('Importação de arquivoCSV iniciada.', [
            'csv_import_id' => $import->id,
            'filename' => $import->filename,
        ]);

        try {
            $file = new SplFileObject(Storage::disk('local')->path($import->path));
            $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

            $header = $file->fgetcsv();
            $expectedHeader = ['external_id', 'customer_id', 'amount', 'occurred_at'];

            if ($header === false || $this->normalizeHeader($header) !== $expectedHeader) {
                throw new \UnexpectedValueException('O cabeçalho do CSV deve ser external_id,customer_id,amount,occurred_at.');
            }

            $lineNumber = 1;

            while (! $file->eof()) {
                $row = $file->fgetcsv();
                $lineNumber++;

                if ($row === false || $row === [null]) {
                    continue;
                }

                $this->processRow($import, $lineNumber, $expectedHeader, $row, $createSale);
            }

            $this->finishImport($import);
        } catch (Throwable $exception) {
            $import->update(['status' => 'failed', 'finished_at' => now()]);

            Log::error('CSV import failed.', [
                'csv_import_id' => $import->id,
                'filename' => $import->filename,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('CSV import exhausted all retries.', [
            'csv_import_id' => $this->csvImportId,
            'exception' => $exception->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [5, 30, 60];
    }

    private function normalizeHeader(array $header): array
    {
        return array_map(
            fn (?string $value): string => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $value ?? '')),
            $header,
        );
    }

    private function processRow(CsvImport $import, int $lineNumber, array $header, array $row, CreateSaleService $createSale): void
    {
        $existingRow = CsvImportRow::query()
            ->where('csv_import_id', $import->id)
            ->where('line_number', $lineNumber)
            ->first();

        if ($existingRow !== null && $existingRow->status !== 'failed') {
            return;
        }

        $externalId = isset($row[0]) ? trim((string) $row[0]) : null;

        if (count($row) !== count($header)) {
            $message = 'CSV row has an invalid number of columns.';
            $this->recordRow($import, $lineNumber, $externalId, 'invalid', $message);
            $this->logInvalidRow($import, $lineNumber, $externalId, [$message]);

            return;
        }

        $data = array_combine($header, array_map(fn (?string $value): string => trim((string) $value), $row));
        $validator = Validator::make($data, CreateSaleService::validationRules());

        if ($validator->fails()) {
            $errors = $validator->errors()->all();
            $this->recordRow($import, $lineNumber, $externalId, 'invalid', implode(' ', $errors));
            $this->logInvalidRow($import, $lineNumber, $externalId, $errors);

            return;
        }

        try {
            $sale = $createSale->create($validator->validated(), 'csv');
            $this->recordRow($import, $lineNumber, $externalId, $sale->wasRecentlyCreated ? 'processed' : 'duplicate');
        } catch (Throwable $exception) {
            $this->recordRow($import, $lineNumber, $externalId, 'failed', $exception->getMessage());

            Log::warning('CSV row processing failed.', [
                'csv_import_id' => $import->id,
                'line_number' => $lineNumber,
                'external_id' => $externalId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function recordRow(CsvImport $import, int $lineNumber, ?string $externalId, string $status, ?string $errorMessage = null): void
    {
        CsvImportRow::query()->updateOrCreate(
            ['csv_import_id' => $import->id, 'line_number' => $lineNumber],
            ['external_id' => $externalId, 'status' => $status, 'error_message' => $errorMessage],
        );
    }

    private function logInvalidRow(CsvImport $import, int $lineNumber, ?string $externalId, array $errors): void
    {
        Log::warning('CSV import row is invalid.', [
            'csv_import_id' => $import->id,
            'line_number' => $lineNumber,
            'external_id' => $externalId,
            'validation_errors' => $errors,
        ]);
    }

    private function finishImport(CsvImport $import): void
    {
        $rows = CsvImportRow::query()->where('csv_import_id', $import->id);
        $processedRows = (clone $rows)->where('status', 'processed')->count();
        $ignoredRows = (clone $rows)->where('status', 'duplicate')->count();
        $failedRows = (clone $rows)->whereIn('status', ['invalid', 'failed'])->count();

        $import->update([
            'status' => $failedRows > 0 ? 'completed_with_errors' : 'completed',
            'total_rows' => $processedRows + $ignoredRows + $failedRows,
            'processed_rows' => $processedRows,
            'ignored_rows' => $ignoredRows,
            'failed_rows' => $failedRows,
            'finished_at' => now(),
        ]);

        Log::info('CSV import completed.', [
            'csv_import_id' => $import->id,
            'status' => $failedRows > 0 ? 'completed_with_errors' : 'completed',
            'total_rows' => $processedRows + $ignoredRows + $failedRows,
            'processed_rows' => $processedRows,
            'ignored_rows' => $ignoredRows,
            'failed_rows' => $failedRows,
        ]);
    }
}
