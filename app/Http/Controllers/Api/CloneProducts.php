<?php

namespace App\Http\Controllers\Api;

use App\Jobs\CloneProductsJob;
use App\Models\Cards;
use App\Models\Category;
use App\Models\ProductQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;

class CloneProducts
{
    private const DAILY_CARD_LIMIT = 1000;

    public function start(Request $request): JsonResponse
    {
        $sellerId = $request->input('seller_id') ?? Session::get('seller');
        $cardsToday = 0;
        if ($sellerId) {
            $cardsToday = Cards::query()
                ->where('sellerID', $sellerId)
                ->whereNotNull('wb_created_at')
                ->whereDate('wb_created_at', now())
                ->count();
        }

        $queueOnly = $request->boolean('queue_only');
        $maxQuantity = $queueOnly
            ? 5000
            : max(0, self::DAILY_CARD_LIMIT - $cardsToday);

        $validated = $request->validate([
            'supplier_id' => 'required|integer',
            'quantity' => 'required|integer|min:1|max:'.$maxQuantity,
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'batch_size' => 'nullable|integer|min:1|max:100',
            'in_stock_only' => 'nullable|int:0,1',
            'prefix' => 'nullable|string|max:10',
            'seller_id' => 'nullable|integer',
            'queue_only' => 'nullable|boolean',
        ]);
        $validated['in_stock_only'] = (bool) ($validated['in_stock_only'] ?? false);
        $validated['queue_only'] = $queueOnly;
        $validated['seller_id'] = $validated['seller_id'] ?? $sellerId;
        $jobId = uniqid('clone_');
        $logFile = "clone_logs/{$jobId}.log";
        Storage::disk('local')->put($logFile, 'Джоба запущена: '.now()."\n");
        CloneProductsJob::dispatch($validated, $jobId)->onQueue('cloneProducts');

        return response()->json([
            'job_id' => $jobId,
            'message' => 'Джоба успешно запущена',
        ]);
    }

    /**
     * Отправка ранее накопленной product_queues в WB (лимит — по дневному лимиту карточек).
     */
    public function processQueue(Request $request): JsonResponse
    {
        $sellerId = $request->input('seller_id') ?? Session::get('seller');
        $cardsToday = 0;
        if ($sellerId) {
            $cardsToday = Cards::query()
                ->where('sellerID', $sellerId)
                ->whereNotNull('wb_created_at')
                ->whereDate('wb_created_at', now())
                ->count();
        }

        $maxQuantity = max(0, self::DAILY_CARD_LIMIT - $cardsToday);

        if ($maxQuantity <= 0) {
            return response()->json(['message' => 'Достигнут дневной лимит карточек для магазина'], 422);
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:'.$maxQuantity,
            'batch_size' => 'nullable|integer|min:1|max:100',
            'seller_id' => 'nullable|integer',
        ]);

        $validated['seller_id'] = $validated['seller_id'] ?? $sellerId;
        if (empty($validated['seller_id'])) {
            return response()->json(['message' => 'Не выбран магазин (seller_id)'], 422);
        }

        $validated['send_queue_to_wb'] = true;

        $jobId = uniqid('clone_');
        $logFile = "clone_logs/{$jobId}.log";
        Storage::disk('local')->put($logFile, 'Джоба отправки очереди запущена: '.now()."\n");
        CloneProductsJob::dispatch($validated, $jobId)->onQueue('cloneProducts');

