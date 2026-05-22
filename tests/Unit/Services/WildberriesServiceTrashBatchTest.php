<?php

namespace Tests\Unit\Services;

use App\Services\WildberriesService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class WildberriesServiceTrashBatchTest extends TestCase
{
    #[TestDox('moveCardsToTrashBatchedWithRetry режет nmID батчами по 100')]
    public function test_batches_one_hundred_per_request(): void
    {
        Http::fake([
            'https://content-api.wildberries.ru/content/v2/cards/delete/trash' => Http::response([], 200),
        ]);

        $ids = range(1, 101);
        $service = new WildberriesService('test-key', []);
        $err = $service->moveCardsToTrashBatchedWithRetry($ids, 100, 2);

        $this->assertNull($err);
        Http::assertSentCount(2);
    }

    #[TestDox('moveCardsToTrashBatchedWithRetry повторяет запрос при временном отказе')]
    public function test_retries_until_success(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                return Http::response(['error' => true], 503);
            }

            return Http::response([], 200);
        });

        $service = new WildberriesService('test-key', []);
        $err = $service->moveCardsToTrashBatchedWithRetry([1_234_567], 100, 5);

        $this->assertNull($err);
        $this->assertSame(3, $calls);
    }

    #[TestDox('при 429 учитывается заголовок X-Ratelimit-Retry')]
    public function test_429_respects_x_ratelimit_retry(): void
    {
        Http::fake([
            'https://content-api.wildberries.ru/content/v2/cards/delete/trash' => Http::sequence()
                ->push(['title' => 'too many requests'], 429, ['X-Ratelimit-Retry' => '1'])
                ->push([], 200, ['X-Ratelimit-Remaining' => '10']),
        ]);

        $service = new WildberriesService('test-key', []);
        $err = $service->moveCardsToTrashBatchedWithRetry([9_999_001], 100, 5);

        $this->assertNull($err);
        Http::assertSentCount(2);
    }

    #[TestDox('HTTP 400 additionalErrors «nmID is deleted» — исключаем nmID и повторяем запрос')]
    public function test_400_additional_errors_deleted_nm_ids_retry_remaining(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            $data = $request->data();
            $nms = $data['nmIDs'] ?? [];

            if ($calls === 1) {
                $this->assertCount(3, $nms);
                $payload = [
                    'data' => null,
                    'error' => true,
                    'errorText' => 'bad request',
                    'additionalErrors' => [
                        '1017819737' => 'nmID is deleted',
                        '1017890054' => 'nmID is deleted',
                    ],
                ];

                return Http::response(json_encode($payload), 400);
            }

            $this->assertCount(1, $nms);
            $this->assertSame(10_186_063_44, (int) $nms[0]);

            return Http::response([], 200);
        });

        $service = new WildberriesService('test-key', []);
        $err = $service->moveCardsToTrashBatchedWithRetry([
            10_178_197_37,
            10_178_900_54,
            10_186_063_44,
        ], 20, 10);

        $this->assertNull($err);
        $this->assertSame(2, $calls);
    }

    #[TestDox('HTTP 400: additionalErrors без пересечения с батчем — один запрос, без повторов того же тела')]
    public function test_400_deleted_nm_ids_not_in_batch_no_retry_loop(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(json_encode([
                'data' => null,
                'error' => true,
                'additionalErrors' => [
                    '999999999' => 'nmID is deleted',
                ],
            ]), 400);
        });

        $service = new WildberriesService('test-key', []);
        $err = $service->moveCardsToTrashBatchedWithRetry([10_178_197_37, 10_178_900_54], 20, 10);

        $this->assertNotNull($err);
        $this->assertSame(1, $calls);
    }
}
