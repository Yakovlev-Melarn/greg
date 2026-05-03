<?php

namespace App\Http\Controllers\Api;

use App\Jobs\WbJob;
use App\Models\Jobs;
use App\Models\Cards as ModelsCards;
use App\Models\Sellers;
use App\Models\SkuMapping;
use App\Services\WildberriesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

        $sortColumns = [
            'id' => 'cards.id',
            'nmID' => 'cards.nmID',
            'supplierVendorCode' => 'cards.supplierVendorCode',
            'supplierName' => 'cards.supplierName',
            'vendorCode' => 'cards.vendorCode',
            'productName' => 'cards.productName',
            'created_at' => 'cards.created_at',
        ];
        if (!array_key_exists($sortBy, $sortColumns)) {
            $sortBy = 'id';
        }

        $hasUserBlockedColumn = Schema::hasColumn('skuMapping', 'user_blocked');
        $userBlockedSelect = $hasUserBlockedColumn
            ? 'sm.user_blocked as unit_user_blocked'
            : DB::raw('0 as unit_user_blocked');

        $query = ModelsCards::query()
            ->leftJoin('skuMapping as sm', function ($join) {
                $join->on(function ($on) {
                    $on->where('cards.supplier', 20)
                        ->whereColumn('sm.origSku', 'cards.vendorCode');
                })->orOn(function ($on) {
                    $on->where('cards.supplier', 10)
                        ->whereColumn('sm.wbSku', 'cards.vendorCode');
                });
            })
            ->select([
                'cards.*',
                'sm.purchase_price as unit_purchase_price',
                'sm.logistics_cost as unit_logistics_cost',
                'sm.total_cost as unit_total_cost',
                'sm.selling_price as unit_selling_price',
                'sm.wb_commission as unit_wb_commission',
                'sm.fulfillment_cost as unit_fulfillment_cost',
                'sm.tax as unit_tax',
                'sm.net_profit as unit_net_profit',
                'sm.stock_quantity as unit_stock_quantity',
                'sm.wbPrice as unit_wb_price',
                'sm.origSku as unit_orig_sku',
                $userBlockedSelect,
            ])
            ->where('sellerID', $request['seller']);

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
            ->orderBy($sortColumns[$sortBy], $sortDir)
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
        $sellerId = (int) ($request['seller'] ?? 0);
        $hasLocalCardsForSeller = $sellerId > 0
            && ModelsCards::where('sellerID', $sellerId)->exists();

        if ($hasLocalCardsForSeller) {
            $cardData = ModelsCards::where('sellerID', $sellerId)
                ->orderByDesc('id')
                ->first();
            $cursor = [
                'limit' => 100,
                'nmID' => $cardData->nmID,
                'updatedAt' => $cardData->updated_at,
            ];
        } else {
            $cursor = ['limit' => 100];
        }

        $jobPayload = [
            'seller_id' => $sellerId,
            'settings' => [
                'settings' => [
                    'sort' => ['ascending' => true],
                    'cursor' => $cursor,
                    'filter' => ['withPhoto' => -1],
                ],
            ],
        ];
        if (! $hasLocalCardsForSeller) {
            // Полный обход каталога с первой страницы — после него можно сверить nmID с локальной БД.
            $jobPayload['cards_full_catalog_from_empty'] = true;
        }
        if ($hasLocalCardsForSeller) {
            // После инкрементальной синхронизации запустим полный проход каталога WB с начала,
            // иначе карточки «ниже» курсора в сортировке никогда не попадут в БД.
            $jobPayload['needs_catalog_backfill_after_incremental'] = true;
        }

        $jobPayload['cards_sync_run_id'] = (string) Str::uuid();
        $jobPayload['cards_sync_notify_start'] = true;

        WbJob::dispatch('getCardList', $jobPayload)->onQueue('updateCardsProcess');
        return [
            'status' => 'success',
            'message' => 'Процесс запущен',
        ];
    }

    public function block($request): array
    {
        try {
            $card = ModelsCards::find((int) $request['card_id']);
            if (!$card) {
                return ['status' => 'error', 'message' => 'Карточка не найдена'];
            }

            if (!in_array((int) $card->supplier, [10, 20], true)) {
                return ['status' => 'error', 'message' => 'Блокировка доступна только для Wildberries и Sima-Land'];
            }

            $seller = Sellers::find((int) $card->sellerID);
            if (!$seller) {
                return ['status' => 'error', 'message' => 'Продавец карточки не найден'];
            }

            $nmId = $this->resolveNmIdForTrash($card);
            if ($nmId <= 0) {
                return ['status' => 'error', 'message' => 'Не удалось определить nmID для удаления'];
            }

            $mappingQuery = $this->resolveMappingQuery($card);
            $mapping = $mappingQuery->first();
            if (!$mapping) {
                return ['status' => 'error', 'message' => 'Связь с skuMapping не найдена'];
            }

            $service = new WildberriesService($seller->wb_api_key, []);
            if (!$service->moveCardsToTrash([$nmId])) {
                return ['status' => 'error', 'message' => 'Не удалось удалить карточку в WB'];
            }

            $column = Schema::hasColumn('skuMapping', 'user_blocked') ? 'user_blocked' : 'blocked';
            $mappingQuery->update([$column => 1]);

            return ['status' => 'success', 'message' => 'Карточка перенесена в корзину WB и помечена как заблокированная'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Ошибка удаления: '.$e->getMessage()];
        }
    }

    public function uploadPhotos($request): array
    {
        try {
            $card = ModelsCards::find((int) ($request['card_id'] ?? 0));
            if (! $card) {
                return ['status' => 'error', 'message' => 'Карточка не найдена'];
            }

            if (! in_array((int) $card->supplier, [10, 20], true)) {
                return ['status' => 'error', 'message' => 'Обновление фото доступно только для Wildberries и Sima-Land'];
            }

            $seller = Sellers::find((int) $card->sellerID);
            if (! $seller) {
                return ['status' => 'error', 'message' => 'Продавец карточки не найден'];
            }

            $supplierVendorCode = trim((string) $card->supplierVendorCode);
            if ($supplierVendorCode === '') {
                return ['status' => 'error', 'message' => 'У карточки не задан артикул поставщика'];
            }

            $queued = WbJob::queuePhotoUploadAndFollowUpFetch(
                (int) $seller->id,
                (int) $card->nmID,
                $supplierVendorCode,
                $card->vendorCode,
                $card->sku,
                true,
                (int) $card->id
            );

            if (! $queued) {
                return ['status' => 'error', 'message' => 'Не удалось определить источник фото для загрузки'];
            }

            return [
                'status' => 'success',
                'message' => 'Обновление фото поставлено в очередь',
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Ошибка: '.$e->getMessage()];
        }
    }

    public function recover($request): array
    {
        try {
            $card = ModelsCards::find((int) $request['card_id']);
            if (!$card) {
                return ['status' => 'error', 'message' => 'Карточка не найдена'];
            }

            if (!in_array((int) $card->supplier, [10, 20], true)) {
                return ['status' => 'error', 'message' => 'Восстановление доступно только для Wildberries и Sima-Land'];
            }

            $seller = Sellers::find((int) $card->sellerID);
            if (!$seller) {
                return ['status' => 'error', 'message' => 'Продавец карточки не найден'];
            }

            $nmId = $this->resolveNmIdForTrash($card);
            if ($nmId <= 0) {
                return ['status' => 'error', 'message' => 'Не удалось определить nmID для восстановления'];
            }

            $mappingQuery = $this->resolveMappingQuery($card);
            $mapping = $mappingQuery->first();
            if (!$mapping) {
                return ['status' => 'error', 'message' => 'Связь с skuMapping не найдена'];
            }

            $service = new WildberriesService($seller->wb_api_key, []);
            if (!$service->recoverCardsFromTrash([$nmId])) {
                return ['status' => 'error', 'message' => 'Не удалось восстановить карточку в WB'];
            }

            $column = Schema::hasColumn('skuMapping', 'user_blocked') ? 'user_blocked' : 'blocked';
            $mappingQuery->update([$column => 0]);

            return ['status' => 'success', 'message' => 'Карточка восстановлена в WB'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Ошибка восстановления: '.$e->getMessage()];
        }
    }

    private function resolveNmIdForTrash(ModelsCards $card): int
    {
        // WB delete/recover APIs operate on nmID only, regardless of supplier type.
        return (int) ($card->nmID ?: 0);
    }

    private function resolveMappingQuery(ModelsCards $card): Builder
    {
        if ((int) $card->supplier === 20) {
            return SkuMapping::query()->where('origSku', (string) $card->vendorCode);
        }

        return SkuMapping::query()->where('wbSku', (string) $card->vendorCode);
    }
}
