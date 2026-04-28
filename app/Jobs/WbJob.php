<?php

namespace App\Jobs;

use App\Libs\Helper;
use App\Libs\WBContent;
use App\Models\Sellers;
use App\Models\SkuMapping;
use App\Services\WildberriesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WbJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;
    public int $timeout = 3600;

    public function __construct(
        private readonly string $action,
        private readonly array  $params = []
    )
    {
    }

    public function handle(): void
    {
        self::{$this->action}($this->params);
    }

    /**
     * @throws ConnectionException
     */
    private function getCardList($params): void
    {
        if ($params['seller_id']) {
            $sku = empty($params['sku']) ? null : $params['sku'];
            $nmID = empty($params['nmID']) ? null : $params['nmID'];
            $seller = Sellers::find($params['seller_id']);
            $service = new WildberriesService($seller->wb_api_key, []);
            $result = $service->getCardList($params['settings']);
            $cursor = $result['data']['cursor']['nmID'];
            $updatedAt = $result['data']['cursor']['updatedAt'];
            $total = $result['data']['cursor']['total'];
            $this->updateCard($result['data']['cards'], $seller, $sku, $nmID);
            if ($total == 100) {
                self::dispatch('getCardList', [
                    'seller_id' => $params['seller_id'],
                    'settings' => [
                        'settings' => [
                            'sort' => ['ascending' => true],
                            'cursor' => [
                                'limit' => 100,
                                'updatedAt' => $updatedAt,
                                'nmID' => $cursor
                            ],
                            'filter' => ['withPhoto' => -1]
                        ]
                    ]
                ])->onQueue('updateCardsProcess');
            }
        } else {
            echo 'error seller_id is null';
        }
    }

    /**
     * @throws ConnectionException
     */
    private function updateStocks($params): void
    {
        if (empty($params['seller_id'])) {
            return;
        }
        $seller = Sellers::find($params['seller_id']);
        if (!$seller) {
            return;
        }
        $cards = $this->getSellerCards($seller);
        $vendorToChrtMap = $this->buildVendorToChrtMap($cards);
        $stockData = $this->fetchStockQuantities($cards, $vendorToChrtMap);
        $this->sendStockUpdates($seller, $stockData);
    }

    private function getSellerCards(Sellers $seller): Collection
    {
        return $seller->cards()
            ->where('supplier', 10)
            ->get();
    }

    private function buildVendorToChrtMap(Collection $cards): array
    {
        return $cards->pluck('chrtID', 'vendorCode')->toArray();
    }

    private function fetchStockQuantities(Collection $cards, array $vendorToChrtMap): array
    {
        $vendorCodes = $cards->pluck('vendorCode')->toArray();
        $chunks = array_chunk($vendorCodes, 100);
        $result = [];
        $total = count($chunks);
        echo "Очередь на запрос из " . $total . " пачек\n";
        foreach ($chunks as $chunk) {
            echo "Осталось " . ($total--) . " пачек\n";
            $vendorCodeString = implode(';', $chunk);
            $stocks = WBContent::getAmounts($vendorCodeString);
            foreach ($stocks as $vendorCode => $quantity) {
                if (isset($vendorToChrtMap[$vendorCode])) {
                    $chrtID = $vendorToChrtMap[$vendorCode];
                    $result[] = [
                        'chrtId' => $chrtID,
                        'amount' => $quantity > 5 ? 5 : 0
                    ];
                }
            }
        }
        return $result;
    }

    /**
     * @throws ConnectionException
     */
    private function sendStockUpdates(Sellers $seller, array $stockData): void
    {
        $chunks = array_chunk($stockData, 1000);
        $service = new WildberriesService($seller->wb_api_key, []);
        $total = count($chunks);
        echo "Очередь на отправку из " . $total . " пачек\n";
        foreach ($chunks as $chunk) {
            $service->updateStocks($seller->wb_warehouse_id, $chunk);
            echo "Осталось " . ($total--) . " пачек\n";
        }
    }

    /**
     * @throws ConnectionException
     */
    private function uploadPhotos($params): void
    {
        $seller = Sellers::find($params['seller_id']);
        $service = new WildberriesService($seller->wb_api_key, []);
        $basket = Helper::getBasketNumber($params['supplierID']);
        $info = WBContent::getCardInfo($params['supplierID']);
        $photoCount = $info['media']['photo_count'];
        $data = [];
        for ($i = 1; $i <= $photoCount; $i++) {
            $data[] = "https://basket-{$basket['basket']}.wbbasket.ru/vol{$basket['small']}"
                . "/part{$basket['mid']}/{$params['supplierID']}/images/big/{$i}.webp";
        }
        $service->uploadPhotos($params['nmID'], $data);
    }

    private function updateCard($cardsData, Sellers $seller, $sku = null, $nmID = null): void
    {
        foreach ($cardsData as $card) {
            $photo = "";
            if (isset($card['photos']) && count($card['photos']) > 0) {
                $photo = $card['photos'][0]['c246x328'];
            } else {
                $postData = [
                    'supplierID' => empty($nmID) ? Helper::getVendorCode($card['vendorCode']) : $nmID,
                    'nmID' => $card['nmID'],
                    'seller_id' => $seller->id
                ];
                self::dispatch('uploadPhotos', $postData)->onQueue('uploadPhotos');
                self::dispatch('getCardList', [
                    'seller_id' => $seller->id,
                    'sku' => $sku,
                    'nmID' => empty($nmID) ? Helper::getVendorCode($card['vendorCode']) : $nmID,
                    'settings' => [
                        'settings' => [
                            'sort' => ['ascending' => true],
                            'cursor' => [
                                'limit' => 1
                            ],
                            'filter' => [
                                'textSearch' => $card['vendorCode'],
                                'withPhoto' => -1
                            ]
                        ]
                    ]
                ])->onQueue('updateCardsProcess')->delay(now()->addMinute());
            }
            if (!empty($photo)) {
                $data = [
                    'updated_at' => $card['updatedAt'],
                    'nmID' => $card['nmID'],
                    'supplier' => Helper::getSupplier($card['vendorCode']),
                    'supplierVendorCode' => $card['vendorCode'],
                    'vendorCode' => Helper::getVendorCode($card['vendorCode']),
                    'supplierName' => Helper::getSupplierName($card['vendorCode']),
                    'productName' => $card['title'],
                    'chrtID' => $card['sizes'][0]['chrtID'],
                    'photo' => $photo,
                    'sku' => $nmID
                ];
                $seller->cards()->firstOrCreate(
                    ['nmID' => $card['nmID']],
                    $data
                );
            }
        }
    }

    private function updatePrice(): void
    {
        $skuMappings = SkuMapping::where('needUpdatePrice', 1)->get();
        foreach ($skuMappings as $skuMapping) {
            $calculatedPrice = $skuMapping->total_cost - ($skuMapping->total_cost * 0.25);
            if ($calculatedPrice < $skuMapping->wbPrice) {
                $sellPrice = $skuMapping->wbPrice + ($skuMapping->wbPrice * 0.25);
            } else {
                $sellPrice = $skuMapping->total_cost;
            }
            $sellPrice = ceil($sellPrice);
            try {
                $seller = Sellers::find($skuMapping->card->sellerID);
                $nmID = $skuMapping->card->nmID;
                if (!$seller || !$nmID) {
                    throw new \Exception('Не удалось получить данные продавца или nmID');
                }
                $service = new WildberriesService($seller->wb_api_key, []);
                $priceData = [[
                    'nmID' => $nmID,
                    'price' => $sellPrice
                ]];
                $resultUpdatePrice = $service->updatePrice($priceData);
                if ($resultUpdatePrice) {
                    $skuMapping->needUpdatePrice = 0;
                    $skuMapping->save();
                }
            } catch (\Exception $e) {
                echo '🚨 Ошибка при обновлении цены: ' . $e->getMessage() . "\r\n";
            }
        }
        self::dispatch('updatePrice', [])->onQueue('updatePrice')->delay(now()->addHour());
    }
}
