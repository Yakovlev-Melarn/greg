<?php

namespace App\Libs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class WBContent
{
    public static function getBreadcrumbs($nmId, $subjectId): array|bool
    {
        $result = Http::withHeaders([])
            ->timeout(180)
            ->connectTimeout(180)
            ->acceptJson()
            ->get("https://www.wildberries.ru/webapi/product/{$nmId}/data?subject={$subjectId}");
        $result = $result->json();
        if (isset($result['value']['data']['sitePath'])) {
            return $result['value']['data']['sitePath'];
        }

        return false;
    }

    /**
     * @throws ConnectionException
     */
    public static function getDetail($nmId, $first = true): ?array
    {
        $result = Http::withHeaders([])
            ->timeout(180)
            ->connectTimeout(180)
            ->acceptJson()
            ->get("https://card.wb.ru/cards/v4/detail?appType=1&curr=rub&dest=-1129907&lang=ru&nm={$nmId}");
        $result = $result->json();
        if (! isset($result['products'][0])) {
            return null;
        }
        if ($first) {
            return $result['products'][0];
        }

        return $result;
    }

    public static function getAmounts(string $nmIds): array|bool
    {
        $result = false;
        $response = self::getDetail($nmIds, false);
        if (isset($response['products'])) {
            foreach ($response['products'] as $productDetail) {
                $result[$productDetail['id']] = self::calcAmount($productDetail);
            }
        }

        return $result;
    }

    private static function calcAmount(array $productDetail): int
    {
        $amount = 0;
        if (! empty($productDetail['totalQuantity'])) {
            $amount = $productDetail['totalQuantity'];
        }

        return $amount;
    }

    public static function getAmount(int $nmId): int
    {
        $response = self::getDetail($nmId);
        if (! isset($response['sizes'])) {
            return 0;
        }

        return self::calcAmount($response);
    }

    public static function getPrices(string $nmId, $seller): array|false
    {
        $response = self::getDetail($nmId);
        $result = false;
        if (! empty($response['data']['products'])) {
            foreach ($response['data']['products'] as $product) {
                if (! isset($product['salePriceU'])) {
                    if ($card = Card::where('supplierSku', $nmId)->first()) {
                        if ($card->prices) {
                            if (! empty($card->prices->s_price)) {
                                $product['salePriceU'] = $card->prices->s_price * 100;
                            }
                        }
                    }
                    if (! isset($product['salePriceU'])) {
                        Telegramm::send("Нет цены закупки для товара {$nmId}. Продавец {$seller->name}\r\n", $seller->user->id);

                        continue;
                    }
                }
                $result[$product['id']] = $product['salePriceU'] / 100;
            }

            return $result;
        }

        return false;
    }

    public static function getPrice(int $nmId): int|bool
    {
        $response = self::getDetail($nmId);
        if (isset($response['products'][0]['sizes'][0]['price'])) {
            if (! empty($price = $response['products'][0]['sizes'][0]['price']['product'])) {
                return (int) $price / 100;
            }
        }

        return false;
    }

    public static function getCategoriesBySupplier($supplierId)
    {
        $response = Http::withHeaders([])
            ->timeout(180)
            ->connectTimeout(180)
            ->acceptJson()
            ->get("https://catalog.wb.ru/sellers/v8/filters?ab_testing=false&appType=1&curr=rub&dest=-431464&filters=xsubject&spp=30&uclusters=4&supplier={$supplierId}");

        return $response->json();
    }

    /**
     * Каталог WB по категории (xsubject). При ошибке HTTP или не-JSON возвращает null — как раньше для ретраев в CloneProductsJob.
     */
    public static function getPagesProductsByCategory($supplierId, $categoryId, $page = 1)
    {
        $page = max(1, (int) $page);
        $base = 'https://catalog.wb.ru/sellers/v4/catalog?ab_testing=false&appType=1&curr=rub&dest=-431464&lang=ru&sort=popular&spp=30&uclusters=4';
        $query = http_build_query([
            'supplier' => $supplierId,
            'xsubject' => $categoryId,
            'page' => $page,
        ]);
        $response = Http::withHeaders([])
            ->timeout(180)
            ->connectTimeout(180)
            ->acceptJson()
            ->get($base.'&'.$query);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    public static function getSkusByPage($url, $categoryId, $page)
    {
        $supplierId = Helper::getSupplierID($url);
        $page = max(1, (int) $page);
        $base = 'https://catalog.wb.ru/sellers/v4/catalog?ab_testing=false&appType=1&curr=rub&dest=-431464&lang=ru&sort=popular&spp=30&uclusters=4';
        $query = http_build_query([
            'supplier' => $supplierId,
            'xsubject' => $categoryId,
            'page' => $page,
        ]);
        $response = Http::withHeaders([])
            ->timeout(180)
            ->connectTimeout(180)
            ->acceptJson()
            ->get($base.'&'.$query);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    public static function getCardInfo($nmId)
    {
        $basket = Helper::getBasketNumber($nmId);
        $response = Http::withHeaders([])
            ->timeout(180)
            ->connectTimeout(180)
            ->acceptJson()
            ->get("https://basket-{$basket['basket']}.wbbasket.ru/vol{$basket['small']}/part{$basket['mid']}/{$nmId}/info/ru/card.json");

        return $response->json();
    }

    /**
     * Несколько попыток basket/card.json — публичный CDN иногда отдаёт пустой или неполный JSON.
     *
     * @return array<string, mixed>|null
     */
    public static function getCardInfoWithRetries(int $nmId, int $attempts = 5, int $sleepMs = 220): ?array
    {
        $attempts = max(1, min($attempts, 8));
        $sleepMs = max(0, min($sleepMs, 2000));
        $last = null;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $basket = Helper::getBasketNumber($nmId);
                $response = Http::withHeaders([])
                    ->timeout(40)
                    ->connectTimeout(15)
                    ->acceptJson()
                    ->get("https://basket-{$basket['basket']}.wbbasket.ru/vol{$basket['small']}/part{$basket['mid']}/{$nmId}/info/ru/card.json");
                $json = $response->successful() ? $response->json() : null;
                if (is_array($json)) {
                    $last = $json;
                    $vc = trim((string) ($json['vendor_code'] ?? ''));
                    if ($vc !== '' || isset($json['id']) || isset($json['imt_name']) || isset($json['media'])) {
                        return $json;
                    }
                }
            } catch (\Throwable) {
                // retry
            }
            if ($i < $attempts && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return is_array($last) ? $last : null;
    }

    /**
     * Артикул (vendor) из карточки card.wb.ru v4/detail (первый продукт).
     */
    public static function extractVendorCodeFromDetailProduct(?array $product): string
    {
        if (! is_array($product)) {
            return '';
        }
        foreach (['vendorCode', 'supplierVendorCode', 'vendor_code'] as $k) {
            $v = trim((string) ($product[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        $sizes = $product['sizes'] ?? [];
        if (is_array($sizes)) {
            foreach ($sizes as $sz) {
                if (! is_array($sz)) {
                    continue;
                }
                foreach (['vendorCode', 'supplierVendorCode'] as $k) {
                    $v = trim((string) ($sz[$k] ?? ''));
                    if ($v !== '') {
                        return $v;
                    }
                }
            }
        }

        return '';
    }

    public static function vendorCodeFromDetailByNm(int $nmId): string
    {
        $product = self::getDetail($nmId);

        return self::extractVendorCodeFromDetailProduct($product);
    }
}
