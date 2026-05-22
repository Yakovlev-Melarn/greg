<?php

namespace App\Http\Controllers;

use App\Jobs\WbJob;
use App\Libs\Helper;
use App\Libs\WBContent;
use App\Models\Cards;
use App\Models\Sellers;
use App\Services\WildberriesService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class CopyCardController
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ConnectionException
     */
    public function index(Request $request): Factory|View
    {
        if ($request->isMethod('POST')) {
            $result = $this->copy($request->all(), false);
            if (! $result['success']) {
                $request->session()->flash('error', $this->copyErrorMessage($result));
            } else {
                $request->session()->flash('success', $result['success']);
                $followUp = $this->resolveFollowUpCardListPayload($request, $result);
                if ($followUp['textSearch'] !== null && $followUp['textSearch'] !== '') {
                    // Как при CloneProducts: без queueWbSku (nmID донора) и sourceSku (vendor_code) не ставится uploadPhotos.
                    WbJob::dispatch('getCardList', [
                        'seller_id' => session()->get('seller'),
                        'sourceSku' => $followUp['sourceSku'],
                        'queueWbSku' => $followUp['queueWbSku'],
                        'settings' => [
                            'settings' => [
                                'filter' => [
                                    'textSearch' => $followUp['textSearch'],
                                    'withPhoto' => -1,
                                ],
                            ],
                        ],
                    ])->onQueue('updateCardsProcess')->delay(now()->addMinute());
                }
            }
        }

        return view('CopyCard/index');
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ConnectionException
     */
    private function copy(array $post, $strictQuantity = true): array
    {
        $seller = Sellers::where('id', session()->get('seller'))->first();
        if ($this->cardIsExist((int) $post['nmID'], $seller->id)) {
            return ['success' => false, 'error' => ['message' => 'Карточка уже существует']];
        } else {
            $content = WBContent::getDetail($post['nmID']);
            if ($strictQuantity) {
                if ($content['totalQuantity'] < 5) {
                    return ['success' => false, 'error' => ['message' => 'Нет в наличии']];
                }
            }
            $info = WBContent::getCardInfo($post['nmID']);
            $service = new WildberriesService($seller->wb_api_key, $post);
            // Для габаритов через Sima-Land api/v3/item нужен sid товара Sima (не nmID WB).
            // Сначала «Артикул магазина» из формы, иначе средний сегмент vendor_code донора (может совпадать с nmID и не находиться в Sima).
            $dimensionSku = null;
            $storeArticle = isset($post['store_article']) ? trim((string) $post['store_article']) : '';
            if ($storeArticle !== '' && ctype_digit($storeArticle)) {
                $dimensionSku = $storeArticle;
            }
            if ($dimensionSku === null && is_array($info) && ! empty($info['vendor_code'])) {
                $middle = Helper::getVendorCode((string) $info['vendor_code']);
                if ($middle !== '' && ctype_digit($middle)) {
                    $dimensionSku = $middle;
                }
            }

            return $service->addProductFromSource($info, $dimensionSku);
        }
    }

    private function cardIsExist(int $nmID, int $sellerID)
    {
        return Cards::where('supplierVendorCode', $nmID)->where('sellerID', $sellerID)->exists();
    }

    /**
     * После cards/upload original_data — массив тел карточек: [ { subjectID, variants: [ { vendorCode } ] } ].
     */
    private function vendorCodeFromUploadOriginalData(array $result): ?string
    {
        $original = $result['original_data'] ?? null;
        if (! is_array($original) || $original === []) {
            return null;
        }

        $first = $original[0] ?? null;
        if (is_array($first)) {
            $variants = $first['variants'] ?? [];
            if (isset($variants[0]['vendorCode'])) {
                return (string) $variants[0]['vendorCode'];
            }
        }

        $variants = $original['variants'] ?? [];
        if (isset($variants[0]['vendorCode'])) {
            return (string) $variants[0]['vendorCode'];
        }

        return null;
    }

    private function resolveCardListTextSearch(array $result, Request $request): ?string
    {
        $fromPayload = $this->vendorCodeFromUploadOriginalData($result);
        if ($fromPayload !== null && $fromPayload !== '') {
            return $fromPayload;
        }

        $nmId = $request->input('nmID');
        if ($nmId === null || $nmId === '') {
            return null;
        }

        $info = WBContent::getCardInfo($nmId);
        if (is_array($info) && ! empty($info['vendor_code'])) {
            return (string) $info['vendor_code'];
        }

        return null;
    }

    /**
     * Для цепочки фото (WbJob::queuePhotoUploadAndFollowUpFetch) нужны nmID донора и vendor_code каталога.
     *
     * @return array{textSearch: ?string, sourceSku: ?string, queueWbSku: ?string}
     */
    private function resolveFollowUpCardListPayload(Request $request, array $uploadResult): array
    {
        $nmId = $request->input('nmID');
        $queueWbSku = ($nmId !== null && $nmId !== '') ? (string) $nmId : null;

        $info = $queueWbSku !== null ? WBContent::getCardInfo($nmId) : null;

        $textSearch = $this->vendorCodeFromUploadOriginalData($uploadResult);
        if (($textSearch === null || $textSearch === '') && is_array($info) && ! empty($info['vendor_code'])) {
            $textSearch = (string) $info['vendor_code'];
        }

        $sourceSku = (is_array($info) && ! empty($info['vendor_code']))
            ? (string) $info['vendor_code']
            : null;

        return [
            'textSearch' => ($textSearch !== null && $textSearch !== '') ? $textSearch : null,
            'sourceSku' => $sourceSku,
            'queueWbSku' => $queueWbSku,
        ];
    }

    private function copyErrorMessage(array $result): string
    {
        if (! empty($result['errorText'])) {
            return (string) $result['errorText'];
        }

        $err = $result['error'] ?? [];
        if (is_array($err)) {
            if (! empty($err['message'])) {
                return (string) $err['message'];
            }
            if (! empty($err['errorText'])) {
                return (string) $err['errorText'];
            }
        }

        return 'Ошибка при копировании карточки';
    }
}
