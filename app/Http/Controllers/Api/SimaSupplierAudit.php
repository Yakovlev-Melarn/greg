<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SimaSupplierAuditCoordinatorJob;
use App\Models\SimaSupplierAuditRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;

class SimaSupplierAudit
{
    public function start(Request $request): JsonResponse
    {
        $sellerId = (int) ($request->input('seller_id') ?? Session::get('seller') ?? 0);
        if ($sellerId <= 0) {
            return response()->json(['message' => 'Не выбран магазин (seller_id)'], 422);
        }

        if (SimaSupplierAuditRun::hasActiveRunForSeller($sellerId)) {
            return response()->json(['message' => 'Аудит уже выполняется для этого магазина'], 422);
        }

        $forceReaudit = $request->boolean('force_reaudit');
        $jobId = uniqid('sima_audit_');
        $logPath = "sima_audit_logs/{$jobId}.log";
        Storage::disk('local')->put($logPath, '['.now()->format('Y-m-d H:i:s')."] Аудит Sima-Land запущен\n");

        $run = SimaSupplierAuditRun::query()->create([
            'seller_id' => $sellerId,
            'status' => SimaSupplierAuditRun::STATUS_RUNNING,
            'job_id' => $jobId,
            'log_path' => $logPath,
            'force_reaudit' => $forceReaudit,
            'started_at' => now(),
        ]);

        SimaSupplierAuditCoordinatorJob::dispatch($run->id, $sellerId, $forceReaudit);

        return response()->json([
            'job_id' => $jobId,
            'run_id' => $run->id,
            'message' => 'Аудит Sima-Land запущен',
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $sellerId = (int) ($request->input('seller_id') ?? Session::get('seller') ?? 0);
        $run = null;
        if ($sellerId > 0) {
            $run = SimaSupplierAuditRun::query()
                ->where('seller_id', $sellerId)
                ->orderByDesc('id')
                ->first();
        } elseif ($request->filled('job_id')) {
            $run = SimaSupplierAuditRun::query()
                ->where('job_id', (string) $request->input('job_id'))
                ->first();
        }

        if ($run === null) {
            return response()->json(['run' => null]);
        }

        $progress = $run->total > 0
            ? round(100 * $run->processed / $run->total, 1)
            : ($run->status === SimaSupplierAuditRun::STATUS_COMPLETED ? 100 : 0);

        return response()->json([
            'run' => [
                'id' => $run->id,
                'job_id' => $run->job_id,
                'status' => $run->status,
                'total' => $run->total,
                'processed' => $run->processed,
                'progress_percent' => $progress,
                'missing_mapping' => $run->missing_mapping,
                'sima_cheaper' => $run->sima_cheaper,
                'not_on_wb' => $run->not_on_wb,
                'switched_to_wb' => $run->switched_to_wb,
                'trashed' => $run->trashed,
                'skipped_low_stock' => $run->skipped_low_stock,
                'wb_errors' => $run->wb_errors,
                'skipped_other' => $run->skipped_other,
                'force_reaudit' => $run->force_reaudit,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'error_message' => $run->error_message,
            ],
        ]);
    }

    public function runs(Request $request): JsonResponse
    {
        $sellerId = (int) ($request->input('seller_id') ?? Session::get('seller') ?? 0);
        if ($sellerId <= 0) {
            return response()->json(['message' => 'Не выбран магазин (seller_id)'], 422);
        }

        $perPage = max(1, min((int) ($request->input('per_page') ?? 10), 50));
        $page = max(1, (int) ($request->input('page') ?? 1));

        $paginator = SimaSupplierAuditRun::query()
            ->where('seller_id', $sellerId)
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'items' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $jobId = (string) ($request->input('job_id') ?? '');
        if ($jobId === '') {
            return response()->json(['logs' => [], 'status' => 'not_found']);
        }

        $run = SimaSupplierAuditRun::query()->where('job_id', $jobId)->first();
        $logFile = $run?->log_path ?? "sima_audit_logs/{$jobId}.log";

        if (! Storage::disk('local')->exists($logFile)) {
            return response()->json([
                'logs' => [],
                'status' => 'not_found',
            ]);
        }

        $content = Storage::disk('local')->get($logFile);
        $lines = explode("\n", $content);
        if (end($lines) === '') {
            array_pop($lines);
        }

        $logs = [];
        foreach (array_slice($lines, -100) as $line) {
            if ($line === '') {
                continue;
            }
            $type = 'info';
            if (str_contains($line, '❌') || str_contains($line, 'ERROR')) {
                $type = 'error';
            } elseif (str_contains($line, '✅') || str_contains($line, 'SUCCESS')) {
                $type = 'success';
            } elseif (str_contains($line, '⚠️') || str_contains($line, 'WARNING')) {
                $type = 'warning';
            }
            $logs[] = ['message' => $line, 'type' => $type];
        }

        $status = 'running';
        if ($run !== null) {
            if ($run->status === SimaSupplierAuditRun::STATUS_COMPLETED) {
                $status = 'completed';
            } elseif ($run->status === SimaSupplierAuditRun::STATUS_FAILED) {
                $status = 'failed';
            }
        } else {
            $lastLine = end($lines) ?: '';
            if (str_contains($lastLine, 'Джоба завершена')) {
                $status = 'completed';
            }
        }

        $progress = null;
        if ($run !== null && $run->total > 0) {
            $progress = [
                'done' => $run->processed,
                'total' => $run->total,
            ];
        }

        return response()->json([
            'logs' => $logs,
            'status' => $status,
            'total_lines' => count($lines),
            'progress' => $progress,
            'run' => $run ? [
                'processed' => $run->processed,
                'total' => $run->total,
                'missing_mapping' => $run->missing_mapping,
                'sima_cheaper' => $run->sima_cheaper,
                'not_on_wb' => $run->not_on_wb,
                'switched_to_wb' => $run->switched_to_wb,
                'trashed' => $run->trashed,
                'skipped_low_stock' => $run->skipped_low_stock,
                'wb_errors' => $run->wb_errors,
                'skipped_other' => $run->skipped_other,
            ] : null,
        ]);
    }
}
