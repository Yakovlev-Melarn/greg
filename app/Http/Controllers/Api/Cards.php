<?php

namespace App\Http\Controllers\Api;

use App\Jobs\WbJob;
use App\Models\Jobs;
use App\Models\Cards as ModelsCards;

class Cards
{
    public function getList($request): array
    {
        $page = max(1, (int)($request['page'] ?? 1));
        $perPage = max(1, min((int)($request['per_page'] ?? 20), 100));
        $search = trim((string)($request['search'] ?? ''));
        $supplier = (string)($request['supplier'] ?? '');
        $sortBy = (string)($request['sort_by'] ?? 'id');
        $sortDir = strtolower((string)($request['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSort = ['id', 'nmID', 'supplierVendorCode', 'supplierName', 'vendorCode', 'productName', 'created_at'];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'id';
        }

        $query = ModelsCards::query()
            ->where('sellerId', $request['seller']);

        if ($supplier !== '') {
            $query->where('supplier', (int)$supplier);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('productName', 'like', "%{$search}%")
                    ->orWhere('supplierVendorCode', 'like', "%{$search}%")
                    ->orWhere('vendorCode', 'like', "%{$search}%")
                    ->orWhere('nmID', 'like', "%{$search}%");
            });
        }

        $total = (clone $query)->count();
        $items = $query
            ->orderBy($sortBy, $sortDir)
            ->forPage($page, $perPage)
            ->get()
            ->toArray();

        return [
            'items' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int)ceil($total / $perPage)),
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
                'search' => $search,
                'supplier' => $supplier,
            ],
        ];
    }

    public function updateList($request): array
    {
        if (Jobs::where('queue', '=', 'updateCardsProcess')->first()) {
            return [
                'status' => 'error',
                'message' => 'Процесс уже запущен',
            ];
        }
        if (ModelsCards::count() > 0) {
            $cardData = ModelsCards::orderBy('id', 'desc')->first();
            $cursor = [
                'limit' => 100,
                'nmID' => $cardData->nmID,
                'updatedAt' => $cardData->updated_at
            ];
        } else {
            $cursor = ['limit' => 100];
        }
        WbJob::dispatch('getCardList', [
            'seller_id' => $request['seller'],
            'settings' => [
                'settings' => [
                    'sort' => ['ascending' => true],
                    'cursor' => $cursor,
                    'filter' => ['withPhoto' => -1]
                ]
            ]
        ])->onQueue('updateCardsProcess');
        return [
            'status' => 'success',
            'message' => 'Процесс запущен',
        ];
    }
}
