<?php

namespace App\Http\Controllers\Api;

use App\Jobs\WbJob;
use App\Libs\WBContent;
use App\Models\Cards as ModelsCards;
use App\Models\Jobs;
use App\Models\Sellers;
use App\Models\SellerWarehouse;
use App\Models\SkuMapping;
use App\Services\WildberriesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class Cards
{
    /** Массовые операции по карточкам (фото / удаление): максимум ID за один запрос. */
    private const BULK_ACTION_CARD_LIMIT = 40;

    public function getList($request): array
    {
        $page = max(1, (int) ($request['page'] ?? 1));
        $perPage = max(1, min((int) ($request['per_page'] ?? 20), 100));
        $search = trim((string) ($request['search'] ?? ''));
        $supplier = (string) ($request['supplier'] ?? '');
        $sortBy = (string) ($request['sort_by'] ?? 'id');
        $sortDir = strtolower((string) ($request['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $sortColumns = [
            'id' => 'cards.id',
            'nmID' => 'cards.nmID',
            'supplierVendorCode' => 'cards.supplierVendorCode',
            'supplierName' => 'cards.supplierName',
            'vendorCode' => 'cards.vendorCode',
            'productName' => 'cards.productName',
            'created_at' => 'cards.created_at',
        ];
        if (! array_key_exists($sortBy, $sortColumns)) {
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
            $query->where('supplier', (int) $supplier);
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

        // Сироты всегда в конце списка (на последних страницах пагинации), внутри группы — выбранная сортировка.
        if (Schema::hasColumn('cards', 'orphan_for_clone')) {
            $query->orderBy('cards.orphan_for_clone', 'asc');
        }
        $items = $query
            ->orderBy($sortColumns[$sortBy], $sortDir)
            ->forPage($page, $perPage)
            ->get()
            ->toArray();

        $warehouses = [];
        $sellerIdForWarehouses = (int) ($request['seller'] ?? 0);
        if ($sellerIdForWarehouses > 0) {
            $warehouses = SellerWarehouse::query()
                ->where('seller_id', $sellerIdForWarehouses)
                ->orderBy('id')
                ->get(['id', 'name', 'wb_warehouse_id'])
                ->map(static fn ($w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'wb_warehouse_id' => $w->wb_warehouse_id,
                ])
                ->values()
                ->all();
        }

        return [
            'items' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
                'search' => $search,
                'supplier' => $supplier,
                'warehouses' => $warehouses,
            ],
        ];
    }

    /**
     * Ручная передача остатков на склад WB (marketplace api/v3/stocks).
     *
     * @return array{status: string, message?: string, sent?: int, skipped?: list<array<string, mixed>>}
     */
    public function pushStocksToWb($request): array
    {
        $sellerId = (int) ($request['seller'] ?? 0);
        if ($sellerId <= 0) {
            return [
                'status' => 'error',
                'message' => 'Не указан продавец',
            ];
        }

        $validator = Validator::make($request->all(), [
            'card_ids' => 'required|array|min:1|max:300',
            'card_ids.*' => 'integer|min:1',
            'amount' => 'required|integer|min:0|max:100000',
            'warehouse_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return [
                'status' => 'error',
                'message' => $validator->errors()->first(),
            ];
        }

        $validated = $validator->validated();
        $amount = min(100000, max(0, (int) $validated['amount']));

        $warehouseQuery = SellerWarehouse::query()->where('seller_id', $sellerId);
        if (! empty($validated['warehouse_id'])) {
            $warehouse = (clone $warehouseQuery)->where('id', (int) $validated['warehouse_id'])->first();
        } else {
            $warehouse = $warehouseQuery->orderBy('id')->first();
        }

        if ($warehouse === null || (int) $warehouse->wb_warehouse_id <= 0) {
            return [
                'status' => 'error',
                'message' => 'Не найден склад WB для этого магазина. Добавьте склад продавца в настройках.',
            ];
        }

        $cardIds = array_values(array_unique(array_map('intval', $validated['card_ids'])));
        $cards = ModelsCards::query()
            ->where('sellerID', $sellerId)
            ->whereIn('id', $cardIds)
            ->get(['id', 'chrtID']);

        if ($cards->count() !== count($cardIds)) {
            return [
                'status' => 'error',
                'message' => 'Часть карточек не найдена или принадлежит другому магазину',
            ];
        }

        $rows = [];
        $skipped = [];
        $seenChrt = [];

        foreach ($cards as $card) {
            $chrtRaw = $card->chrtID;
            if ($chrtRaw === null || $chrtRaw === '' || (int) $chrtRaw <= 0) {
                $skipped[] = [
                    'card_id' => $card->id,
                    'reason' => 'Нет chrtID',
                ];

                continue;
            }

            $chrtId = (int) $chrtRaw;
            if (isset($seenChrt[$chrtId])) {
                continue;
            }

            $seenChrt[$chrtId] = true;
            $rows[] = [
                'chrtId' => $chrtId,
                'amount' => $amount,
            ];
        }

        if ($rows === []) {
            return [
                'status' => 'error',
                'message' => 'Ни у одной выбранной карточки нет chrtID для отправки остатков',
                'skipped' => $skipped,
            ];
        }

        $seller = Sellers::find($sellerId);
        if ($seller === null || trim((string) $seller->wb_api_key) === '') {
            return [
                'status' => 'error',
                'message' => 'У продавца не задан API-ключ WB',
            ];
        }

        $service = new WildberriesService($seller->wb_api_key, []);
        $wbWhId = (int) $warehouse->wb_warehouse_id;

        foreach (array_chunk($rows, 1000) as $chunk) {
            if (! $service->updateStocks($wbWhId, $chunk)) {
                return [
                    'status' => 'error',
                    'message' => 'Wildberries отклонил запрос обновления остатков',
                    'skipped' => $skipped,
                ];
            }
        }

        $sent = count($rows);

        return [
            'status' => 'success',
            'message' => 'Остатки отправлены на WB: '.$sent.' '.($sent === 1 ? 'позиция' : 'позиций').'.',
            'sent' => $sent,
            'skipped' => $skipped,
        ];
    }

    public function updateList($request): array
    {
        if (self::isUpdateCardsProcessQueueBusy()) {
            return [
                'status' => 'error',
                'message' => 'Процесс уже запущен',
            ];
        }
        $sellerId = (int) ($request['seller'] ?? 0);
        if ($sellerId <= 0) {
            return [
                'status' => 'error',
                'message' => 'Не указан продавец',
            ];
        }

        $selectiveCodes = self::normalizeSupplierVendorCodesInput($request['supplier_vendor_codes'] ?? null);
        if ($selectiveCodes !== []) {
            if (count($selectiveCodes) > 300) {
                return [
                    'status' => 'error',
                    'message' => 'Не более 300 артикулов за один запуск',
                ];
            }

            $jobPayload = [
                'seller_id' => $sellerId,
                'supplier_vendor_codes' => $selectiveCodes,
                'settings' => ['settings' => []],
                'cards_sync_run_id' => (string) Str::uuid(),
                'cards_sync_notify_start' => true,
            ];

            WbJob::dispatch('getCardList', $jobPayload)->onQueue('updateCardsProcess');

            return [
                'status' => 'success',
                'message' => 'Запущено обновление по '.count($selectiveCodes).' артикулам',
            ];
        }

        $hasLocalCardsForSeller = ModelsCards::where('sellerID', $sellerId)->exists();

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
            $ids = $this->extractCardIdsFromRequest($request);
            if ($ids === []) {
                return ['status' => 'error', 'message' => 'Не указана карточка'];
            }
            if (count($ids) > self::BULK_ACTION_CARD_LIMIT) {
                return ['status' => 'error', 'message' => 'Не более '.self::BULK_ACTION_CARD_LIMIT.' карточек за один запрос'];
            }

            if (count($ids) === 1) {
                $card = ModelsCards::find($ids[0]);
                if (! $card) {
                    return ['status' => 'error', 'message' => 'Карточка не найдена'];
                }

                return $this->performBlockCard($card);
            }

            $results = [];
            foreach ($ids as $id) {
                $card = ModelsCards::find($id);
                if (! $card) {
                    $results[] = ['card_id' => $id, 'status' => 'error', 'message' => 'Карточка не найдена'];

                    continue;
                }
                $row = $this->performBlockCard($card);
                $row['card_id'] = $id;
                $results[] = $row;
            }

            return $this->aggregateBulkActionResults($results, 'Удаление в корзину WB');
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Ошибка удаления: '.$e->getMessage()];
        }
    }

    public function uploadPhotos($request): array
    {
        try {
            $ids = $this->extractCardIdsFromRequest($request);
            if ($ids === []) {
                return ['status' => 'error', 'message' => 'Не указана карточка'];
            }
            if (count($ids) > self::BULK_ACTION_CARD_LIMIT) {
                return ['status' => 'error', 'message' => 'Не более '.self::BULK_ACTION_CARD_LIMIT.' карточек за один запрос'];
            }

            if (count($ids) === 1) {
                $card = ModelsCards::find($ids[0]);
                if (! $card) {
                    return ['status' => 'error', 'message' => 'Карточка не найдена'];
                }

                return $this->formatSingleUploadPhotosResponse($this->performUploadPhotosForCard($card));
            }

            $results = [];
            foreach ($ids as $id) {
                $card = ModelsCards::find($id);
                if (! $card) {
                    $results[] = ['card_id' => $id, 'status' => 'error', 'message' => 'Карточка не найдена', 'orphan_for_clone' => false];

                    continue;
                }
                $row = $this->performUploadPhotosForCard($card);
                $row['card_id'] = $id;
                $results[] = $row;
            }

            return $this->aggregateBulkActionResults($results, 'Обновление фото');
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Ошибка: '.$e->getMessage()];
        }
    }

    public function recover($request): array
    {
        try {
            $card = ModelsCards::find((int) $request['card_id']);
            if (! $card) {
                return ['status' => 'error', 'message' => 'Карточка не найдена'];
            }
            $seller = Sellers::find((int) $card->sellerID);
            if (! $seller) {
                return ['status' => 'error', 'message' => 'Продавец карточки не найден'];
            }
            $nmId = $this->resolveNmIdForTrash($card);
            if ($nmId <= 0) {
                return ['status' => 'error', 'message' => 'Не удалось определить nmID для восстановления'];
            }
            $mappingQuery = $this->resolveMappingQuery($card);
            $mapping = $mappingQuery->first();
            $service = new WildberriesService($seller->wb_api_key, []);
            if (! $service->recoverCardsFromTrash([$nmId])) {
                return ['status' => 'error', 'message' => 'Не удалось восстановить карточку в WB'];
            }
            $column = Schema::hasColumn('skuMapping', 'user_blocked') ? 'user_blocked' : 'blocked';
            if ($mapping) {
                $mappingQuery->update([$column => 0]);
            }

            return ['status' => 'success', 'message' => 'Карточка восстановлена в WB'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Ошибка восстановления: '.$e->getMessage()];
        }
    }

    /**
     * @param  \Illuminate\Http\Request|array<string, mixed>  $request
     * @return list<int>
     */
    private function extractCardIdsFromRequest($request): array
    {
        $raw = is_array($request) ? ($request['card_ids'] ?? null) : ($request['card_ids'] ?? null);
        if (is_array($raw) && $raw !== []) {
            $ids = array_values(array_unique(array_map('intval', $raw)));

            return array_values(array_filter($ids, static fn (int $id) => $id > 0));
        }

        $one = (int) (is_array($request) ? ($request['card_id'] ?? 0) : ($request['card_id'] ?? 0));

        return $one > 0 ? [$one] : [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function aggregateBulkActionResults(array $rows, string $label): array
    {
        $ok = 0;
        $fail = 0;
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'success') {
                $ok++;
            } else {
                $fail++;
            }
        }
        $total = count($rows);
        if ($total === 0) {
            return ['status' => 'error', 'message' => 'Нет данных для обработки', 'bulk_results' => []];
        }

        $message = "{$label}: успешно {$ok} из {$total}";
        if ($fail > 0) {
            $message .= ", ошибок: {$fail}";
        }

        return [
            'status' => $ok > 0 ? 'success' : 'error',
            'message' => $message,
            'bulk_results' => $rows,
            'bulk_ok' => $ok,
            'bulk_fail' => $fail,
        ];
    }

    /**
     * @param  array{status: string, message: string, orphan_for_clone?: bool}  $inner
     * @return array<string, mixed>
     */
    private function formatSingleUploadPhotosResponse(array $inner): array
    {
        $out = [
            'status' => $inner['status'],
            'message' => $inner['message'],
        ];
        if (! empty($inner['orphan_for_clone'])) {
            $out['orphan_for_clone'] = true;
        }

        return $out;
    }

    /**
     * @return array{status: string, message: string, unit_user_blocked?: int}
     */
    private function performBlockCard(ModelsCards $card): array
    {
        $seller = Sellers::find((int) $card->sellerID);
        if (! $seller) {
            return ['status' => 'error', 'message' => 'Продавец карточки не найден'];
        }
        $nmId = $this->resolveNmIdForTrash($card);
        if ($nmId <= 0) {
            return ['status' => 'error', 'message' => 'Не удалось определить nmID для удаления'];
        }
        $mappingQuery = $this->resolveMappingQuery($card);
        $mapping = $mappingQuery->first();
        $service = new WildberriesService($seller->wb_api_key, []);
        if (! $service->moveCardsToTrash([$nmId])) {
            return ['status' => 'error', 'message' => 'Не удалось удалить карточку в WB'];
        }
        $column = Schema::hasColumn('skuMapping', 'user_blocked') ? 'user_blocked' : 'blocked';
        if ($mapping) {
            $mappingQuery->update([$column => 1]);
        }

        $hasUserBlocked = Schema::hasColumn('skuMapping', 'user_blocked');

        return [
            'status' => 'success',
            'message' => 'Карточка перенесена в корзину WB и помечена как заблокированная',
            'unit_user_blocked' => $hasUserBlocked ? 1 : 0,
        ];
    }

    /**
     * @return array{status: string, message: string, orphan_for_clone: bool}
     */
    private function performUploadPhotosForCard(ModelsCards $card): array
    {
        $seller = Sellers::find((int) $card->sellerID);
        if (! $seller) {
            return ['status' => 'error', 'message' => 'Продавец карточки не найден', 'orphan_for_clone' => false];
        }

        $supplierVendorCode = trim((string) $card->supplierVendorCode);
        if ($supplierVendorCode === '') {
            return ['status' => 'error', 'message' => 'У карточки не задан артикул поставщика', 'orphan_for_clone' => false];
        }

        // Sima-Land: донор фото (basket/getCardInfo по nm донора) должен совпадать с origSku в skuMapping, иначе — корзина WB + сирота.
        if ((int) $card->supplier === 20) {
            $mapping = SkuMapping::query()->where('origSku', (string) $card->vendorCode)->first();
            if (! $mapping) {
                return [
                    'status' => 'error',
                    'message' => 'Для Sima-Land нет записи skuMapping по vendorCode карточки — проверку донора фото выполнить нельзя',
                    'orphan_for_clone' => false,
                ];
            }
            $expectedOrigSku = trim((string) $mapping->origSku);
            if ($expectedOrigSku === '') {
                return ['status' => 'error', 'message' => 'В skuMapping пустой origSku — обновление фото отменено', 'orphan_for_clone' => false];
            }

            $photoDonorNm = WbJob::resolvePhotoSourceSupplierId(
                $supplierVendorCode,
                $card->vendorCode,
                $card->sku,
                (int) $card->nmID,
            );
            if ($photoDonorNm <= 0) {
                return ['status' => 'error', 'message' => 'Не удалось определить nmID донора для проверки фото', 'orphan_for_clone' => false];
            }

            $verdict = $this->simaPhotoDonorVersusOrigSkuVerdict($photoDonorNm, $expectedOrigSku);
            if ($verdict['code'] === 'inconclusive') {
                Log::warning('Cards uploadPhotos: проверка донора Sima-Land неоднозначна', [
                    'seller_id' => $seller->id,
                    'card_id' => $card->id,
                    'photo_donor_nm' => $photoDonorNm,
                    'expected_orig_sku' => $expectedOrigSku,
                    'basket_vendor' => $verdict['basket_vendor'] ?? '',
                    'detail_vendor' => $verdict['detail_vendor'] ?? '',
                ]);

                return [
                    'status' => 'error',
                    'message' => $verdict['message'] ?? 'Проверка донора фото неоднозначна. Повторите позже.',
                    'orphan_for_clone' => false,
                ];
            }
            if ($verdict['code'] === 'mismatch') {
                $nmId = (int) $card->nmID;
                if ($nmId <= 0) {
                    return ['status' => 'error', 'message' => 'У карточки нет nmID для отправки в корзину WB', 'orphan_for_clone' => false];
                }
                $service = new WildberriesService($seller->wb_api_key, []);
                $trashed = false;
                $trashError = null;
                try {
                    $trashed = $service->moveCardsToTrash([$nmId]);
                } catch (\Throwable $e) {
                    $trashError = $e->getMessage();
                }
                $card->orphan_for_clone = true;
                $card->save();
                if ($trashError !== null) {
                    return [
                        'status' => 'error',
                        'message' => 'Донор фото не совпадает с origSku (подтверждено по двум источникам WB); товар помечен сиротой. Ошибка корзины WB: '.$trashError,
                        'orphan_for_clone' => true,
                    ];
                }
                if (! $trashed) {
                    return [
                        'status' => 'error',
                        'message' => 'Донор фото не совпадает с origSku; товар помечен сиротой, но WB не подтвердил отправку в корзину',
                        'orphan_for_clone' => true,
                    ];
                }

                return [
                    'status' => 'success',
                    'message' => 'Донор фото не совпадает с origSku в skuMapping (basket и каталог WB согласны): карточка отправлена в корзину WB, товар помечен как сирота',
                    'orphan_for_clone' => true,
                ];
            }
        }

        $queued = WbJob::queuePhotoUploadAndFollowUpFetch(
            (int) $seller->id,
            (int) $card->nmID,
            $supplierVendorCode,
            $card->vendorCode,
            $card->sku,
            (int) $card->nmID,
            true,
            (int) $card->id
        );

        if (! $queued) {
            return ['status' => 'error', 'message' => 'Не удалось определить источник фото для загрузки', 'orphan_for_clone' => false];
        }

        return [
            'status' => 'success',
            'message' => 'Обновление фото поставлено в очередь',
            'orphan_for_clone' => false,
        ];
    }

    /**
     * Сравнение артикула донора с origSku: trim, затем числовое сравнение для чисто цифровых кодов.
     */
    private function simaVendorCodesSemanticallyEqual(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if (ctype_digit($a) && ctype_digit($b)) {
            return (int) $a === (int) $b;
        }

        return false;
    }

    /**
     * Сверка донора: basket/card.json (с ретраями) + card.wb.ru v4/detail.
     * Сирота только если оба источника согласны, что артикул не совпадает с origSku.
     *
     * @return array{
     *     code: 'match'|'mismatch'|'inconclusive',
     *     message?: string,
     *     basket_vendor?: string,
     *     detail_vendor?: string
     * }
     */
    private function simaPhotoDonorVersusOrigSkuVerdict(int $photoDonorNm, string $expectedOrigSku): array
    {
        $basketInfo = WBContent::getCardInfoWithRetries($photoDonorNm);
        $basketVc = is_array($basketInfo) ? trim((string) ($basketInfo['vendor_code'] ?? '')) : '';

        $detailVc = '';
        try {
            $detailVc = trim((string) WBContent::vendorCodeFromDetailByNm($photoDonorNm));
        } catch (\Throwable) {
            $detailVc = '';
        }

        $basketOk = $basketVc !== '' && $this->simaVendorCodesSemanticallyEqual($basketVc, $expectedOrigSku);
        $detailOk = $detailVc !== '' && $this->simaVendorCodesSemanticallyEqual($detailVc, $expectedOrigSku);
        if ($basketOk || $detailOk) {
            return ['code' => 'match'];
        }

        $basketSeen = $basketVc !== '';
        $detailSeen = $detailVc !== '';
        if ($basketSeen && $detailSeen && $this->simaVendorCodesSemanticallyEqual($basketVc, $detailVc)) {
            return [
                'code' => 'mismatch',
                'basket_vendor' => $basketVc,
                'detail_vendor' => $detailVc,
            ];
        }

        return [
            'code' => 'inconclusive',
            'message' => 'Проверка артикула донора по данным WB неоднозначна (возможен временный сбой каталога). Повторите позже. Товар не помечен сиротой, в корзину WB не отправляется.',
            'basket_vendor' => $basketVc,
            'detail_vendor' => $detailVc,
        ];
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

    /**
     * Очередь занята только если джоба уже выполняется или доступна воркеру сейчас.
     * Отложенные (delay) записи с available_at в будущем не блокируют новый запуск — иначе
     * follow-up getCardList через минуту не давал сразу запустить следующее выборочное обновление.
     */
    private static function isUpdateCardsProcessQueueBusy(): bool
    {
        $now = time();

        return Jobs::query()
            ->where('queue', 'updateCardsProcess')
            ->where(function ($q) use ($now) {
                $q->whereNotNull('reserved_at')
                    ->orWhere('available_at', '<=', $now);
            })
            ->exists();
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private static function normalizeSupplierVendorCodesInput($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            $parts = preg_split('/[\r\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_unique(array_filter(array_map('trim', $parts))));
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }
}
