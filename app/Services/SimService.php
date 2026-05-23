<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class SimService
{
    /** Число попыток GET к api/v3/item (включая первую). */
    private const SIMA_ITEM_MAX_ATTEMPTS = 15;

    /** Пауза перед повтором после неудачной попытки (индекс 0 = после 1-й неудачи). */
    private const SIMA_RETRY_BACKOFF_MS = [
        1000, 2000, 3500, 5000, 8000, 12000, 16000, 20000, 24000, 28000, 30000, 30000, 30000, 30000,
    ];

    private const SIMA_HTTP_TIMEOUT_SEC = 150;

    private const SIMA_HTTP_CONNECT_TIMEOUT_SEC = 60;

    public static function fetchProductData($sid)
    {
        return self::simItemGetWithRetry(['sid' => (string) $sid]);
    }

    /**
     * Варианты sid для Sima-Land: исходный код, дубль без слэша (123456123456 → 123456),
     * сегменты через «/» (123456/123456).
     *
     * @return list<string>
     */
    public static function normalizeSidCandidates(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $candidates = [];
        $seen = [];

        $add = static function (string $candidate) use (&$candidates, &$seen): void {
            $candidate = trim($candidate);
            if ($candidate === '' || isset($seen[$candidate])) {
                return;
            }
            $seen[$candidate] = true;
            $candidates[] = $candidate;
        };

        if (preg_match('/^(\d+)/', $raw, $matches)) {
            $add($matches[1]);
        }

        $digits = preg_replace('/\D/', '', $raw);
        if ($digits !== '' && strlen($digits) % 2 === 0) {
            $halfLen = (int) (strlen($digits) / 2);
            $left = substr($digits, 0, $halfLen);
            $right = substr($digits, $halfLen);
            if ($left === $right) {
                $add($left);
            }
        }

        if (str_contains($raw, '/')) {
            foreach (explode('/', $raw) as $segment) {
                $segment = trim($segment);
                if ($segment === '') {
                    continue;
                }
                if (preg_match('/^(\d+)/', $segment, $segmentMatches)) {
                    $add($segmentMatches[1]);
                } elseif (ctype_digit($segment)) {
                    $add($segment);
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public static function fetchProductDataResolvingSid(string $raw): array
    {
        $candidates = self::normalizeSidCandidates($raw);
        if ($candidates === []) {
            throw new InvalidArgumentException('Пустой sid');
        }

        $lastException = null;
        $lastIndex = count($candidates) - 1;

        foreach ($candidates as $index => $sid) {
            try {
                return self::fetchProductData($sid);
            } catch (RequestException $e) {
                $lastException = $e;
                if ($index < $lastIndex && self::isSidOutOfRangeError($e)) {
                    continue;
                }
                throw $e;
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        throw new InvalidArgumentException('Пустой sid');
    }

    public static function isSidOutOfRangeError(Throwable $e): bool
    {
        if ($e instanceof RequestException) {
            $response = $e->response;
            if ($response !== null && $response->status() === 422) {
                $message = (string) ($response->json('message') ?? '');

                return str_contains(strtolower($message), 'sid is out of range');
            }
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, '422')
            && str_contains($message, 'sid is out of range');
    }

    /**
     * Bulk-запрос остатков по списку origSku.
     *
     * @param  array<int|string>  $origSkus
     * @return array<string, int> map origSku → amount (0 или 5, см. calculateStockQuantity)
     */
    public static function getAmountsBulk(array $origSkus, int $chunkSize = 50): array
    {
        $skus = array_values(array_unique(array_filter(
            array_map(static fn ($s) => trim((string) $s), $origSkus),
            static fn (string $s): bool => $s !== '',
        )));

        if ($skus === []) {
            return [];
        }

        $result = [];
        foreach (array_chunk($skus, max(1, $chunkSize)) as $chunk) {
            $sid = implode(',', $chunk);
            $response = self::simItemGetWithRetry([
                'sid' => $sid,
                'expand' => 'stocks',
            ]);

            foreach (($response['items'] ?? []) as $item) {
                if (! isset($item['sid'])) {
                    continue;
                }
                $result[(string) $item['sid']] = self::calculateStockQuantity($item);
            }
        }

        return $result;
    }

    /**
     * GET https://www.sima-land.ru/api/v3/item/ с повторами при таймаутах и 5xx/429.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    private static function simItemGetWithRetry(array $query): array
    {
        $max = self::SIMA_ITEM_MAX_ATTEMPTS;
        $lastThrowable = null;

        for ($attempt = 0; $attempt < $max; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->timeout(self::SIMA_HTTP_TIMEOUT_SEC)
                    ->connectTimeout(self::SIMA_HTTP_CONNECT_TIMEOUT_SEC)
                    ->get('https://www.sima-land.ru/api/v3/item/', $query);

                if ($response->successful()) {
                    return $response->json() ?? [];
                }

                if ($attempt < $max - 1 && self::shouldRetryHttpResponse($response)) {
                    self::simaBackoffSleep($attempt);

                    continue;
                }

                $response->throw();
            } catch (ConnectionException $e) {
                $lastThrowable = $e;
                if ($attempt < $max - 1) {
                    self::simaBackoffSleep($attempt);

                    continue;
                }
                throw $e;
            } catch (RequestException $e) {
                $lastThrowable = $e;
                if ($attempt < $max - 1 && self::isRetriableRequestException($e)) {
                    self::simaBackoffSleep($attempt);

                    continue;
                }
                throw $e;
            } catch (Throwable $e) {
                $lastThrowable = $e;
                if ($attempt < $max - 1 && self::isRetriableTransportMessage($e)) {
                    self::simaBackoffSleep($attempt);

                    continue;
                }
                throw $e;
            }
        }

        if ($lastThrowable !== null) {
            throw $lastThrowable;
        }

        throw new ConnectionException('Sima-Land: исчерпаны попытки запроса к api/v3/item.');
    }

    private static function shouldRetryHttpResponse(\Illuminate\Http\Client\Response $response): bool
    {
        if ($response->serverError()) {
            return true;
        }

        return $response->status() === 429;
    }

    private static function isRetriableRequestException(RequestException $e): bool
    {
        $response = $e->response;
        if ($response !== null) {
            if ($response->serverError()) {
                return true;
            }
            if ($response->status() === 429) {
                return true;
            }
        }

        return self::isRetriableTransportMessage($e);
    }

    private static function isRetriableTransportMessage(Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if (self::messageSuggestsTransportRetry($cur->getMessage())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Таймауты соединения, DNS, reset и типичные cURL transport-коды — повторяем запрос.
     */
    private static function messageSuggestsTransportRetry(string $message): bool
    {
        $m = strtolower($message);

        if (str_contains($m, 'timeout') || str_contains($m, 'timed out') || str_contains($m, 'was reached')) {
            return true;
        }
        if (str_contains($m, 'failed to connect') || str_contains($m, 'could not resolve host')) {
            return true;
        }
        if (str_contains($m, 'port 443') || str_contains($m, 'libcurl') || str_contains($m, 'curl.haxx.se')) {
            return true;
        }
        if (str_contains($m, 'connection refused') || str_contains($m, 'connection reset')) {
            return true;
        }
        if (str_contains($m, 'ssl') && (str_contains($m, 'error') || str_contains($m, 'handshake'))) {
            return true;
        }

        return (bool) preg_match('/curl error\\s*(6|7|16|18|28|35|52|56)\\b/i', $m);
    }

    private static function simaBackoffSleep(int $attemptAfterFailure): void
    {
        $ms = self::SIMA_RETRY_BACKOFF_MS[$attemptAfterFailure] ?? 2000;
        usleep($ms * 1000);
    }

    /**
     * @throws Exception
     */
    public static function validateResponse(array $response): bool
    {
        if (! isset($response['items'][0])) {
            if (! isset($response['_meta'])) {
                throw new Exception('Invalid API response format');
            } else {
                throw new Exception('Product not found');
            }
        }
        $item = $response['items'][0];
        if (! self::calculateStockQuantity($item)) {
            throw new Exception('Amount is null');
        }
        if (self::checkMinQuantity($item)) {
            throw new Exception('Min quantity > 1');
        }

        return true;
    }

    public static function checkMinQuantity(array $item): bool
    {
        return
            (isset($item['minimum_order_quantity']) && $item['minimum_order_quantity'] > 1) ||
            (isset($item['cart_min_diff']) && $item['cart_min_diff'] > 1) ||
            (isset($item['min_qty']) && $item['min_qty'] > 1);
    }

    public static function calculateStockQuantity(array $item): int
    {
        if (isset($item['balance']) && $item['balance']) {
            return 5;
        }

        return isset($item['isEnough']) && $item['isEnough'] ? 5 : 0;
    }

    /**
     * @throws Exception
     */
    public static function getProductDimensions(array $item, float $productVolume, ?string $sku = null): array
    {
        $hasMissingDimension =
            ! isset($item['depth']) || $item['depth'] === 0 ||
            ! isset($item['width']) || $item['width'] === 0 ||
            ! isset($item['height']) || $item['height'] === 0;
        if ($hasMissingDimension && $productVolume > 0) {
            $calculatedDimensions = self::calculateDimensionsCm($productVolume);
            $dimensions = [
                'depth' => $calculatedDimensions['height'],
                'length' => $calculatedDimensions['length'],
                'width' => $calculatedDimensions['width'],
                'weight_kg' => (isset($item['weight']) && $item['weight'] !== 0)
                    ? round($item['weight'] / 1000, 2)
                    : 1,
            ];
            self::assertDimensionsWithinLimit($dimensions, $sku);

            return $dimensions;
        }
        $dimensions = [
            'depth' => (isset($item['depth']) && $item['depth'] !== 0) ? $item['depth'] : 10,
            'length' => (isset($item['width']) && $item['width'] !== 0) ? $item['width'] : 10,
            'width' => (isset($item['height']) && $item['height'] !== 0) ? $item['height'] : 10,
            'weight_kg' => (isset($item['weight']) && $item['weight'] !== 0)
                ? round($item['weight'] / 1000, 2)
                : 1,
        ];
        self::assertDimensionsWithinLimit($dimensions, $sku);

        return $dimensions;
    }

    /**
     * @throws Exception
     */
    private static function assertDimensionsWithinLimit(array $dimensions, ?string $sku = null): void
    {
        $maxDimension = max($dimensions['depth'], $dimensions['length'], $dimensions['width']);
        if ($maxDimension > 100) {
            $skuLabel = $sku ? " для SKU {$sku}" : '';
            throw new Exception(
                "Товар{$skuLabel} помещен в карантин: одна из сторон больше 100 см ".
                "(depth={$dimensions['depth']}, length={$dimensions['length']}, width={$dimensions['width']})"
            );
        }
    }

    public static function calculateDimensionsCm(float $volume): array
    {
        $volumeCm3 = $volume * 1000;
        if ($volumeCm3 <= 0) {
            throw new InvalidArgumentException('Объем должен быть положительным числом');
        }
        $sideLength = pow($volumeCm3, 1 / 3);

        return [
            'length' => round($sideLength, 2),    // длина в см
            'width' => round($sideLength, 2),    // ширина в см
            'height' => round($sideLength, 2),     // высота в см
        ];
    }
}
