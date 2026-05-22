<?php

namespace App\Http\Controllers\Api;

use App\Models\Cards;
use App\Models\Sellers;
use App\Models\SellerWarehouse;
use App\Models\SellerWarehouseStockHistory;
use App\Services\WildberriesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Seller
{
    /**
     * Поставщики карточек, допустимые в маршруте остатков склада.
     *
     * @var list<int>
     */
    public const STOCK_ROUTE_SUPPLIERS = [10, 20];

    /**
     * @deprecated Используйте STOCK_ROUTE_SUPPLIERS; оставлено для Rule::in по legacy полю supplier.
     *
     * @var list<int>
     */
    private const ALLOWED_SUPPLIERS = [20];

    public function list(Request $request): array
    {
        if ($request->boolean('with_warehouses')) {
            return Sellers::with('warehouses')->orderBy('id')->get()->map(function ($s) {
                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'wb_api_key' => $s->wb_api_key,
                    'warehouses' => $s->warehouses->map(fn ($w) => [
                        'id' => $w->id,
                        'wb_warehouse_id' => $w->wb_warehouse_id,
                        'name' => $w->name,
                        'supplier' => $w->supplier,
                        'stock_supplier_ids' => $w->effectiveStockSupplierIds(),
                        'sima_stock_via' => (string) ($w->sima_stock_via ?? SellerWarehouse::SIMA_STOCK_VIA_WB_CATALOG),
                        'stock_collect_enabled' => (bool) $w->stock_collect_enabled,
                        'stock_send_to_wb' => (bool) $w->stock_send_to_wb,
                        'stock_frequency_minutes' => (int) ($w->stock_frequency_minutes ?? 30),
                        'stock_last_run_at' => $w->stock_last_run_at?->toIso8601String(),
                        'stock_last_run_result' => $w->stock_last_run_result,
                    ])->values()->all(),
                ];
            })->values()->all();
        }

        $sellers = Sellers::all();
        if ($request->filled('fields')) {
            return self::filter($sellers, $request->input('fields'));
        }

        return $sellers->toArray();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'wb_api_key' => 'required|string',
            'warehouses' => 'nullable|array',
            'warehouses.*.wb_warehouse_id' => 'required|integer|min:1',
            'warehouses.*.name' => 'nullable|string|max:255',
            'warehouses.*.supplier' => ['nullable', 'integer', Rule::in(self::ALLOWED_SUPPLIERS)],
            'warehouses.*.stock_supplier_ids' => 'sometimes|array|min:1',
            'warehouses.*.stock_supplier_ids.*' => ['integer', Rule::in(self::STOCK_ROUTE_SUPPLIERS)],
            'warehouses.*.sima_stock_via' => ['sometimes', 'string', Rule::in([SellerWarehouse::SIMA_STOCK_VIA_SIMA_API, SellerWarehouse::SIMA_STOCK_VIA_WB_CATALOG])],
            'warehouses.*.stock_collect_enabled' => 'sometimes|boolean',
            'warehouses.*.stock_send_to_wb' => 'sometimes|boolean',
            'warehouses.*.stock_frequency_minutes' => 'sometimes|integer|min:5|max:1440',
        ]);

        $rows = $validated['warehouses'] ?? [];
        $normalizedRows = $this->normalizeWarehouseRowsPayload($rows);
        $this->assertNoStockSupplierOverlapInNestedRows($normalizedRows);
        $this->assertStockSettingsInRequest($rows);

        unset($validated['warehouses']);

        $seller = Sellers::create($validated);

        foreach ($rows as $i => $row) {
            $collect = (bool) ($row['stock_collect_enabled'] ?? false);
            $send = (bool) ($row['stock_send_to_wb'] ?? false);
            $ids = $normalizedRows[$i]['stock_supplier_ids'];
            $simaVia = $normalizedRows[$i]['sima_stock_via'];
            $seller->warehouses()->create([
                'wb_warehouse_id' => $row['wb_warehouse_id'],
                'name' => $row['name'] ?? null,
                'supplier' => SellerWarehouse::legacySupplierFromStockSupplierIds($ids),
                'stock_supplier_ids' => $ids,
                'sima_stock_via' => $simaVia,
                'stock_collect_enabled' => $collect,
                'stock_send_to_wb' => $send,
                'stock_frequency_minutes' => isset($row['stock_frequency_minutes']) ? (int) $row['stock_frequency_minutes'] : 30,
            ]);
        }

        return response()->json($seller->load('warehouses'), 201);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:sellers,id',
            'name' => 'sometimes|string|max:255',
            'wb_api_key' => 'sometimes|string',
        ]);

        $seller = Sellers::findOrFail($validated['id']);
        $seller->update(collect($validated)->except('id')->all());

        return response()->json($seller->fresh('warehouses'));
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:sellers,id',
        ]);

        Sellers::destroy($validated['id']);

        return response()->json(['success' => true]);
    }

    public function warehouseStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'seller_id' => 'required|integer|exists:sellers,id',
            'wb_warehouse_id' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('seller_warehouses', 'wb_warehouse_id')->where('seller_id', $request->input('seller_id')),
            ],
            'name' => 'nullable|string|max:255',
            'supplier' => ['nullable', 'integer', Rule::in(self::ALLOWED_SUPPLIERS)],
            'stock_supplier_ids' => 'sometimes|array|min:1',
            'stock_supplier_ids.*' => ['integer', Rule::in(self::STOCK_ROUTE_SUPPLIERS)],
            'sima_stock_via' => ['sometimes', 'string', Rule::in([SellerWarehouse::SIMA_STOCK_VIA_SIMA_API, SellerWarehouse::SIMA_STOCK_VIA_WB_CATALOG])],
            'stock_collect_enabled' => 'sometimes|boolean',
            'stock_send_to_wb' => 'sometimes|boolean',
            'stock_frequency_minutes' => 'sometimes|integer|min:5|max:1440',
        ]);

        $this->assertStockSendRequiresCollect($validated);

        $norm = $this->normalizeSingleWarehousePayload($validated);
        $this->assertNoStockSupplierIntersection(
            (int) $validated['seller_id'],
            $norm['stock_supplier_ids'],
            null
        );

        $seller = Sellers::findOrFail($validated['seller_id']);
        $warehouse = $seller->warehouses()->create([
            'wb_warehouse_id' => $validated['wb_warehouse_id'],
            'name' => $validated['name'] ?? null,
            'supplier' => SellerWarehouse::legacySupplierFromStockSupplierIds($norm['stock_supplier_ids']),
            'stock_supplier_ids' => $norm['stock_supplier_ids'],
            'sima_stock_via' => $norm['sima_stock_via'],
            'stock_collect_enabled' => (bool) ($validated['stock_collect_enabled'] ?? false),
            'stock_send_to_wb' => (bool) ($validated['stock_send_to_wb'] ?? false),
            'stock_frequency_minutes' => isset($validated['stock_frequency_minutes']) ? (int) $validated['stock_frequency_minutes'] : 30,
        ]);

        return response()->json($warehouse, 201);
    }

    public function warehouseUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:seller_warehouses,id',
            'wb_warehouse_id' => 'sometimes|integer|min:1',
            'name' => 'nullable|string|max:255',
            'supplier' => ['sometimes', 'nullable', 'integer', Rule::in(self::ALLOWED_SUPPLIERS)],
            'stock_supplier_ids' => 'sometimes|array|min:1',
            'stock_supplier_ids.*' => ['integer', Rule::in(self::STOCK_ROUTE_SUPPLIERS)],
            'sima_stock_via' => ['sometimes', 'string', Rule::in([SellerWarehouse::SIMA_STOCK_VIA_SIMA_API, SellerWarehouse::SIMA_STOCK_VIA_WB_CATALOG])],
            'stock_collect_enabled' => 'sometimes|boolean',
            'stock_send_to_wb' => 'sometimes|boolean',
            'stock_frequency_minutes' => 'sometimes|integer|min:5|max:1440',
        ]);

        $warehouse = SellerWarehouse::findOrFail($validated['id']);

        $collect = array_key_exists('stock_collect_enabled', $validated)
            ? (bool) $validated['stock_collect_enabled']
            : $warehouse->stock_collect_enabled;
        $send = array_key_exists('stock_send_to_wb', $validated)
            ? (bool) $validated['stock_send_to_wb']
            : $warehouse->stock_send_to_wb;
        if ($send && ! $collect) {
            throw ValidationException::withMessages([
                'stock_send_to_wb' => ['Нельзя отправлять остатки в WB без включённого сбора.'],
            ]);
        }

        if (isset($validated['wb_warehouse_id']) && (int) $validated['wb_warehouse_id'] !== (int) $warehouse->wb_warehouse_id) {
            $request->validate([
                'wb_warehouse_id' => [
                    'required',
                    'integer',
                    'min:1',
                    Rule::unique('seller_warehouses', 'wb_warehouse_id')
                        ->where('seller_id', $warehouse->seller_id)
                        ->ignore($warehouse->id),
                ],
            ]);
        }

        $merge = collect($validated)->only([
            'wb_warehouse_id',
            'name',
            'supplier',
            'stock_supplier_ids',
            'sima_stock_via',
            'stock_collect_enabled',
            'stock_send_to_wb',
            'stock_frequency_minutes',
        ])->all();

        if (array_key_exists('stock_supplier_ids', $merge) || array_key_exists('supplier', $merge) || $request->has('sima_stock_via')) {
            $payload = array_merge($warehouse->toArray(), $merge);
            $norm = $this->normalizeSingleWarehousePayload($payload);
            $this->assertNoStockSupplierIntersection(
                (int) $warehouse->seller_id,
                $norm['stock_supplier_ids'],
                (int) $warehouse->id
            );
            $warehouse->stock_supplier_ids = $norm['stock_supplier_ids'];
            $warehouse->sima_stock_via = $norm['sima_stock_via'];
            $warehouse->supplier = SellerWarehouse::legacySupplierFromStockSupplierIds($norm['stock_supplier_ids']);
        }

        $warehouse->fill(collect($validated)->only([
            'wb_warehouse_id',
            'name',
            'stock_collect_enabled',
            'stock_send_to_wb',
            'stock_frequency_minutes',
        ])->all());
        $warehouse->save();

        return response()->json($warehouse->fresh());
    }

    public function warehouseDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'required|integer|exists:seller_warehouses,id',
        ]);

        SellerWarehouse::destroy($validated['id']);

        return response()->json(['success' => true]);
    }

    public function warehouseStockHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:seller_warehouses,id',
            'limit' => 'sometimes|integer|min:1|max:500',
        ]);
        $limit = (int) ($validated['limit'] ?? 100);
        $warehouseId = (int) $validated['warehouse_id'];

        $items = SellerWarehouseStockHistory::query()
            ->where('seller_warehouse_id', $warehouseId)
            ->orderByDesc('collected_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (SellerWarehouseStockHistory $h): array => [
                'id' => $h->id,
                'chrt_id' => $h->chrt_id,
                'amount' => $h->amount,
                'is_positive' => (bool) $h->is_positive,
                'wb_eligible' => (bool) $h->wb_eligible,
                'included_in_wb_batch' => (bool) $h->included_in_wb_batch,
                'wb_sent_at' => $h->wb_sent_at?->toIso8601String(),
                'collected_at' => $h->collected_at?->toIso8601String(),
                'run_key' => $h->run_key,
            ])
            ->values()
            ->all();

        $runsSummary = SellerWarehouseStockHistory::query()
            ->where('seller_warehouse_id', $warehouseId)
            ->selectRaw(
                'run_key, max(collected_at) as collected_at, count(*) as positions, '.
                'sum(case when is_positive then 1 else 0 end) as positive_count, '.
                'sum(case when wb_eligible then 1 else 0 end) as wb_eligible_count, '.
                'sum(case when included_in_wb_batch then 1 else 0 end) as wb_sent_count'
            )
            ->groupBy('run_key')
            ->orderByDesc('collected_at')
            ->limit(15)
            ->get()
            ->map(static function ($row): array {
                return [
                    'run_key' => (string) $row->run_key,
                    'collected_at' => $row->collected_at !== null
                        ? Carbon::parse($row->collected_at)->toIso8601String()
                        : null,
                    'positions' => (int) $row->positions,
                    'positive' => (int) $row->positive_count,
                    'wb_eligible' => (int) $row->wb_eligible_count,
                    'wb_sent' => (int) $row->wb_sent_count,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'items' => $items,
            'runs_summary' => $runsSummary,
        ]);
    }

    /**
     * Обнуление остатков в WB для карточек выбранных поставщиков на указанном складе продавца.
     */
    public function warehouseZeroStocks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:seller_warehouses,id',
            'supplier_ids' => 'required|array|min:1',
            'supplier_ids.*' => ['integer', Rule::in(self::STOCK_ROUTE_SUPPLIERS)],
        ]);

        $warehouse = SellerWarehouse::with('seller')->findOrFail((int) $validated['warehouse_id']);
        $seller = $warehouse->seller;
        if ($seller === null) {
            return response()->json(['message' => 'Магазин не найден'], 422);
        }

        if ((int) $warehouse->wb_warehouse_id <= 0) {
            return response()->json(['message' => 'У склада не задан wb_warehouse_id'], 422);
        }

        $supplierIds = array_values(array_unique(array_map('intval', $validated['supplier_ids'])));
        $allowed = $warehouse->effectiveStockSupplierIds();
        $notAllowed = array_values(array_diff($supplierIds, $allowed));
        if ($notAllowed !== []) {
            return response()->json([
                'message' => 'Указаны поставщики, не привязанные к этому складу: '.implode(', ', $notAllowed),
            ], 422);
        }

        $key = trim((string) $seller->wb_api_key);
        if ($key === '') {
            return response()->json(['message' => 'У продавца не задан API-ключ WB'], 422);
        }

        $cards = Cards::query()
            ->where('sellerID', $seller->id)
            ->whereIn('supplier', $supplierIds)
            ->where('supplier', '>', 0)
            ->get(['id', 'chrtID']);

        $rows = [];
        $seenChrt = [];
        foreach ($cards as $card) {
            $chrtRaw = $card->chrtID;
            if ($chrtRaw === null || $chrtRaw === '' || (int) $chrtRaw <= 0) {
                continue;
            }
            $chrtId = (int) $chrtRaw;
            if (isset($seenChrt[$chrtId])) {
                continue;
            }
            $seenChrt[$chrtId] = true;
            $rows[] = ['chrtId' => $chrtId, 'amount' => 0];
        }

        if ($rows === []) {
            return response()->json([
                'message' => 'Нет карточек с chrtID для выбранных поставщиков',
                'sent' => 0,
                'supplier_ids' => $supplierIds,
            ], 422);
        }

        $service = new WildberriesService($key, []);
        $wbWhId = (int) $warehouse->wb_warehouse_id;
        foreach (array_chunk($rows, 1000) as $chunk) {
            if (! $service->updateStocks($wbWhId, $chunk)) {
                return response()->json([
                    'message' => 'Wildberries отклонил запрос обновления остатков',
                    'sent' => 0,
                ], 502);
            }
        }

        return response()->json([
            'success' => true,
            'sent' => count($rows),
            'warehouse_id' => (int) $warehouse->id,
            'supplier_ids' => $supplierIds,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{stock_supplier_ids: list<int>, sima_stock_via: string}>
     */
    private function normalizeWarehouseRowsPayload(array $rows): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            $out[$i] = $this->normalizeSingleWarehousePayload($row);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{stock_supplier_ids: list<int>, sima_stock_via: string}
     */
    private function normalizeSingleWarehousePayload(array $data): array
    {
        $idsRaw = $data['stock_supplier_ids'] ?? null;
        if (is_array($idsRaw) && $idsRaw !== []) {
            $ids = $this->normalizeStockSupplierIdList($idsRaw);
        } else {
            $legacy = $data['supplier'] ?? null;
            $ids = $this->normalizeStockSupplierIdListFromLegacy($legacy === '' ? null : $legacy);
        }

        $via = (string) ($data['sima_stock_via'] ?? SellerWarehouse::SIMA_STOCK_VIA_WB_CATALOG);
        if (! in_array($via, [SellerWarehouse::SIMA_STOCK_VIA_SIMA_API, SellerWarehouse::SIMA_STOCK_VIA_WB_CATALOG], true)) {
            $via = SellerWarehouse::SIMA_STOCK_VIA_WB_CATALOG;
        }
        if (! in_array(20, $ids, true)) {
            $via = SellerWarehouse::SIMA_STOCK_VIA_WB_CATALOG;
        }

        return [
            'stock_supplier_ids' => $ids,
            'sima_stock_via' => $via,
        ];
    }

    /**
     * @param  list<mixed>  $ids
     * @return list<int>
     */
    private function normalizeStockSupplierIdList(array $ids): array
    {
        $out = [];
        foreach ($ids as $x) {
            $i = (int) $x;
            if ($i > 0 && in_array($i, self::STOCK_ROUTE_SUPPLIERS, true)) {
                $out[$i] = true;
            }
        }
        $keys = array_map('intval', array_keys($out));
        sort($keys);
        if ($keys === []) {
            throw ValidationException::withMessages([
                'stock_supplier_ids' => ['Укажите хотя бы одного поставщика из списка: '.implode(', ', self::STOCK_ROUTE_SUPPLIERS)],
            ]);
        }

        return $keys;
    }

    /**
     * @return list<int>
     */
    private function normalizeStockSupplierIdListFromLegacy(null|int|string $supplier): array
    {
        if ($supplier === null || $supplier === '') {
            return [10];
        }
        if ((int) $supplier === 20) {
            return [20];
        }

        return [10];
    }

    /**
     * @param  array<int, array{stock_supplier_ids: list<int>, sima_stock_via: string}>  $normalizedRows
     */
    private function assertNoStockSupplierOverlapInNestedRows(array $normalizedRows): void
    {
        $previous = [];
        foreach ($normalizedRows as $idx => $norm) {
            $ids = $norm['stock_supplier_ids'];
            foreach ($previous as $j => $prevIds) {
                if (array_intersect($ids, $prevIds) !== []) {
                    throw ValidationException::withMessages([
                        "warehouses.$idx.stock_supplier_ids" => [
                            'Поставщики пересекаются с другим складом в этом запросе (warehouses['.$j.']).',
                        ],
                    ]);
                }
            }
            $previous[$idx] = $ids;
        }
    }

    /**
     * @param  list<int>  $supplierIds
     */
    private function assertNoStockSupplierIntersection(int $sellerId, array $supplierIds, ?int $ignoreWarehouseId): void
    {
        $supplierIds = array_values(array_unique($supplierIds));
        $query = SellerWarehouse::query()->where('seller_id', $sellerId);
        if ($ignoreWarehouseId !== null) {
            $query->where('id', '!=', $ignoreWarehouseId);
        }
        foreach ($query->get() as $existing) {
            $other = $existing->effectiveStockSupplierIds();
            $inter = array_intersect($supplierIds, $other);
            if ($inter !== []) {
                throw ValidationException::withMessages([
                    'stock_supplier_ids' => [
                        'Поставщик(и) '.implode(', ', $inter).' уже привязаны к другому складу (#'.$existing->id.').',
                    ],
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function assertStockSettingsInRequest(array $rows): void
    {
        foreach ($rows as $index => $row) {
            $collect = (bool) ($row['stock_collect_enabled'] ?? false);
            $send = (bool) ($row['stock_send_to_wb'] ?? false);
            if ($send && ! $collect) {
                throw ValidationException::withMessages([
                    "warehouses.$index.stock_send_to_wb" => ['Нельзя отправлять остатки в WB без включённого сбора.'],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertStockSendRequiresCollect(array $data): void
    {
        $hasCollect = array_key_exists('stock_collect_enabled', $data);
        $hasSend = array_key_exists('stock_send_to_wb', $data);
        if (! $hasSend) {
            return;
        }
        $collect = $hasCollect ? (bool) $data['stock_collect_enabled'] : false;
        $send = (bool) $data['stock_send_to_wb'];
        if ($send && ! $collect) {
            throw ValidationException::withMessages([
                'stock_send_to_wb' => ['Нельзя отправлять остатки в WB без включённого сбора.'],
            ]);
        }
    }

    private function filter($sellers, $fields): array
    {
        return $sellers->pluck(...$fields)->toArray();
    }
}
