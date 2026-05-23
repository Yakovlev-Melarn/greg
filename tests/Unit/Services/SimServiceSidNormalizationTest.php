<?php

namespace Tests\Unit\Services;

use App\Services\SimService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SimServiceSidNormalizationTest extends TestCase
{
    #[TestDox('normalizeSidCandidates: дубль без слэша и формат со слэшем')]
    public function test_normalize_sid_candidates_for_duplicated_formats(): void
    {
        $this->assertSame(['123456123456', '123456'], SimService::normalizeSidCandidates('123456123456'));
        $this->assertSame(['123456'], SimService::normalizeSidCandidates('123456/123456'));
        $this->assertSame(['123456'], SimService::normalizeSidCandidates('123456'));
    }

    #[TestDox('fetchProductDataResolvingSid повторяет запрос с нормализованным sid при 422')]
    public function test_fetch_product_data_retries_with_normalized_sid_on_422(): void
    {
        Http::fake([
            'https://www.sima-land.ru/api/v3/item/*' => Http::sequence()
                ->push(['name' => 'Unprocessable entity', 'message' => 'sid is out of range', 'code' => 0, 'status' => 422], 422)
                ->push([
                    'items' => [
                        [
                            'sid' => 123456,
                            'price' => 100,
                            'balance' => 1,
                            'depth' => 10,
                            'width' => 10,
                            'height' => 10,
                            'weight' => 500,
                        ],
                    ],
                ], 200),
        ]);

        $response = SimService::fetchProductDataResolvingSid('123456123456');

        $this->assertSame(123456, $response['items'][0]['sid']);
        Http::assertSentCount(2);
    }

    #[TestDox('isSidOutOfRangeError распознаёт 422 от Sima-Land')]
    public function test_is_sid_out_of_range_error(): void
    {
        Http::fake([
            'https://www.sima-land.ru/api/v3/item/*' => Http::response([
                'message' => 'sid is out of range',
            ], 422),
        ]);

        try {
            SimService::fetchProductData('999999999999999');
            $this->fail('Expected RequestException');
        } catch (RequestException $e) {
            $this->assertTrue(SimService::isSidOutOfRangeError($e));
        }
    }
}
