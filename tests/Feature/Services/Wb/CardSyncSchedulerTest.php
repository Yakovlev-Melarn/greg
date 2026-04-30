<?php

namespace Tests\Feature\Services\Wb;

use App\Jobs\WbJob;
use App\Services\Wb\CardSyncScheduler;
use PHPUnit\Framework\Attributes\TestDox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

class CardSyncSchedulerTest extends TestCase
{
    #[TestDox('Планируется follow-up getCardList с корректным payload и задержкой')]
    public function test_case_01(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 12, 0, 0));

        $scheduler = new CardSyncScheduler();
        $scheduler->dispatchFollowUpCardFetch(
            sellerId: 77,
            sourceSku: '4601766',
            queueWbSku: '12624007',
            supplierVendorCode: 'SM-L-4601766-1'
        );

        Queue::assertPushed(WbJob::class, function (WbJob $job): bool {
            $reflection = new ReflectionClass($job);

            $actionProperty = $reflection->getProperty('action');
            $actionProperty->setAccessible(true);
            $action = $actionProperty->getValue($job);

            $paramsProperty = $reflection->getProperty('params');
            $paramsProperty->setAccessible(true);
            $params = $paramsProperty->getValue($job);

            $expectedDelay = Carbon::now()->addMinute();

            return $action === 'getCardList'
                && $job->queue === 'updateCardsProcess'
                && (string) $job->delay === (string) $expectedDelay
                && ($params['seller_id'] ?? null) === 77
                && ($params['sourceSku'] ?? null) === '4601766'
                && ($params['queueWbSku'] ?? null) === '12624007'
                && ($params['settings']['settings']['filter']['textSearch'] ?? null) === 'SM-L-4601766-1'
                && ($params['settings']['settings']['cursor']['limit'] ?? null) === 1;
        });

        Carbon::setTestNow();
    }

    #[TestDox('Планируется follow-up getCardList для legacy сценария с минимальным payload')]
    public function test_case_02(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::create(2026, 4, 30, 13, 0, 0));

        $scheduler = new CardSyncScheduler();
        $scheduler->dispatchFollowUpCardFetch(
            sellerId: 99,
            sourceSku: null,
            queueWbSku: null,
            supplierVendorCode: 'SM-L-999999-1'
        );

        Queue::assertPushed(WbJob::class, function (WbJob $job): bool {
            $reflection = new ReflectionClass($job);

            $actionProperty = $reflection->getProperty('action');
            $actionProperty->setAccessible(true);
            $action = $actionProperty->getValue($job);

            $paramsProperty = $reflection->getProperty('params');
            $paramsProperty->setAccessible(true);
            $params = $paramsProperty->getValue($job);

            $expectedDelay = Carbon::now()->addMinute();

            return $action === 'getCardList'
                && $job->queue === 'updateCardsProcess'
                && (string) $job->delay === (string) $expectedDelay
                && ($params['seller_id'] ?? null) === 99
                && array_key_exists('sourceSku', $params)
                && array_key_exists('queueWbSku', $params)
                && $params['sourceSku'] === null
                && $params['queueWbSku'] === null
                && ($params['settings']['settings']['filter']['textSearch'] ?? null) === 'SM-L-999999-1'
                && ($params['settings']['settings']['cursor']['limit'] ?? null) === 1;
        });

        Carbon::setTestNow();
    }
}
