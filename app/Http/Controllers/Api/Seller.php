<?php

namespace App\Http\Controllers\Api;

use App\Models\Sellers;
use App\Models\SellerWarehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Seller
{
    /**
     * Поставщики, для которых можно привязать отдельный склад.
     * NULL означает «склад по умолчанию для всех остальных».
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
            'warehouses.*.stock_collect_enabled' => 'sometimes|boolean',
            'warehouses.*.stock_send_to_wb' => 'sometimes|boolean',
            'warehouses.*.stock_frequency_minutes' => 'sometimes|integer|min:5|max:1440',
        ]);

        $rows = $validated['warehouses'] ?? [];
        $this->assertUniqueSupplierInRequest($rows);
        $this->assertStockSettingsInRequest($rows);

        unset($validated['warehouses']);

        $seller = Sellers::create($validated);

        foreach ($rows as $row) {
            $collect = (bool) ($row['stock_collect_enabled'] ?? false);
            $send = (bool) ($row['stock_send_to_wb'] ?? false);
            $seller->warehouses()->create([
                'wb_warehouse_id' => $row['wb_warehouse_id'],
                'name' => $row['name'] ?? null,
                'supplier' => $row['supplier'] ?? null,
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
            'stock_collect_enabled' => 'sometimes|boolean',
            'stock_send_to_wb' => 'sometimes|boolean',
            'stock_frequency_minutes' => 'sometimes|integer|min:5|max:1440',
        ]);

        $this->assertStockSendRequiresCollect($validated);

        $this->assertUniqueSupplierForSeller(
            (int) $validated['seller_id'],
            $validated['supplier'] ?? null,
            null
        );

        $seller = Sellers::findOrFail($validated['seller_id']);
        $warehouse = $seller->warehouses()->create([
            'wb_warehouse_id' => $validated['wb_warehouse_id'],
            'name' => $validated['name'] ?? null,
            'supplier' => $validated['supplier'] ?? null,
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

        if ($request->has('supplier')) {
            $newSupplier = $validated['supplier'] ?? null;
            if ($newSupplier !== $warehouse->supplier) {
                $this->assertUniqueSupplierForSeller(
                    (int) $warehouse->seller_id,
                    $newSupplier,
                    (int) $warehouse->id
                );
            }
        }

        $warehouse->fill(collect($validated)->only([
            'wb_warehouse_id',
            'name',
            'supplier',
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

    /**
     * В рамках одного запроса (nested warehouses при создании селлера) не должно быть
     * двух складов с одним и тем же supplier (в т.ч. двух NULL-складов).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function assertUniqueSupplierInRequest(array $rows): void
    {
        $seen = [];
        foreach ($rows as $index => $row) {
            $supplier = $row['supplier'] ?? null;
            $key = $supplier === null ? 'null' : (string) $supplier;
            if (isset($seen[$key])) {
                $label = $supplier === null ? 'склад по умолчанию' : 'склад для supplier=' . $supplier;
                throw ValidationException::withMessages([
                    "warehouses.$index.supplier" => ['У селлера уже задан ' . $label . '.'],
                ]);
            }
            $seen[$key] = true;
        }
    }

    /**
     * У селлера не может быть двух складов для одного и того же supplier.
     */
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

    private function assertUniqueSupplierForSeller(int $sellerId, ?int $supplier, ?int $ignoreId): void
    {
        $query = SellerWarehouse::where('seller_id', $sellerId);
        if ($supplier === null) {
            $query->whereNull('supplier');
        } else {
            $query->where('supplier', $supplier);
        }
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            $label = $supplier === null ? 'склад по умолчанию' : 'склад для supplier=' . $supplier;
            throw ValidationException::withMessages([
                'supplier' => ['У селлера уже задан ' . $label . '.'],
            ]);
        }
    }

    private function filter($sellers, $fields): array
    {
        return $sellers->pluck(...$fields)->toArray();
    }
}