        return response()->json([
            'job_id' => $jobId,
            'message' => 'Джоба отправки очереди запущена',
        ]);
    }

    /**
     * Только обход очереди клонирования для восстановления сирот (getCardInfo + привязка sku/mapping).
     * Обрабатываются все позиции, включая blocked; отправка в WB не выполняется.
     */
    public function startOrphanScan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:10000',
            'batch_size' => 'nullable|integer|min:1|max:100',
            'seller_id' => 'nullable|integer',
        ]);

        $sellerId = $validated['seller_id'] ?? Session::get('seller');
        if (empty($sellerId)) {
            return response()->json(['message' => 'Не выбран магазин (seller_id)'], 422);
        }

        $validated['seller_id'] = (int) $sellerId;
        $validated['orphan_scan_only'] = true;

        $jobId = uniqid('clone_');
        $logFile = "clone_logs/{$jobId}.log";
        Storage::disk('local')->put($logFile, 'Джоба проверки сирот по очереди запущена: '.now()."\n");
        CloneProductsJob::dispatch($validated, $jobId)->onQueue('cloneProducts');

        return response()->json([
            'job_id' => $jobId,
            'message' => 'Джоба проверки сирот запущена',
        ]);
    }

    /**
     * Обход категорий WB выбранного поставщика: для товаров из выдачи — getCardInfo, при совпадении с сиротой магазина — восстановление.
     * Не создаёт очередь и не загружает карточки в WB.
     */
    public function startOrphanCatalogScan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|integer',
            'quantity' => 'required|integer|min:1|max:500000',
            'in_stock_only' => 'nullable|boolean',
            'seller_id' => 'nullable|integer',
            /** Только категории с checked=0; остальные в БД не трогаем (без массового сброса). */
            'orphan_catalog_retry_unchecked_only' => 'nullable|boolean',
        ]);

        $sellerId = $validated['seller_id'] ?? Session::get('seller');
        if (empty($sellerId)) {
            return response()->json(['message' => 'Не выбран магазин (seller_id)'], 422);
        }

        $validated['seller_id'] = (int) $sellerId;
        $validated['orphan_catalog_scan_only'] = true;
        $validated['in_stock_only'] = (bool) ($validated['in_stock_only'] ?? false);
        $validated['orphan_catalog_retry_unchecked_only'] = (bool) ($validated['orphan_catalog_retry_unchecked_only'] ?? false);

        $jobId = uniqid('clone_');
        $logFile = "clone_logs/{$jobId}.log";
        Storage::disk('local')->put($logFile, 'Джоба проверки сирот по каталогу WB запущена: '.now()."\n");
        CloneProductsJob::dispatch($validated, $jobId)->onQueue('cloneProducts');

        return response()->json([
            'job_id' => $jobId,
            'message' => 'Джоба проверки сирот по каталогу WB запущена',
        ]);
    }

    /**
     * Статистика для страницы клонирования: сироты по магазину и очередь product_queues.
     */
    public function stats(Request $request): JsonResponse
    {
        $sellerId = $request->input('seller_id') ?? Session::get('seller');
        $orphanCardsCount = 0;
        if ($sellerId) {
            $orphanCardsCount = Cards::query()
                ->where('sellerID', $sellerId)
                ->where('orphan_for_clone', true)
                ->count();
        }

        $cloneQueueReadyCount = ProductQueue::query()->where('blocked', 0)->count();

        $categoriesUncheckedCount = Category::query()->where('checked', 0)->count();

        return response()->json([
            'orphan_cards_count' => $orphanCardsCount,
            'clone_queue_ready_count' => $cloneQueueReadyCount,
            'categories_unchecked_count' => $categoriesUncheckedCount,
        ]);
    }

    /**
     * Артикулы supplierVendorCode карточек-сирот текущего магазина (для буфера обмена на /competitorCards).
     */
    public function orphanSupplierVendorCodes(Request $request): JsonResponse
    {
        $sellerId = $request->input('seller_id') ?? Session::get('seller');
        if (empty($sellerId)) {
            return response()->json(['message' => 'Не выбран магазин'], 422);
        }

        if (! Schema::hasColumn('cards', 'orphan_for_clone')) {
            return response()->json(['codes' => [], 'count' => 0]);
        }

        $codes = Cards::query()
            ->where('sellerID', (int) $sellerId)
            ->where('orphan_for_clone', true)
            ->whereNotNull('supplierVendorCode')
            ->where('supplierVendorCode', '!=', '')
            ->orderBy('id')
            ->pluck('supplierVendorCode')
            ->map(static fn ($c) => trim((string) $c))
            ->filter(static fn ($c) => $c !== '')
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'codes' => $codes,
            'count' => count($codes),
        ]);
    }

    public function logs($request)
    {
        $logFile = "clone_logs/{$request['job_id']}.log";
        if (! Storage::disk('local')->exists($logFile)) {
            return response()->json([
                'logs' => [],
                'status' => 'not_found',
            ]);
        }
        $content = Storage::disk('local')->get($logFile);
        $lines = explode("\n", $content);
        array_pop($lines);
        $logs = [];
        foreach (array_slice($lines, -100, 100) as $line) {
            if (! empty($line)) {
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
                    'type' => $type,
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

        $orphanProgress = [
            'queue' => null,
            'catalog' => null,
        ];
        foreach ($lines as $line) {
            if ($line === '' || ! str_starts_with($line, 'ORPHAN_PROGRESS')) {
                continue;
            }
            if (preg_match('/^ORPHAN_PROGRESS\t(queue|catalog)\t(\d+)\t(\d+)/', $line, $m)) {
                $orphanProgress[$m[1]] = [
                    'done' => (int) $m[2],
                    'limit' => (int) $m[3],
                ];
            }
        }

        return response()->json([
            'logs' => $logs,
            'status' => $status,
            'total_lines' => count($lines),
            'orphan_progress' => $orphanProgress,
        ]);
    }
}
