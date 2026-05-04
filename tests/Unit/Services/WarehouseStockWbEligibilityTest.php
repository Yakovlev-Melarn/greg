<?php

namespace Tests\Unit\Services;

use App\Services\WarehouseStockWbEligibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class WarehouseStockWbEligibilityTest extends TestCase
{
    #[TestDox('Первый снимок всегда требует синхронизации с WB')]
    public function test_first_snapshot_syncs(): void
    {
        $this->assertTrue(WarehouseStockWbEligibility::shouldSyncToWb(null, true));
        $this->assertTrue(WarehouseStockWbEligibility::shouldSyncToWb(null, false));
    }

    #[TestDox('Два положительных подряд — не слать')]
    public function test_positive_positive_skips(): void
    {
        $this->assertFalse(WarehouseStockWbEligibility::shouldSyncToWb(true, true));
    }

    #[TestDox('Два нуля подряд — не слать')]
    public function test_zero_zero_skips(): void
    {
        $this->assertFalse(WarehouseStockWbEligibility::shouldSyncToWb(false, false));
    }

    #[DataProvider('transitionProvider')]
    #[TestDox('Переходы между нулём и положительным — слать')]
    public function test_transitions_send(?bool $prev, bool $next, bool $expected): void
    {
        $this->assertSame($expected, WarehouseStockWbEligibility::shouldSyncToWb($prev, $next));
    }

    /**
     * @return iterable<string, array{0: ?bool, 1: bool, 2: bool}>
     */
    public static function transitionProvider(): iterable
    {
        yield 'zero_to_positive' => [false, true, true];
        yield 'positive_to_zero' => [true, false, true];
    }
}
