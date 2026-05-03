<?php

namespace App\Services;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    public function addProductFromSource(array $sourceData, ?string $sku = null): array
    {
        $productData = $this->transformSourceData($sourceData, $sku);
        $result = $this->sendCardsUpload([$productData], 30);
        if ($this->needsDimensionsArrayFormat($result)) {
            $dimensionsArrayPayload = $this->withDimensionsArrayPayload([$productData]);
            $retryResult = $this->sendCardsUpload($dimensionsArrayPayload, 30);
            if (($retryResult['success'] ?? false) === true) {
                return $retryResult;
            }
        }
        return $result;
    }

    /**
     * @throws ConnectionException
     * @throws \Exception
     */
    public function addProductsFromSourceBatch(array $products): array
    {
        $payload = [];
        $itemsMeta = [];
        $skippedItems = [];

        foreach ($products as $product) {
            $sourceData = $product['sourceData'] ?? [];
            $sku = $product['sku'] ?? null;
            $queueSku = $product['queueSku'] ?? null;
            $itemCardConfig = $product['itemCardConfig'] ?? null;
            try {
                $productData = $this->transformSourceData($sourceData, $sku, $itemCardConfig);
                $payload[] = $productData;
                $itemsMeta[] = [
                    'queueSku' => $queueSku,
                    'sid' => $sku,
                    'vendorCode' => $productData['variants'][0]['vendorCode'] ?? null,
                ];
            } catch (\Exception $e) {
                $skippedItems[] = [
                    'queueSku' => $queueSku,
                    'sid' => $sku,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (empty($payload)) {
            return [
                'success' => false,
                'http_code' => 422,
                'error' => ['message' => 'Нет валидных товаров для batch upload'],
                'original_data' => [],
                'items' => [],
                'skipped_items' => $skippedItems,
            ];
        }

        $result = $this->sendCardsUpload($payload, 60);
        $usedIndividualUpload = false;

        if ($this->needsDimensionsArrayFormat($result)) {
            $dimensionsArrayPayload = $this->withDimensionsArrayPayload($payload);
            $retryResult = $this->sendCardsUpload($dimensionsArrayPayload, 60);
            if (($retryResult['success'] ?? false) === true) {
                $result = $retryResult;
            } else {
                $payload = $dimensionsArrayPayload;
            }
        }

        // WB may reject whole batch with a generic weightBrutto message.
        // In this case fallback to per-card upload to isolate bad items.
        if ($this->needsDimensionsArrayFormat($result) && count($payload) > 1) {
            $result = $this->uploadCardsIndividually($payload, $itemsMeta, $skippedItems);
            $usedIndividualUpload = true;
        }

        // Same batch rejection when one card lacks required subject characteristics — isolate by vendor.
        if (!$usedIndividualUpload && $this->needsCharacteristicsBatchIsolateFailure($result) && count($payload) > 1) {
            $result = $this->uploadCardsIndividually($payload, $itemsMeta, $skippedItems);
            $usedIndividualUpload = true;
        }

        if (($result['success'] ?? false) === true && !$usedIndividualUpload) {
            $result['items'] = $itemsMeta;
        }
        if (!$usedIndividualUpload) {
            $result['skipped_items'] = $skippedItems;
        }
        return $result;
    }

    /**
     * @throws ConnectionException
     */
    private function sendCardsUpload(array $payload, int $timeout): array
    {
        $lastExceptionMessage = null;
        $connectionFailures = 0;
        $rateLimitRetries = 0;
        $maxConnectionRetries = 3;
        $maxRateLimitRetries = 12;

        while (true) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                    ->timeout($timeout + min($rateLimitRetries * 5, 60))
                    ->post($this->baseUrl . 'cards/upload', $payload);

                $result = $this->handleResponse($response, $payload);

                if (($result['success'] ?? false) === true) {
                    return $result;
                }

                if ($this->isRateLimitedResult($result, $response)) {
                    $rateLimitRetries++;
                    if ($rateLimitRetries > $maxRateLimitRetries) {
                        return $result;
                    }
                    $seconds = $this->rateLimitBackoffSeconds($rateLimitRetries, $response);
                    Log::warning('Wildberries cards/upload rate limited, retry after backoff', [
                        'sleep_seconds' => $seconds,
                        'attempt' => $rateLimitRetries,
                    ]);
                    sleep($seconds);
                    continue;
                }

                return $result;
            } catch (ConnectionException $e) {
                $lastExceptionMessage = $e->getMessage();
                $connectionFailures++;
                if ($connectionFailures >= $maxConnectionRetries) {
                    break;
                }
                sleep(2 * $connectionFailures);
            }
        }

        return [
            'success' => false,
            'http_code' => 0,
            'error' => [
                'error' => 1,
                'errorText' => $lastExceptionMessage ?? 'Network timeout while uploading cards',
                'additionalErrors' => [],
            ],
            'original_data' => $payload,
        ];
    }

    private function isRateLimitedResult(array $result, Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        $err = $result['error'] ?? [];
        if (!is_array($err)) {
            return false;
        }

        if ((int)($err['status'] ?? 0) === 429) {
            return true;
        }

        $title = mb_strtolower((string)($err['title'] ?? ''));
        if (str_contains($title, 'too many requests')) {
            return true;
        }

        $detail = mb_strtolower((string)($err['detail'] ?? ''));
        if (str_contains($detail, 'global limiter') ||
            str_contains($detail, 'per seller') ||
            str_contains($detail, 'limited by')) {
            return true;
        }

        $errorText = mb_strtolower((string)($err['errorText'] ?? ''));
        return str_contains($errorText, 'too many requests');
    }

    private function rateLimitBackoffSeconds(int $attempt, Response $response): int
    {
        $retryAfter = $response->header('Retry-After');
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return max(1, min(120, (int)$retryAfter));
        }

        return min(60, max(2, (int) pow(2, min($attempt, 6))));
    }

    private function needsDimensionsArrayFormat(array $result): bool
    {
        if (($result['success'] ?? false) === true) {
            return false;
        }
        $errorText = mb_strtolower((string)($result['error']['errorText'] ?? ''));
        return str_contains($errorText, 'вес с упаковкой') &&
            str_contains($errorText, 'variants[i].dimensions[j].weightbrutto');
    }

    /**
     * Whole batch fails with HTTP 400 when any card is missing required characteristics for its subject.
     */
    private function needsCharacteristicsBatchIsolateFailure(array $result): bool
    {
        if (($result['success'] ?? false) === true) {
            return false;
        }
        if ((int)($result['http_code'] ?? 0) !== 400) {
            return false;
        }
        $errorText = mb_strtolower((string)($result['error']['errorText'] ?? ''));
        if (str_contains($errorText, 'required characteristics') ||
            str_contains($errorText, 'lacks required characteristics')) {
            return true;
        }
        if (str_contains($errorText, 'характеристик') && str_contains($errorText, 'обязательн')) {
            return true;
        }
        $additional = $result['error']['additionalErrors'] ?? [];
        if (!is_array($additional) || $additional === []) {
            return false;
        }
        foreach ($additional as $msg) {
            $m = mb_strtolower((string)$msg);
            if (str_contains($m, 'missing required characteristics') ||
                str_contains($m, 'required characteristics')) {
                return true;
            }
        }

        return false;
    }

    private function withDimensionsArrayPayload(array $payload): array
    {
        foreach ($payload as &$card) {
            if (empty($card['variants']) || !is_array($card['variants'])) {
                continue;
            }
            foreach ($card['variants'] as &$variant) {
                if (empty($variant['dimensions']) || !is_array($variant['dimensions'])) {
                    continue;
                }
                if (array_is_list($variant['dimensions'])) {
                    continue;
                }
                $sizesCount = isset($variant['sizes']) && is_array($variant['sizes'])
                    ? max(1, count($variant['sizes']))
                    : 1;
                $variant['dimensions'] = array_fill(0, $sizesCount, $variant['dimensions']);
            }
            unset($variant);
        }
        unset($card);

        return $payload;
    }

    private function uploadCardsIndividually(array $payload, array $itemsMeta, array $initialSkippedItems): array
    {
        $successfulItems = [];
        $failedItems = $initialSkippedItems;
        $lastError = null;
        /** Pause between single-card calls to stay under WB per-seller rate limits */
        $pauseMicrosBetweenCards = max(0, (int) config('services.wildberries.upload_pause_ms_between_cards', 500)) * 1000;

        foreach ($payload as $index => $cardPayload) {
            if ($index > 0 && $pauseMicrosBetweenCards > 0) {
                usleep($pauseMicrosBetweenCards);
            }

            $singlePayload = [$cardPayload];
            $singleResult = $this->sendCardsUpload($singlePayload, 30);

            if ($this->needsDimensionsArrayFormat($singleResult)) {
                $singleRetryPayload = $this->withDimensionsArrayPayload($singlePayload);
                $singleRetryResult = $this->sendCardsUpload($singleRetryPayload, 30);
                if (($singleRetryResult['success'] ?? false) === true) {
                    $singleResult = $singleRetryResult;
                }
            }

            if (($singleResult['success'] ?? false) === true) {
                if (isset($itemsMeta[$index])) {
                    $successfulItems[] = $itemsMeta[$index];
                }
                continue;
            }

            $lastError = $singleResult['error'] ?? ['message' => 'Unknown error'];
            $meta = $itemsMeta[$index] ?? ['queueSku' => null, 'sid' => null];
            $failedItems[] = [
                'queueSku' => $meta['queueSku'] ?? null,
                'sid' => $meta['sid'] ?? null,
                'error' => $lastError['errorText'] ?? ($lastError['message'] ?? 'Unknown error'),
            ];
        }

        if (empty($successfulItems)) {
            return [
                'success' => false,
                'http_code' => 400,
                'error' => $lastError ?? ['message' => 'Batch and per-item upload failed'],
                'original_data' => $payload,
                'items' => [],
                'skipped_items' => $failedItems,
            ];
        }

        return [
            'success' => true,
            'http_code' => 200,
            'data' => ['partial' => !empty($failedItems)],
            'original_data' => $payload,
            'items' => $successfulItems,
            'skipped_items' => $failedItems,
        ];
    }

    /**
     * @throws ConnectionException
     * @throws \Exception
     */
    private function transformSourceData(array $source, ?string $sku = null, ?array $itemCardConfig = null): array
    {
        $cfg = $itemCardConfig ?? $this->cardConfig;
        $vendorCode = empty($sku)
            ? ($cfg['prefix'] . '-' . $cfg['nmID'] . '-' . $cfg['package'])
            : "{$cfg['prefix']}-{$sku}-{$cfg['package']}";

        return [
            'subjectID' => $source['data']['subject_id'],
            'variants' => [
                [
                    'brand' => $this->extractBrand($source),
                    'title' => $source['imt_name'] ?? '',
                    'description' => $source['description'] ?? '',
                    'vendorCode' => $vendorCode,
                    'dimensions' => $this->extractDimensions($sku),
                    'sizes' => $this->extractSizes($cfg),
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
            'bayerlux' => 'BayerLux',
            default => $brand,
        };
    }

    /**
     * @throws \Exception
     */
    private function extractDimensions(?string $sku = null): array
    {
        if (empty($sku)) {
            echo "Sku is empty \r\n";
            return [
                'length' => 10,
                'width' => 10,
                'height' => 10,
                'weightBrutto' => 0.5,
            ];
        }
        $response = SimService::fetchProductData($sku);
        SimService::validateResponse($response);
        $item = $response['items'][0];
        $result = SimService::getProductDimensions($item, $item['product_volume'] ?? 0, (string)$sku);
        // WB rejects too-small brutto values with a generic weightBrutto format error.
        // Keep kilograms, but enforce a safe minimum for API validation.
        $weightBrutto = max(0.1, (float)($result['weight_kg'] ?? 0.5));

        return [
            'length' => floor($result['length']) > 0 ? floor($result['length']) : 1,
            'width' => floor($result['width']) > 0 ? floor($result['width']) : 1,
            'height' => floor($result['depth']) > 0 ? floor($result['depth']) : 1,
            'weightBrutto' => round($weightBrutto, 3),
        ];
    }

    /**
     * @param array $cfg Конфиг карточки (цена в рублях, как в cardConfig)
     */
    private function extractSizes(array $cfg): array
    {
        return [['price' => (int)$cfg['price']]];
    }

    /**
     * @throws ConnectionException
     */
    private function extractCharacteristics(array $source): array
    {
        $result = [];
        $blockedCharacteristicIds = [
            // Weight-related characteristics (can conflict with dimensions.weightBrutto).
            88952, // Вес с упаковкой (alt for some subjects)
            88953, // Вес без упаковки / related weight field
            89064, // Вес (subject-dependent meaning)
            // Package dimensions (subject-dependent IDs).
            90849, // Ширина упаковки (old set)
            90846, // Длина упаковки (old set)
            90745, // Высота упаковки (old set)
            90673, // Ширина упаковки (new set)
            90630, // Длина упаковки (new set)
            90652, // Высота упаковки (new set)
        ];
        $blockedNames = [
            'вес с упаковкой',
            'вес без упаковки',
            'вес',
            'длина упаковки',
            'ширина упаковки',
            'высота упаковки',
            'глубина упаковки',
        ];
        $chars = $this->getCharacteristics((int)$source['data']['subject_id']);
        if (!empty($source['options'])) {
            foreach ($source['options'] as $characteristic) {
                foreach ($chars['data']['data'] as $char) {
                    if ($char['name'] === $characteristic['name']) {
                        $charName = mb_strtolower((string)($char['name'] ?? ''));
                        if (
                            in_array((int)$char['charcID'], $blockedCharacteristicIds, true) ||
                            in_array($charName, $blockedNames, true)
                        ) {
                            continue;
                        }
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
            ->post('https://content-api.wildberries.ru/content/v3/media/save', [
                'nmId' => (int) $nmID,
                'data' => $data,
            ]);

        $json = $result->json();
        if (! empty($json['error'])) {
            Log::warning('Wildberries media/save returned error', [
                'nmID' => $nmID,
                'response' => $json,
                'http_status' => $result->status(),
            ]);
        }
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

    /**
     * @throws ConnectionException
     */
    public function moveCardsToTrash(array $nmIds): bool
    {
        $result = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post("https://content-api.wildberries.ru/content/v2/cards/delete/trash", [
                'nmIDs' => array_values($nmIds),
            ]);
        return $result->status() === 200;
    }

    /**
     * @throws ConnectionException
     */
    public function recoverCardsFromTrash(array $nmIds): bool
    {
        $result = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post("https://content-api.wildberries.ru/content/v2/cards/recover", [
                'nmIDs' => array_values($nmIds),
            ]);
        return $result->status() === 200;
    }
}
