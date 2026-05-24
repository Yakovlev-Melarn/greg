<?php

namespace Tests\Feature\Api;

use App\Jobs\SimaSupplierAuditCoordinatorJob;
use App\Models\Sellers;
use App\Models\SimaSupplierAuditRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SimaSupplierAuditApiTest extends TestCase
{
    use RefreshDatabase;

    private function createSeller(): Sellers
    {
        $seller = new Sellers;
        $seller->name = 'Audit Seller';
        $seller->wb_api_key = 'test-key';
        $seller->save();

        return $seller;
    }

    #[TestDox('start без seller_id — 422')]
    public function test_start_requires_seller(): void
    {
        $response = $this->postJson('/api/sima-supplier-audit/start/', []);
        $response->assertStatus(422);
    }

    #[TestDox('start при активном прогоне — 422')]
    public function test_start_rejects_when_running(): void
    {
        $seller = $this->createSeller();
        SimaSupplierAuditRun::query()->create([
            'seller_id' => $seller->id,
            'status' => SimaSupplierAuditRun::STATUS_RUNNING,
            'job_id' => 'sima_audit_existing',
            'log_path' => 'sima_audit_logs/sima_audit_existing.log',
        ]);

        $response = $this->postJson('/api/sima-supplier-audit/start/', [
            'seller_id' => $seller->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Аудит уже выполняется для этого магазина');
    }

    #[TestDox('start — job_id и постановка координатора в очередь')]
    public function test_start_dispatches_coordinator(): void
    {
        Queue::fake();
        $seller = $this->createSeller();

        $response = $this->postJson('/api/sima-supplier-audit/start/', [
            'seller_id' => $seller->id,
            'force_reaudit' => false,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['job_id', 'run_id', 'message']);
        $this->assertStringStartsWith('sima_audit_', $response->json('job_id'));

        Queue::assertPushed(SimaSupplierAuditCoordinatorJob::class, function ($job) use ($seller) {
            return $job->sellerId === $seller->id;
        });

        $this->assertDatabaseHas('sima_supplier_audit_runs', [
            'seller_id' => $seller->id,
            'status' => SimaSupplierAuditRun::STATUS_RUNNING,
        ]);
    }

    #[TestDox('status возвращает последний прогон')]
    public function test_status_returns_run(): void
    {
        $seller = $this->createSeller();
        SimaSupplierAuditRun::query()->create([
            'seller_id' => $seller->id,
            'status' => SimaSupplierAuditRun::STATUS_COMPLETED,
            'job_id' => 'sima_audit_done',
            'total' => 10,
            'processed' => 10,
            'switched_to_wb' => 2,
        ]);

        $response = $this->postJson('/api/sima-supplier-audit/status/', [
            'seller_id' => $seller->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('run.status', 'completed')
            ->assertJsonPath('run.switched_to_wb', 2)
            ->assertJsonPath('run.progress_percent', 100);
    }
}
