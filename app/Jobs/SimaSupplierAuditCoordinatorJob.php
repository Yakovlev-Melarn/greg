<?php

namespace App\Jobs;

use App\Models\Cards;
use App\Models\SimaSupplierAuditRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Очередь: php artisan queue:work --queue=simaSupplierAudit --timeout=0
 */
class SimaSupplierAuditCoordinatorJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'simaSupplierAudit';

    public int $timeout = 0;

    public int $uniqueFor = 86400;

    public function __construct(
        public int $runId,
        public int $sellerId,
        public bool $forceReaudit = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return 'sima_supplier_audit_seller_'.$this->sellerId;
    }

    public function handle(): void
    {
        $run = SimaSupplierAuditRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        $logPath = $run->log_path ?? 'sima_audit_logs/'.$run->job_id.'.log';
        $this->appendLog($logPath, 'Координатор: сбор карточек Sima-Land (supplier=20)...');

        $cardIds = $this->collectCardIds();
        $total = count($cardIds);

        $run->update([
            'total' => $total,
            'status' => SimaSupplierAuditRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
        ]);

        $this->appendLog($logPath, "Найдено карточек к обработке: {$total}");

        if ($total === 0) {
            $run->update([
                'status' => SimaSupplierAuditRun::STATUS_COMPLETED,
                'finished_at' => now(),
            ]);
            $this->appendLog($logPath, 'Джоба завершена: нет карточек для аудита');

            return;
        }

        foreach ($cardIds as $index => $cardId) {
            ProcessSimaSupplierAuditCardJob::dispatch($this->runId, (int) $cardId, $this->sellerId)
                ->onQueue(self::QUEUE)
                ->delay(now()->addSeconds($index * 2));
        }

        $this->appendLog($logPath, "Поставлено в очередь {$total} карточек (задержка 2 с между задачами)");
    }

    /**
     * @return list<int>
     */
    private function collectCardIds(): array
    {
        $query = Cards::query()
            ->where('sellerID', $this->sellerId)
            ->where('supplier', 20)
            ->orderBy('id');

        if (! $this->forceReaudit && Schema::hasColumn('skuMapping', 'user_blocked')) {
            $query->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('skuMapping as sm')
                    ->whereColumn('sm.origSku', 'cards.vendorCode')
                    ->where('sm.user_blocked', true);
            });
        }

        return $query->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }

    private function appendLog(string $logPath, string $line): void
    {
        Storage::disk('local')->append($logPath, '['.now()->format('Y-m-d H:i:s').'] '.$line."\n");
    }
}
