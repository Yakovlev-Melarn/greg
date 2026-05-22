<?php

namespace App\Console\Commands;

use App\Jobs\WbJob;
use App\Models\Sellers;
use Illuminate\Console\Command;

class BackfillCardsWbCreatedAt extends Command
{
    protected $signature = 'cards:backfill-wb-created-at
                            {--seller= : ID продавца (если не указан — все продавцы)}';

    protected $description = 'Полный обход каталога WB (catalog_backfill) для заполнения cards.wb_created_at из поля createdAt ответа API';

    public function handle(): int
    {
        $sellerOpt = $this->option('seller');
        $query = Sellers::query()->orderBy('id');
        if ($sellerOpt !== null && $sellerOpt !== '') {
            $query->where('id', (int) $sellerOpt);
        }

        $sellers = $query->get();
        if ($sellers->isEmpty()) {
            $this->warn('Продавцы не найдены.');

            return self::FAILURE;
        }

        foreach ($sellers as $seller) {
            WbJob::dispatch('getCardList', [
                'seller_id' => $seller->id,
                'catalog_backfill' => true,
                'wb_nm_ids_seen' => [],
                'manual_wb_created_at_backfill' => str_replace(' ', '', (string) microtime()),
                'settings' => [
                    'settings' => [
                        'sort' => ['ascending' => true],
                        'cursor' => ['limit' => 100],
                        'filter' => ['withPhoto' => -1],
                    ],
                ],
            ])->onQueue('updateCardsProcess');

            $this->info("В очередь updateCardsProcess: обход каталога для продавца #{$seller->id} ({$seller->name}).");
        }

        $this->comment('Дальнейшие синхронизации подставляют wb_created_at только если поле ещё пустое (см. WbJob::updateCard).');

        return self::SUCCESS;
    }
}
