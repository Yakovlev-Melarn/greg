<?php

namespace Tests\Unit\Jobs;

use App\Jobs\CloneProductsJob;
use App\Models\SkuMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;
use Tests\TestCase;

class CloneProductsJobSkuMappingTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('ensureSkuMappingIfMissing не падает при занятом wbSku другой строкой')]
    public function test_ensure_mapping_skips_when_wb_sku_taken_by_other_orig(): void
    {
        SkuMapping::create([
            'origSku' => 'other-vendor',
            'wbSku' => '13184820',
            'wbPrice' => 100,
        ]);

        $job = new CloneProductsJob([], 'test-job');
        $result = $this->invokePrivate($job, 'ensureSkuMappingIfMissing', ['46065114606511', '13184820', 10151]);

        $this->assertFalse($result);
        $this->assertSame(1, SkuMapping::query()->where('wbSku', '13184820')->count());
        $this->assertFalse(SkuMapping::query()->where('origSku', '46065114606511')->exists());
    }

    /**
     * @param  array<mixed>  $args
     */
    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $reflection = new ReflectionClass($object);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($object, $args);
    }
}
