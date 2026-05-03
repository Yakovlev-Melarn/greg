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
        ]);

        $rows = $validated['warehouses'] ?? [];
        $this->assertUniqueSupplierInRequest($rows);

        unset($validated['warehouses']);

        $seller = Sellers::create($validated);

        foreach ($rows as $row) {
            $seller->warehouses()->create([
                'wb_warehouse_id' => $row['wb_warehouse_id'],
                'name' => $row['name'] ?? null,
                'supplier' => $row['supplier'] ?? null,
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
        ]);

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
        ]);

        $warehouse = SellerWarehouse::findOrFail($validated['id']);

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

        $warehouse->fill(collect($validated)->only(['wb_warehouse_id', 'name', 'supplier'])->all());
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
