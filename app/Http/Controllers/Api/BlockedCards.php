<?php

namespace App\Http\Controllers\Api;

use App\Services\CardModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockedCards
{
    public function quarantine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplierVendorCodes' => ['required', 'array', 'min:1'],
            'supplierVendorCodes.*' => ['required', 'string', 'max:255'],
        ]);

        $service = new CardModerationService();
        $result = $service->quarantineBySupplierVendorCodes($validated['supplierVendorCodes']);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
