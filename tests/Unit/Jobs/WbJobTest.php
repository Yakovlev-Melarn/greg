<?php

namespace Tests\Unit\Jobs;

use App\DTO\Wb\CardListContext;
use App\DTO\Wb\PhotoUploadPayload;
use App\DTO\Wb\PriceUpdatePayload;
use App\Jobs\WbJob;
use App\Models\SkuMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class WbJobTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('Контекст getCardList собирается из новых полей')]
    public function test_case_01(): void
    {
        $job = new WbJob('getCardList', []);

        /** @var CardListContext|null $context */
        $context = $this->invokePrivate($job, 'buildCardListContext', [[
            'seller_id' => 11,
            'sourceSku' => '4601766',
            'queueWbSku' => '12624007',
            'settings' => ['settings' => ['cursor' => ['limit' => 1]]],
        ]]);

        $this->assertInstanceOf(CardListContext::class, $context);
        $this->assertSame(11, $context->sellerId);
        $this->assertSame('4601766', $context->sourceSku);
        $this->assertSame('12624007', $context->queueWbSku);
    }

    #[TestDox('Контекст getCardList собирается из legacy полей')]
    public function test_case_02(): void
    {
        $job = new WbJob('getCardList', []);

        /** @var CardListContext|null $context */
        $context = $this->invokePrivate($job, 'buildCardListContext', [[
            'seller_id' => 22,
            'sku' => 'legacy-source',
            'nmID' => 'legacy-queue',
            'settings' => [],
        ]]);

        $this->assertInstanceOf(CardListContext::class, $context);
        $this->assertSame('legacy-source', $context->sourceSku);
        $this->assertSame('legacy-queue', $context->queueWbSku);
    }

    #[TestDox('Payload для uploadPhotos валидируется корректно')]
    public function test_case_03(): void
    {
        $job = new WbJob('uploadPhotos', []);

        /** @var PhotoUploadPayload|null $valid */
        $valid = $this->invokePrivate($job, 'buildPhotoUploadPayload', [[
            'seller_id' => 1,
            'nmID' => 2,
            'supplierID' => 3,
        ]]);
        $this->assertInstanceOf(PhotoUploadPayload::class, $valid);

        /** @var PhotoUploadPayload|null $invalid */
        $invalid = $this->invokePrivate($job, 'buildPhotoUploadPayload', [[
            'seller_id' => 1,
            'nmID' => 0,
            'supplierID' => 3,
        ]]);
        $this->assertNull($invalid);
    }

    #[TestDox('Источник фото определяется по приоритетам поставщика')]
    public function test_case_04(): void
    {
        $job = new WbJob('getCardList', []);

        $simaUsesQueue = $this->invokePrivate(
            $job,
            'resolvePhotoSourceSupplierId',
            ['SM-L-4601766-1', '4601766', '12624007']
        );
        $this->assertSame(12624007, $simaUsesQueue);

        $simaFallbackToSource = $this->invokePrivate(
            $job,
            'resolvePhotoSourceSupplierId',
            ['SM-L-4601766-1', '4601766', null]
        );
        $this->assertSame(4601766, $simaFallbackToSource);

        $wbUsesVendorCodeSku = $this->invokePrivate(
            $job,
            'resolvePhotoSourceSupplierId',
            ['WB-X-998877-1', null, null]
        );
        $this->assertSame(998877, $wbUsesVendorCodeSku);
    }

    #[TestDox('Sima-Land подставляет queueWbSku из SkuMapping при пустом queueWbSku')]
    public function test_case_04b_mapping_queue_when_empty(): void
    {
        SkuMapping::forceCreate([
            'origSku' => '7778888',
            'wbSku' => '12624007',
        ]);

        $job = new WbJob('getCardList', []);

        $fromMapping = $this->invokePrivate(
            $job,
            'resolvePhotoSourceSupplierId',
            ['SM-L-7778888-1', '111', null]
        );
        $this->assertSame(12624007, $fromMapping);
    }

    #[TestDox('DTO цены формируется корректно по skuMapping')]
    public function test_case_05(): void
    {
        $job = new WbJob('updatePrice', []);
        $mapping = new SkuMapping([
            'total_cost' => 100,
            'wbPrice' => 120,
        ]);
        $mapping->id = 15;
        $mapping->setRelation('card', (object) [
            'sellerID' => 7,
            'nmID' => 7001,
        ]);

        /** @var PriceUpdatePayload $payload */
        $payload = $this->invokePrivate($job, 'buildPricePayloadForMapping', [$mapping]);

        $this->assertInstanceOf(PriceUpdatePayload::class, $payload);
        $this->assertSame(7, $payload->sellerId);
        $this->assertSame(7001, $payload->nmId);
        $this->assertSame(150, $payload->price);
        $this->assertSame(15, $payload->mappingId);
    }

    #[TestDox('При отсутствии карточки выбрасывается RuntimeException')]
    public function test_case_06(): void
    {
        $job = new WbJob('updatePrice', []);
        $mapping = new SkuMapping([
            'total_cost' => 100,
            'wbPrice' => 120,
        ]);
        $mapping->setRelation('card', null);

        $this->expectException(RuntimeException::class);
        $this->invokePrivate($job, 'buildPricePayloadForMapping', [$mapping]);
    }

    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $reflection = new ReflectionClass($object);
        $targetMethod = $reflection->getMethod($method);
        $targetMethod->setAccessible(true);

        return $targetMethod->invokeArgs($object, $args);
    }
}
