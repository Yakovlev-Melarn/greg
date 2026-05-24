<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SimaSupplierAuditRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'seller_id',
        'status',
        'total',
        'processed',
        'missing_mapping',
        'sima_cheaper',
        'not_on_wb',
        'switched_to_wb',
        'trashed',
        'skipped_low_stock',
        'wb_errors',
        'skipped_other',
        'job_id',
        'log_path',
        'force_reaudit',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected $casts = [
        'force_reaudit' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public static function hasActiveRunForSeller(int $sellerId): bool
    {
        return self::query()
            ->where('seller_id', $sellerId)
            ->where('status', self::STATUS_RUNNING)
            ->exists();
    }

    /**
     * @param  array<string, int>  $counterDeltas
     */
    public function recordCardOutcome(array $counterDeltas = []): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($counterDeltas) {
            $run = self::query()->whereKey($this->id)->lockForUpdate()->first();
            if ($run === null) {
                return;
            }

            $updates = ['processed' => $run->processed + 1];
            foreach ($counterDeltas as $column => $delta) {
                if ($delta > 0 && in_array($column, [
                    'missing_mapping', 'sima_cheaper', 'not_on_wb', 'switched_to_wb',
                    'trashed', 'skipped_low_stock', 'wb_errors', 'skipped_other',
                ], true)) {
                    $updates[$column] = $run->{$column} + $delta;
                }
            }

            self::query()->whereKey($run->id)->update($updates);
            $run->refresh();

            if ($run->total > 0 && $run->processed >= $run->total && $run->status === self::STATUS_RUNNING) {
                self::query()->whereKey($run->id)->where('status', self::STATUS_RUNNING)->update([
                    'status' => self::STATUS_COMPLETED,
                    'finished_at' => now(),
                ]);
                if ($run->log_path) {
                    \Illuminate\Support\Facades\Storage::disk('local')->append(
                        $run->log_path,
                        '['.now()->format('Y-m-d H:i:s')."] Джоба завершена\n",
                    );
                }
            }

            $this->setRawAttributes($run->getAttributes());
            $this->syncOriginal();
        });
    }
}
