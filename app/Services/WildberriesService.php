<?php

namespace App\Services;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WildberriesService
{
    /** @var array<string, float> microtime: не раньше этого момента слать следующий delete/trash (на ключ API) */
    private static array $trashEarliestNextRequestAt = [];

    protected string $apiKey;

    protected array $cardConfig;

    protected string $vendorCode;

    protected string $baseUrl = 'https://content-api.wildberries.ru/content/v2/';

    public function __construct($apiKey, $cardConfig)
    {
        $this->apiKey = $apiKey;
        $this->cardConfig = $cardConfig;
        if (! empty($cardConfig)) {
            $this->vendorCode = $cardConfig['prefix'].'-'.$cardConfig['nmID'].'-'.$cardConfig['package'];
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
        if (! $usedIndividualUpload && $this->needsCharacteristicsBatchIsolateFailure($result) && count($payload) > 1) {
            $result = $this->uploadCardsIndividually($payload, $itemsMeta, $skippedItems);
            $usedIndividualUpload = true;
        }

        if (($result['success'] ?? false) === true && ! $usedIndividualUpload) {
            $result['items'] = $itemsMeta;
        }
        if (! $usedIndividualUpload) {
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
                    ->post($this->baseUrl.'cards/upload', $payload);

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
        if (! is_array($err)) {
            return false;
        }

        if ((int) ($err['status'] ?? 0) === 429) {
            return true;
        }

        $title = mb_strtolower((string) ($err['title'] ?? ''));
        if (str_contains($title, 'too many requests')) {
            return true;
        }

        $detail = mb_strtolower((string) ($err['detail'] ?? ''));
        if (str_contains($detail, 'global limiter') ||
            str_contains($detail, 'per seller') ||
            str_contains($detail, 'limited by')) {
            return true;
        }

        $errorText = mb_strtolower((string) ($err['errorText'] ?? ''));

        return str_contains($errorText, 'too many requests');
    }

    private function rateLimitBackoffSeconds(int $attempt, Response $response): int
    {
        $retryAfter = $response->header('Retry-After');
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return max(1, min(120, (int) $retryAfter));
        }

        return min(60, max(2, (int) pow(2, min($attempt, 6))));
    }

    private function needsDimensionsArrayFormat(array $result): bool
    {
        if (($result['success'] ?? false) === true) {
            return false;
        }
        $errorText = mb_strtolower((string) ($result['error']['errorText'] ?? ''));

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
        if ((int) ($result['http_code'] ?? 0) !== 400) {
            return false;
        }
        $errorText = mb_strtolower((string) ($result['error']['errorText'] ?? ''));
        if (str_contains($errorText, 'required characteristics') ||
            str_contains($errorText, 'lacks required characteristics')) {
            return true;
        }
        if (str_contains($errorText, 'характеристик') && str_contains($errorText, 'обязательн')) {
            return true;
        }
        $additional = $result['error']['additionalErrors'] ?? [];
        if (! is_array($additional) || $additional === []) {
            return false;
        }
        foreach ($additional as $msg) {
            $m = mb_strtolower((string) $msg);
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
            if (empty($card['variants']) || ! is_array($card['variants'])) {
                continue;
            }
            foreach ($card['variants'] as &$variant) {
                if (empty($variant['dimensions']) || ! is_array($variant['dimensions'])) {
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
            'data' => ['partial' => ! empty($failedItems)],
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
            ? ($cfg['prefix'].'-'.$cfg['nmID'].'-'.$cfg['package'])
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
                    'characteristics' => $this->extractCharacteristics($source),
                ],
            ],
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
     * Габариты из Sima-Land по sid; при пустом ответе, ошибке API или сети — дефолт (как без sku),
     * чтобы копирование карточки не падало, если sid не из каталога Sima (часто путают с nmID WB).
     */
    private function extractDimensions(?string $sku = null): array
    {
        if (empty($sku)) {
            return $this->defaultUploadDimensions();
        }

        try {
            $response = SimService::fetchProductData($sku);
            SimService::validateResponse($response);
            $item = $response['items'][0];
            $result = SimService::getProductDimensions($item, $item['product_volume'] ?? 0, (string) $sku);
            // WB rejects too-small brutto values with a generic weightBrutto format error.
            // Keep kilograms, but enforce a safe minimum for API validation.
            $weightBrutto = max(0.1, (float) ($result['weight_kg'] ?? 0.5));

            return [
                'length' => floor($result['length']) > 0 ? floor($result['length']) : 1,
                'width' => floor($result['width']) > 0 ? floor($result['width']) : 1,
                'height' => floor($result['depth']) > 0 ? floor($result['depth']) : 1,
                'weightBrutto' => round($weightBrutto, 3),
            ];
        } catch (\Throwable $e) {
            Log::warning('Sima-Land dimensions unavailable, using defaults for cards/upload', [
                'sid' => $sku,
                'error' => $e->getMessage(),
            ]);

            return $this->defaultUploadDimensions();
        }
    }

    /**
     * @return array{length: int, width: int, height: int, weightBrutto: float}
     */
    private function defaultUploadDimensions(): array
    {
        return [
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'weightBrutto' => 0.5,
        ];
    }

    /**
     * @param  array  $cfg  Конфиг карточки (цена в рублях, как в cardConfig)
     */
    private function extractSizes(array $cfg): array
    {
        return [['price' => (int) $cfg['price']]];
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
        $chars = $this->getCharacteristics((int) $source['data']['subject_id']);
        if (! empty($source['options'])) {
            foreach ($source['options'] as $characteristic) {
                foreach ($chars['data']['data'] as $char) {
                    if ($char['name'] === $characteristic['name']) {
                        $charName = mb_strtolower((string) ($char['name'] ?? ''));
                        if (
                            in_array((int) $char['charcID'], $blockedCharacteristicIds, true) ||
                            in_array($charName, $blockedNames, true)
                        ) {
                            continue;
                        }
                        if ($char['charcType'] === 4) {
                            $result[] = ['id' => $char['charcID'], 'value' => (int) $characteristic['value']];
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
    public function updateStocks(int $whID, array $chunk): bool
    {
        $result = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->put("https://marketplace-api.wildberries.ru/api/v3/stocks/{$whID}", [
                'stocks' => $chunk,
            ]);

        return $result->successful();
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
            ->post('https://discounts-prices-api.wildberries.ru/api/v2/upload/task', [
                'data' => $data,
            ]);
        echo 'Статус запроса '.$result->status()."\n";

        if ($result->status() !== 200) {
            $json = $result->json();
            $details = [];

            if (is_array($json)) {
                foreach (['message', 'error', 'errorText', 'detail'] as $field) {
                    if (! empty($json[$field])) {
                        $details[] = "{$field}: {$json[$field]}";
                    }
                }
            }

            if (empty($details)) {
                $rawBody = trim((string) $result->body());
                $details[] = $rawBody !== '' ? $rawBody : 'пустой ответ';
            }

            throw new \RuntimeException(
                'WB updatePrice вернул статус '.$result->status().'. Детали: '.implode('; ', $details)
            );
        }

        return true;
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
            ->post('https://content-api.wildberries.ru/content/v2/cards/update', $dimensionData);
        echo 'Статус запроса '.$result->status()."\n";

        return $result->status() == 200;
    }

    /**
     * Один запрос переноса в корзину (без повторов).
     *
     * @param  list<int|string>  $nmIds
     *
     * @throws ConnectionException
     */
    public function moveCardsToTrash(array $nmIds): bool
    {
        $response = $this->sendMoveCardsToTrashRequest($nmIds);
        if ($response->status() === 200) {
            return true;
        }
        if ($response->status() === 400) {
            $deleted = $this->parseNmIdsDeletedAlreadyFromTrash400($response);
            $normalized = [];
            foreach ($nmIds as $id) {
                $n = (int) $id;
                if ($n > 0) {
                    $normalized[$n] = $n;
                }
            }
            $pending = array_diff(array_values($normalized), $deleted);

            return $pending === [] && $deleted !== [];
        }

        return false;
    }

    /**
     * Перенос в корзину батчами с паузами по доке WB (интервал = период/лимит) и заголовкам X-Ratelimit-*.
     *
     * @param  list<int|string>  $nmIds
     * @return null|string null при успехе, иначе краткое описание последней ошибки
     */
    public function moveCardsToTrashBatchedWithRetry(
        array $nmIds,
        ?int $chunkSize = null,
        ?int $maxAttemptsPerChunk = null,
    ): ?string {
        $normalized = [];
        foreach ($nmIds as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $normalized[$n] = $n;
            }
        }
        $nmIds = array_values($normalized);
        if ($nmIds === []) {
            return null;
        }

        $chunkSize ??= (int) config('services.wildberries.trash_batch_chunk_size', 20);
        $maxAttemptsPerChunk ??= (int) config('services.wildberries.trash_max_attempts_per_chunk', 15);
        $chunkSize = max(1, min(100, $chunkSize));
        $maxAttemptsPerChunk = max(1, min(40, $maxAttemptsPerChunk));

        $chunks = array_chunk($nmIds, $chunkSize);
        $pauseMs = (int) config('services.wildberries.trash_inter_chunk_pause_ms', 2500);

        foreach ($chunks as $index => $chunk) {
            if ($index > 0 && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
            $err = $this->moveCardsToTrashChunkWithRetry($chunk, $maxAttemptsPerChunk);
            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }

    /**
     * HTTP 400 с additionalErrors: nmID уже удалён на стороне WB — не ошибка для жёсткого удаления.
     *
     * @return list<int>
     */
    private function parseNmIdsDeletedAlreadyFromTrash400(Response $response): array
    {
        if ($response->status() !== 400) {
            return [];
        }
        $decoded = json_decode($response->body(), true);
        if (! is_array($decoded)) {
            return [];
        }
        $errors = $decoded['additionalErrors'] ?? null;
        if (! is_array($errors)) {
            return [];
        }
        $out = [];
        foreach ($errors as $nmKey => $message) {
            if (! is_numeric((string) $nmKey)) {
                continue;
            }
            $nm = (int) $nmKey;
            if ($nm <= 0) {
                continue;
            }
            $text = '';
            if (is_string($message)) {
                $text = strtolower($message);
            } elseif (is_array($message)) {
                $text = strtolower(json_encode($message, JSON_UNESCAPED_UNICODE));
            }
            if ($text === '') {
                continue;
            }
            if (str_contains($text, 'deleted') || str_contains($text, 'удал')) {
                $out[$nm] = $nm;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<int>  $nmIds
     */
    private function moveCardsToTrashChunkWithRetry(array $nmIds, int $maxAttempts): ?string
    {
        $pending = array_values(array_unique(array_map('intval', array_filter($nmIds, static fn ($n) => (int) $n > 0))));
        if ($pending === []) {
            return null;
        }

        $lastDetail = 'Wildberries не подтвердил перенос в корзину';
        $maxIterations = max($maxAttempts * 4, 24);
        $iteration = 0;
        $rateLimitRetries = 0;
        $serverErrorRetries = 0;

        while ($pending !== [] && $iteration < $maxIterations) {
            $iteration++;
            try {
                $response = $this->sendMoveCardsToTrashRequest($pending);
                $status = $response->status();
                if ($status === 200) {
                    return null;
                }

                $body = $response->body();
                $snippet = strlen($body) > 200 ? substr($body, 0, 200).'…' : $body;
                $lastDetail = 'HTTP '.$status.' ('.count($pending).' nmID)'.($snippet !== '' ? ': '.$snippet : '');

                if ($status === 400) {
                    $alreadyDeleted = $this->parseNmIdsDeletedAlreadyFromTrash400($response);
                    if ($alreadyDeleted !== []) {
                        $toRemove = array_values(array_intersect($pending, $alreadyDeleted));
                        if ($toRemove === []) {
                            Log::warning('WB delete/trash: 400 additionalErrors не пересекаются с отправленными nmID — повтор бессмыслен', [
                                'pending_sample' => array_slice($pending, 0, 5),
                                'parsed_deleted_sample' => array_slice($alreadyDeleted, 0, 5),
                            ]);

                            return $lastDetail;
                        }
                        $before = count($pending);
                        $pending = array_values(array_diff($pending, $toRemove));
                        if (count($pending) >= $before) {
                            return $lastDetail;
                        }
                        Log::info('WB delete/trash: nmID уже удалены на WB (400 additionalErrors), повтор без них', [
                            'removed_nm_ids' => $toRemove,
                            'remaining_count' => count($pending),
                            'was_count' => $before,
                        ]);
                        if ($pending === []) {
                            return null;
                        }

                        continue;
                    }

                    return $lastDetail;
                }

                if ($status >= 400 && $status < 500 && $status !== 429) {
                    return $lastDetail;
                }

                if ($status === 429 || $status === 503) {
                    if ($rateLimitRetries >= $maxAttempts) {
                        return $lastDetail;
                    }
                    $rateLimitRetries++;
                    $this->waitTrash429Or503Cooldown($response, $status);
                    Log::warning('WB moveCardsToTrash: пауза после 429/503 по заголовкам лимита', [
                        'iteration' => $iteration,
                        'rate_limit_try' => $rateLimitRetries,
                        'status' => $status,
                        'nm_count' => count($pending),
                    ]);

                    continue;
                }

                if ($status >= 500) {
                    if ($serverErrorRetries >= min(5, $maxAttempts)) {
                        return $lastDetail;
                    }
                    $serverErrorRetries++;
                    $interval = $this->getTrashDocIntervalSeconds();
                    $sleepSec = min(10.0, max($interval * 2, 0.2));
                    usleep((int) round($sleepSec * 1e6));

                    continue;
                }

                return $lastDetail;
            } catch (ConnectionException $e) {
                $lastDetail = 'Сеть: '.$e->getMessage();
                $connMax = min(8, $maxAttempts);
                if ($rateLimitRetries + $serverErrorRetries >= $connMax) {
                    return $lastDetail;
                }
                $rateLimitRetries++;
                $this->scheduleTrashEarliestAfterConnectionOrUnknownFailure();
                Log::warning('WB moveCardsToTrash: повтор после ошибки соединения', [
                    'iteration' => $iteration,
                    'message' => $e->getMessage(),
                ]);
                usleep((int) round(max($this->getTrashDocIntervalSeconds(), 0.2) * 1e6));
            } catch (\Throwable $e) {
                $lastDetail = $e->getMessage();
                if ($serverErrorRetries >= min(3, $maxAttempts)) {
                    return $lastDetail;
                }
                $serverErrorRetries++;
                $this->scheduleTrashEarliestAfterConnectionOrUnknownFailure();
                usleep((int) round(max($this->getTrashDocIntervalSeconds(), 0.2) * 1e6));
            }
        }

        return $pending === [] ? null : $lastDetail;
    }

    /**
     * @param  list<int|string>  $nmIds
     */
    private function sendMoveCardsToTrashRequest(array $nmIds): Response
    {
        $this->waitTrashDocIntervalBeforeRequest();

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout(120)
                ->post('https://content-api.wildberries.ru/content/v2/cards/delete/trash', [
                    'nmIDs' => array_values(array_map('intval', $nmIds)),
                ]);
            $this->applyTrashRateLimiterAfterResponse($response);

            return $response;
        } catch (ConnectionException $e) {
            $this->scheduleTrashEarliestAfterConnectionOrUnknownFailure();

            throw $e;
        }
    }

    private function trashRateLimiterKey(): string
    {
        return sha1($this->apiKey);
    }

    /**
     * Интервал между запросами к delete/trash по доке WB: период / лимит (напр. 60/300 = 0,2 с).
     */
    private function getTrashDocIntervalSeconds(): float
    {
        $period = (int) config('services.wildberries.trash_ratelimit_period_seconds', 60);
        $requests = (int) config('services.wildberries.trash_ratelimit_requests_per_period', 300);

        return min(60.0, max(0.05, $period / max(1, $requests)));
    }

    private function waitTrashDocIntervalBeforeRequest(): void
    {
        $key = $this->trashRateLimiterKey();
        $earliest = self::$trashEarliestNextRequestAt[$key] ?? 0.0;
        $now = microtime(true);
        if ($now < $earliest) {
            usleep((int) round(($earliest - $now) * 1e6));
        }
    }

    /**
     * После любого ответа delete/trash: равномерный интервал; при X-Ratelimit-Remaining = 0 — ещё один интервал.
     */
    private function applyTrashRateLimiterAfterResponse(Response $response): void
    {
        $key = $this->trashRateLimiterKey();
        $interval = $this->getTrashDocIntervalSeconds();
        $next = microtime(true) + $interval;

        $remaining = $this->trashRateLimitIntHeader($response, 'X-Ratelimit-Remaining');
        if ($remaining !== null && $remaining <= 0) {
            $next += $interval;
        }

        self::$trashEarliestNextRequestAt[$key] = max(
            self::$trashEarliestNextRequestAt[$key] ?? 0.0,
            $next
        );
    }

    /**
     * 429/503: ждём X-Ratelimit-Retry (сек), иначе X-Ratelimit-Reset / Retry-After, иначе 2×интервал.
     */
    private function waitTrash429Or503Cooldown(Response $response, int $status): void
    {
        $key = $this->trashRateLimiterKey();
        $interval = $this->getTrashDocIntervalSeconds();

        $retrySec = null;
        if ($status === 429 || $status === 503) {
            $retrySec = $this->trashRateLimitFloatHeader($response, 'X-Ratelimit-Retry');
        }
        if ($retrySec === null || $retrySec <= 0) {
            $retrySec = $this->trashRateLimitFloatHeader($response, 'X-Ratelimit-Reset');
        }
        if ($retrySec === null || $retrySec <= 0) {
            $ra = $this->trashRateLimitStringHeader($response, 'Retry-After');
            if ($ra !== null && is_numeric($ra)) {
                $retrySec = (float) $ra;
            }
        }
        if ($retrySec === null || $retrySec <= 0) {
            $retrySec = max(2.0, $interval * 2);
        }
        $retrySec = min(120.0, max($interval, $retrySec));

        $until = microtime(true) + $retrySec;
        self::$trashEarliestNextRequestAt[$key] = max(
            self::$trashEarliestNextRequestAt[$key] ?? 0.0,
            $until
        );

        $waitSec = $until - microtime(true);
        if ($waitSec > 0) {
            usleep((int) round($waitSec * 1e6));
        }
    }

    private function scheduleTrashEarliestAfterConnectionOrUnknownFailure(): void
    {
        $key = $this->trashRateLimiterKey();
        $interval = $this->getTrashDocIntervalSeconds();
        self::$trashEarliestNextRequestAt[$key] = max(
            self::$trashEarliestNextRequestAt[$key] ?? 0.0,
            microtime(true) + $interval
        );
    }

    private function trashRateLimitStringHeader(Response $response, string $canonical): ?string
    {
        $v = $response->header($canonical);
        if ($v !== null && $v !== '') {
            return is_array($v) ? ($v[0] ?? null) : (string) $v;
        }
        foreach ($response->headers() as $name => $lines) {
            if (strcasecmp((string) $name, $canonical) === 0) {
                return isset($lines[0]) ? (string) $lines[0] : null;
            }
        }

        return null;
    }

    private function trashRateLimitIntHeader(Response $response, string $canonical): ?int
    {
        $s = $this->trashRateLimitStringHeader($response, $canonical);
        if ($s === null || ! is_numeric($s)) {
            return null;
        }

        return (int) $s;
    }

    private function trashRateLimitFloatHeader(Response $response, string $canonical): ?float
    {
        $s = $this->trashRateLimitStringHeader($response, $canonical);
        if ($s === null || ! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    /** @internal сброс статики лимитера между тестами */
    public static function resetTrashRateLimiterForTests(): void
    {
        self::$trashEarliestNextRequestAt = [];
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
            ->post('https://content-api.wildberries.ru/content/v2/cards/recover', [
                'nmIDs' => array_values($nmIds),
            ]);

        return $result->status() === 200;
    }
}
