<?php

namespace App\Http\Controllers\Api;

use App\Jobs\RecalculateSkuPricesJob;
use App\Models\SystemNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkuMapping
{
    public function recalculateWithMargin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profit_margin_percent' => 'required|numeric|min:0|max:99.99',
        ]);

        RecalculateSkuPricesJob::dispatch((float) $validated['profit_margin_percent'])
            ->onQueue('updateCardsProcess');

        SystemNotification::query()->create([
            'title' => 'Запущен пересчет цен',
            'message' => "Задача поставлена в очередь. Наценка: {$validated['profit_margin_percent']}%",
            'level' => 'info',
            'source' => 'recalculate_prices_job',
            'meta' => ['profit_margin_percent' => (float) $validated['profit_margin_percent']],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Пересчет цен запущен в фоне. Уведомление появится после завершения.',
            'profit_margin_percent' => (float) $validated['profit_margin_percent'],
        ]);
    }
}
