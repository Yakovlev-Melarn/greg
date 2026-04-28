<?php

namespace App\Services;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WildberriesService
{
    protected string $apiKey;
    protected array $cardConfig;
    protected string $vendorCode;
    protected string $baseUrl = 'https://content-api.wildberries.ru/content/v2/';

    public function __construct($apiKey, $cardConfig)
    {
        $this->apiKey = $apiKey;
        $this->cardConfig = $cardConfig;
        if (!empty($cardConfig)) {
            $this->vendorCode = $cardConfig['prefix'] . '-' . $cardConfig['nmID'] . '-' . $cardConfig['package'];
        }
    }

    /**
     * @throws ConnectionException
     */
    public function addProductFromSource(array $sourceData, $sku = null): array
    {
        $productData = $this->transformSourceData($sourceData, $sku);
        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post($this->baseUrl . 'cards/upload', [$productData]);
        return $this->handleResponse($response, $productData);
    }

    /**
     * @throws ConnectionException
     * @throws \Exception
     */
    private function transformSourceData(array $source, $sku = null): array
    {
        return [
            'subjectID' => $source['data']['subject_id'],
            'variants' => [
                [
                    'brand' => $this->extractBrand($source),
                    'title' => $source['imt_name'] ?? '',
                    'description' => $source['description'] ?? '',
                    'vendorCode' => empty($sku) ? $this->vendorCode : "{$this->cardConfig['prefix']}-{$sku}-{$this->cardConfig['package']}",
                    'dimensions' => $this->extractDimensions($sku),
                    'sizes' => $this->extractSizes($source),
                    'characteristics' => $this->extractCharacteristics($source)
                ]
            ]
        ];
    }

    private function extractBrand(array $source): string
    {
        $brand = $source['selling']['brand_name'] ?? 'TopGiper';
        return match (strtolower($brand)) {
            'hoco' => 'HOCO',
            'smartbuy' => 'SmartBuy',
            'torso' => 'TORSO',
            'grand caratt' => 'GRAND CARATT',
            'astrohim' => 'ASTROHIM',
            'оки-чпоки' => 'Оки Чпоки',
            'huter' => 'HUTER',
            'арт узор' => 'Арт узор',
            'windigo' => 'WINDIGO',
            'take it easy' => 'Take it easy',
            'qf' => 'Queen fair',
            default => $brand,
        };
    }

    /**
     * @throws \Exception
     */
    private function extractDimensions($sku = null): array
    {
        if (empty($sku)) {
            echo "Sku is empty \r\n";
            return [
                'length' => 10,
                'width' => 10,
                'height' => 10,
                'weightBrutto' => 0.5
            ];
        }
        $response = SimService::fetchProductData($sku);
        SimService::validateResponse($response);
        $item = $response['items'][0];
        $result = SimService::getProductDimensions($item, $item['product_volume'] ?? 0, (string)$sku);
        return [
            'length' => floor($result['length']) > 0 ? floor($result['length']) : 1,
            'width' => floor($result['width']) > 0 ? floor($result['width']) : 1,
            'height' => floor($result['depth']) > 0 ? floor($result['depth']) : 1,
            'weightBrutto' => $result['weight_kg']
        ];
    }

    private function extractSizes(array $source): array
    {
        return [['price' => (int)$this->cardConfig['price']]];
    }

    /**
     * @throws ConnectionException
     */
    private function extractCharacteristics(array $source): array
    {
        $result = [];
        $chars = $this->getCharacteristics((int)$source['data']['subject_id']);
        if (!empty($source['options'])) {
            foreach ($source['options'] as $characteristic) {
                foreach ($chars['data']['data'] as $char) {
                    if ($char['name'] === $characteristic['name']) {
                        if ($char['charcType'] === 4) {
                            $result[] = ['id' => $char['charcID'], 'value' => (int)$characteristic['value']];
                        } else {
                            $result[] = ['id' => $char['charcID'], 'value' => [$characteristic['value']]];
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * @throws ConnectionException
     */
    private function getCharacteristics(int $subject_id): array
    {
        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->get("{$this->baseUrl}object/charcs/{$subject_id}");
        return $this->handleResponse($response, [$subject_id]);
    }

    private function handleResponse(PromiseInterface|Response $response, array $sourceData): array
    {
        if ($response->successful()) {
            return [
                'success' => true,
                'http_code' => $response->status(),
                'data' => $response->json(),
                'original_data' => $sourceData,
            ];
        }
        return [
            'success' => false,
            'http_code' => $response->status(),
            'error' => $response->json() ?? ['message' => 'Unknown error'],
            'original_data' => $sourceData,
        ];
    }

    /**
     * @throws ConnectionException
     */
    public function getCardList($settings): array
    {
        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post("{$this->baseUrl}get/cards/list", $settings);
        return $this->handleResponse($response, [$settings]);
    }

    /**
     * @throws ConnectionException
     */
    public function uploadPhotos($nmID, $data): void
    {

        $result = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post("https://content-api.wildberries.ru/content/v3/media/save", [
                'nmId' => (int)$nmID,
                'data' => $data
            ]);
        print_r($result->json());
    }

    /**
     * @throws ConnectionException
     */
    public function updateStocks(int $whID, array $chunk): void
    {
        $result = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->put("https://marketplace-api.wildberries.ru/api/v3/stocks/{$whID}", [
                'stocks' => $chunk,
            ]);
        echo "Статус запроса " . $result->status() . "\n";
    }

    /**
     * @throws ConnectionException
     */
    public function updatePrice(array $data): bool
    {
        $result = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post("https://discounts-prices-api.wildberries.ru/api/v2/upload/task", [
                'data' => $data,
            ]);
        echo "Статус запроса " . $result->status() . "\n";
        return $result->status() == 200;
    }

    /**
     * @throws ConnectionException
     */
    public function updateDimension(array $dimensionData): bool
    {
        $result = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post("https://content-api.wildberries.ru/content/v2/cards/update", $dimensionData);
        echo "Статус запроса " . $result->status() . "\n";
        return $result->status() == 200;
    }
}
