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
    public static function getDetail($nmId, $first = true): array|null
    {
        $result = Http::withHeaders([])
            ->timeout(180)
            ->connectTimeout(180)
            ->acceptJson()
            ->get("https://card.wb.ru/cards/v4/detail?appType=1&curr=rub&dest=-1129907&lang=ru&nm={$nmId}");
        $result = $result->json();
        if (!isset($result['products'][0])) {
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
        if (!empty($productDetail['totalQuantity'])) {
            $amount = $productDetail['totalQuantity'];
        }
        return $amount;
    }

    public static function getAmount(int $nmId): int
    {
        $response = self::getDetail($nmId);
        if (!isset($response['sizes'])) {
            return 0;
        }
        return self::calcAmount($response);
    }

    public static function getPrices(string $nmId, $seller): array|false
    {
        $response = self::getDetail($nmId);
        $result = false;
        if (!empty($response['data']['products'])) {
            foreach ($response['data']['products'] as $product) {
                if (!isset($product['salePriceU'])) {
                    if ($card = Card::where('supplierSku', $nmId)->first()) {
                        if ($card->prices) {
                            if (!empty($card->prices->s_price)) {
                                $product['salePriceU'] = $card->prices->s_price * 100;
                            }
                        }
                    }
                    if (!isset($product['salePriceU'])) {
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
            if (!empty($price = $response['products'][0]['sizes'][0]['price']['product'])) {
                return (int)$price / 100;
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

    public static function getPagesProductsByCategory($supplierId, $categoryId, $page = 1)
    {
        $url = "&supplier={$supplierId}&xsubject={$categoryId}&page={$page}";
        $response = Http::withHeaders([])
            ->timeout(180)
            ->connectTimeout(180)
            ->acceptJson()
            ->get("https://catalog.wb.ru/sellers/v4/catalog?ab_testing=false&appType=1&curr=rub&dest=-431464&lang=ru&page=1&sort=popular&spp=30&uclusters=4{$url}");
        return $response->json();
    }

    public static function getSkusByPage($url, $categoryId, $page)
    {
        $supplierId = Helper::getSupplierID($url);
        $url = "&supplier={$supplierId}&xsubject={$categoryId}&page={$page}";
        $response = Http::withHeaders([])
            ->timeout(180)
            ->connectTimeout(180)
            ->acceptJson()
            ->get("https://catalog.wb.ru/sellers/v4/catalog?ab_testing=false&appType=1&curr=rub&dest=-431464&lang=ru&page=1&sort=popular&spp=30&uclusters=4{$url}");
        return $response->json();
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
}
