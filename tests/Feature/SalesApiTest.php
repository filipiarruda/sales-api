<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWebhookSignature;
use App\Jobs\ImportSalesCsv;
use App\Jobs\ProcessSalePoints;
use App\Models\CsvImport;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\CreateSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.partner_webhook.secret' => 'test-secret']);
    }

    public function test_valid_webhook_creates_sale(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create();
        $payload = $this->salePayload($customer);

        $response = $this->withHeader('X-Webhook-Signature', $this->signature($payload))
            ->postJson('/api/webhooks/sales', $payload);

        $response->assertCreated()->assertJsonPath('status', 'created');
        $this->assertDatabaseHas('sales', ['external_id' => $payload['external_id'], 'amount' => 35000]);
        Queue::assertPushed(ProcessSalePoints::class);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $customer = Customer::factory()->create();

        $this->withHeader('X-Webhook-Signature', 'invalid')
            ->postJson('/api/webhooks/sales', $this->salePayload($customer))
            ->assertUnauthorized();
    }

    public function test_webhook_validates_payload_and_existing_customer(): void
    {
        Log::spy();
        $payload = $this->salePayload();
        $payload['customer_id'] = 999999;
        $payload['amount'] = 0;

        $this->withHeader('X-Webhook-Signature', $this->signature($payload))
            ->postJson('/api/webhooks/sales', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'amount']);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Webhook sale payload is invalid.'
                && $context['source'] === 'webhook',
        );
    }

    public function test_duplicate_webhook_creates_one_sale_and_dispatches_once(): void
    {
        Queue::fake();
        Log::spy();
        $customer = Customer::factory()->create();
        $payload = $this->salePayload($customer);

        $this->withHeader('X-Webhook-Signature', $this->signature($payload))->postJson('/api/webhooks/sales', $payload)->assertCreated();
        $this->withHeader('X-Webhook-Signature', $this->signature($payload))->postJson('/api/webhooks/sales', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertDatabaseCount('sales', 1);
        Queue::assertPushed(ProcessSalePoints::class, 1);
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Sale duplicate ignored.'
                && $context['external_id'] === $payload['external_id'],
        );
    }

    public function test_points_job_is_idempotent_and_discards_fractional_points(): void
    {
        Log::spy();
        $customer = Customer::factory()->create();
        $sale = Sale::factory()->for($customer)->create(['amount' => 35500]);
        $job = new ProcessSalePoints($sale->id);

        $job->handle();
        $job->handle();

        $this->assertSame(35, $customer->fresh()->points_balance);
        $this->assertSame(35, $sale->fresh()->points_awarded);
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Sale points processing skipped because it was already processed.'
                && $context['sale_id'] === $sale->id,
        );
    }

    public function test_two_sales_increment_the_same_customer_correctly(): void
    {
        $customer = Customer::factory()->create();
        $first = Sale::factory()->for($customer)->create(['amount' => 35000]);
        $second = Sale::factory()->for($customer)->create(['amount' => 12050]);

        (new ProcessSalePoints($first->id))->handle();
        (new ProcessSalePoints($second->id))->handle();

        $this->assertSame(47, $customer->fresh()->points_balance);
    }

    public function test_points_endpoint_requires_authentication_and_returns_balance(): void
    {
        $customer = Customer::factory()->create(['points_balance' => 35]);
        $this->getJson('/api/customers/'.$customer->id.'/points')->assertUnauthorized();

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/customers/'.$customer->id.'/points')
            ->assertOk()
            ->assertExactJson(['customer_id' => $customer->id, 'points' => 35]);
    }

    public function test_csv_import_records_valid_invalid_and_duplicate_rows(): void
    {
        Queue::fake();
        Storage::fake('local');
        Log::spy();
        $customer = Customer::factory()->create();
        $csv = implode("\n", [
            'external_id,customer_id,amount,occurred_at',
            'CSV-1,'.$customer->id.',350.00,2026-08-20T14:30:00',
            'CSV-INVALID,missing,10.00,2026-08-20T14:30:00',
            'CSV-1,'.$customer->id.',350.00,2026-08-20T14:30:00',
        ]);

        $this->withoutMiddleware(VerifyWebhookSignature::class)
            ->postJson('/api/imports/sales', ['file' => UploadedFile::fake()->createWithContent('sales.csv', $csv)])
            ->assertAccepted();

        $import = CsvImport::query()->firstOrFail();
        (new ImportSalesCsv($import->id))->handle(app(CreateSaleService::class));

        $this->assertSame('completed_with_errors', $import->fresh()->status);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('csv_import_rows', ['csv_import_id' => $import->id, 'status' => 'processed']);
        $this->assertDatabaseHas('csv_import_rows', ['csv_import_id' => $import->id, 'status' => 'invalid']);
        $this->assertDatabaseHas('csv_import_rows', ['csv_import_id' => $import->id, 'status' => 'duplicate']);

        $sale = Sale::query()->where('external_id', 'CSV-1')->firstOrFail();
        (new ProcessSalePoints($sale->id))->handle();

        $this->assertSame(35, $customer->fresh()->points_balance);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'CSV import row is invalid.'
                && $context['csv_import_id'] === $import->id
                && $context['line_number'] === 3,
        );
    }

    public function test_csv_treats_a_webhook_sale_as_duplicate(): void
    {
        Queue::fake();
        Storage::fake('local');
        $customer = Customer::factory()->create();
        Sale::factory()->for($customer)->create(['external_id' => 'EXISTING-SALE']);
        $path = 'imports/sales/existing.csv';
        Storage::disk('local')->put($path, "external_id,customer_id,amount,occurred_at\nEXISTING-SALE,{$customer->id},10.00,2026-08-20T14:30:00");
        $import = CsvImport::factory()->create(['path' => $path]);

        (new ImportSalesCsv($import->id))->handle(app(CreateSaleService::class));

        $this->assertDatabaseHas('csv_import_rows', ['csv_import_id' => $import->id, 'status' => 'duplicate']);
    }

    public function test_csv_retries_preserve_existing_row_audit(): void
    {
        Queue::fake();
        Storage::fake('local');
        $customer = Customer::factory()->create();
        $path = 'imports/sales/retry.csv';
        Storage::disk('local')->put($path, "external_id,customer_id,amount,occurred_at\nCSV-RETRY,{$customer->id},10.00,2026-08-20T14:30:00");
        $import = CsvImport::factory()->create(['path' => $path]);
        $job = new ImportSalesCsv($import->id);

        $job->handle(app(CreateSaleService::class));
        $job->handle(app(CreateSaleService::class));

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('csv_import_rows', [
            'csv_import_id' => $import->id,
            'line_number' => 2,
            'status' => 'processed',
        ]);
    }

    public function test_csv_with_invalid_header_is_marked_as_failed(): void
    {
        Storage::fake('local');
        $path = 'imports/sales/invalid-header.csv';
        Storage::disk('local')->put($path, "customer_id,amount\n145,10.00");
        $import = CsvImport::factory()->create(['path' => $path]);

        try {
            (new ImportSalesCsv($import->id))->handle(app(CreateSaleService::class));
        } catch (\UnexpectedValueException) {
        }

        $this->assertSame('failed', $import->fresh()->status);
    }

    public function test_sale_processing_failure_is_logged_and_rethrown(): void
    {
        Log::spy();

        try {
            (new ProcessSalePoints(999999))->handle();
            $this->fail('The missing sale should throw an exception.');
        } catch (\Throwable) {
        }

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Sale points processing attempt failed.'
                && $context['sale_id'] === 999999,
        );
    }

    /** @return array<string, int|string|float> */
    private function salePayload(?Customer $customer = null): array
    {
        return [
            'external_id' => 'SALE-92831',
            'customer_id' => $customer?->id ?? 1,
            'amount' => 350.00,
            'occurred_at' => '2026-08-20T14:30:00',
        ];
    }

    /** @param array<string, int|string|float> $payload */
    private function signature(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), 'test-secret');
    }
}
