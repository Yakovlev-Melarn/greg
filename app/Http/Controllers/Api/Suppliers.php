<?php

namespace App\Http\Controllers\Api;

use App\Models\Supplier;
use App\Models\Supplier as ModelsSuppliers;
use Illuminate\Http\JsonResponse;

class Suppliers
{
    public function list(): array
    {
        return ModelsSuppliers::orderBy('id', 'desc')
            ->get()
            ->toArray();
    }

    public function store($request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'link' => 'required|url',
        ]);
        $supplier = Supplier::create($validated);
        return response()->json($supplier, 201);
    }

    public function destroy($request): JsonResponse
    {
        $supplier = Supplier::find($request['supplierId']);
        if (!$supplier) {
            return response()->json(['error' => 'Supplier not found'], 404);
        }
        $supplier->delete();
        return response()->json(['success' => true]);
    }
}
