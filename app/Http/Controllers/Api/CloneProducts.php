<?php

namespace App\Http\Controllers\Api;

use App\Jobs\CloneProductsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CloneProducts
{
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|integer',
            'quantity' => 'required|integer|min:1|max:1000',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'batch_size' => 'nullable|integer|min:1|max:100',
            'in_stock_only' => 'nullable|int:0,1',
            'prefix' => 'nullable|string|max:10',
            'seller_id' => 'nullable|integer'
        ]);
        $validated['in_stock_only'] = (bool)($validated['in_stock_only'] ?? false);
        $jobId = uniqid('clone_');
        $logFile = "clone_logs/{$jobId}.log";
        Storage::disk('local')->put($logFile, "Джоба запущена: " . now() . "\n");
        CloneProductsJob::dispatch($validated, $jobId)->onQueue('cloneProducts');
        return response()->json([
            'job_id' => $jobId,
            'message' => 'Джоба успешно запущена'
        ]);
    }

    public function logs($request)
    {
        $logFile = "clone_logs/{$request['job_id']}.log";
        if (!Storage::disk('local')->exists($logFile)) {
            return response()->json([
                'logs' => [],
                'status' => 'not_found'
            ]);
        }
        $content = Storage::disk('local')->get($logFile);
        $lines = explode("\n", $content);
        array_pop($lines);
        $logs = [];
        foreach (array_slice($lines, -100, 100) as $line) {
            if (!empty($line)) {
                // Определение типа сообщения
                $type = 'info';
                if (str_contains($line, '❌') || str_contains($line, 'ERROR')) {
                    $type = 'error';
                } elseif (str_contains($line, '✅') || str_contains($line, 'SUCCESS')) {
                    $type = 'success';
                } elseif (str_contains($line, '⚠️') || str_contains($line, 'WARNING')) {
                    $type = 'warning';
                }
                $logs[] = [
                    'message' => $line,
                    'type' => $type
                ];
            }
        }
        $lastLine = end($lines);
        $status = 'running';
        if (str_contains($lastLine, 'completed') || str_contains($lastLine, 'Джоба завершена')) {
            $status = 'completed';
        } elseif (str_contains($lastLine, 'failed') || str_contains($lastLine, 'Джоба завершена с ошибкой')) {
            $status = 'failed';
        }
        return response()->json([
            'logs' => $logs,
            'status' => $status,
            'total_lines' => count($lines)
        ]);
    }
}
