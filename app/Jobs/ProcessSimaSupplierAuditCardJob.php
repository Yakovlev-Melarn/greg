<?php

namespace App\Jobs;

use App\Exceptions\SimaSupplierAuditWbException;
use App\Models\Cards;
use App\Models\Sellers;
use App\Models\SimaSupplierAuditRun;
use App\Services\SimaSupplierAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessSimaSupplierAuditCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $tries = 10;

    public function __construct(
        public int $runId,
        public int $cardId,
        public int $sellerId,
    ) {
        $this->onQueue(SimaSupplierAuditCoordinatorJob::QUEUE);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600, 900, 900, 900, 900];
    }

    public function handle(): void
    {
        $run = SimaSupplierAuditRun::query()->find($this->runId);
        if ($run === null || $run->status === SimaSupplierAuditRun::STATUS_FAILED) {
            return;
        }

        $card = Cards::query()
            ->where('id', $this->cardId)
            ->where('sellerID', $this->sellerId)
            ->where('supplier', 20)
            ->first();

        if ($card === null) {
            $run->recordCardOutcome(['skipped_other' => 1]);
            $this->appendLog($run, "⚠️ card_id={$this->cardId}: карточка не найдена или уже не Sima");

            return;
        }

        $seller = Sellers::query()->find($this->sellerId);
        if ($seller === null) {
            $run->recordCardOutcome(['skipped_other' => 1]);
            $this->appendLog($run, "⚠️ card_id={$this->cardId}: seller не найден");

            return;
        }

        try {
            $service = SimaSupplierAuditService::forSeller($seller);
            $result = $service->processCard($card, $seller);
            $run->recordCardOutcome($result->counterDeltas);
            $this->appendLog($run, $result->logLine);
        } catch (SimaSupplierAuditWbException $e) {
            throw $e;
        } catch (Throwable $e) {
            $run->recordCardOutcome(['wb_errors' => 1]);
            $this->appendLog($run, "❌ card_id={$this->cardId}: {$e->getMessage()}");
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = SimaSupplierAuditRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        $run->recordCardOutcome(['wb_errors' => 1]);
        $msg = $exception?->getMessage() ?? 'unknown';
        $this->appendLog($run, "❌ card_id={$this->cardId} failed permanently: {$msg}");
    }

    private function appendLog(SimaSupplierAuditRun $run, string $line): void
    {
        $logPath = $run->log_path ?? 'sima_audit_logs/'.$run->job_id.'.log';
        Storage::disk('local')->append($logPath, '['.now()->format('Y-m-d H:i:s').'] '.$line."\n");
    }
}
