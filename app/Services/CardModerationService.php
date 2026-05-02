<?php

namespace App\Services;

use App\Models\Cards;
use App\Models\ProductQueue;
use App\Models\SkuMapping;
use Illuminate\Support\Facades\DB;

class CardModerationService
{
    public function quarantineBySupplierVendorCodes(array $supplierVendorCodes): array
    {
        $results = [];
        $summary = [
            'total' => count($supplierVendorCodes),
            'processed' => 0,
            'not_found' => 0,
            'errors' => 0,
        ];

        foreach ($supplierVendorCodes as $supplierVendorCode) {
            $results[] = $this->processCode((string)$supplierVendorCode);
        }

        foreach ($results as $row) {
            if ($row['status'] === 'success') {
                $summary['processed']++;
            } elseif ($row['status'] === 'not_found') {
                $summary['not_found']++;
            } else {
                $summary['errors']++;
            }
        }

        return [
            'summary' => $summary,
            'items' => $results,
        ];
    }

    private function processCode(string $supplierVendorCode): array
    {
        $code = trim($supplierVendorCode);
        if ($code === '') {
            return [
                'supplierVendorCode' => $supplierVendorCode,
                'status' => 'error',
                'message' => 'Пустой supplierVendorCode',
            ];
        }

        try {
            return DB::transaction(function () use ($code) {
                $card = Cards::where('supplierVendorCode', $code)->first();
                if (!$card) {
                    return [
                        'supplierVendorCode' => $code,
                        'status' => 'not_found',
                        'message' => 'Карточка не найдена в таблице cards',
                    ];
                }

                $updatedMappings = SkuMapping::where('origSku', $card->vendorCode)->update(['blocked' => 1]);

                $queueSku = $this->resolveQueueSku($card);
                if ($queueSku === null) {
                    return [
                        'supplierVendorCode' => $code,
                        'status' => 'error',
                        'message' => 'Не удалось определить идентификатор для очереди (пустые sku и nmID)',
                    ];
                }

                $queue = ProductQueue::updateOrCreate(
                    ['sku' => $queueSku],
                    [
                        'prefix' => null,
                        'price' => null,
                        'blocked' => 1,
                    ]
                );

                return [
                    'supplierVendorCode' => $code,
                    'status' => 'success',
                    'message' => 'Карточка помещена в карантин',
                    'card' => [
                        'id' => $card->id,
                        'sku' => $card->sku,
                        'queueSku' => $queueSku,
                        'vendorCode' => $card->vendorCode,
                    ],
                    'skuMappingUpdated' => $updatedMappings,
                    'queueId' => $queue->id,
                ];
            });
        } catch (\Throwable $e) {
            return [
                'supplierVendorCode' => $code,
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Ключ строки в product_queues: приоритетно wb sku карточки, иначе nmID (как в CloneProductsJob для очереди).
     */
    private function resolveQueueSku(Cards $card): ?string
    {
        $fromSku = trim((string) ($card->sku ?? ''));
        if ($fromSku !== '') {
            return $fromSku;
        }

        if ($card->nmID !== null && (string) $card->nmID !== '') {
            return (string) $card->nmID;
        }

        $fromSvc = trim((string) ($card->supplierVendorCode ?? ''));
        if ($fromSvc !== '') {
            return $fromSvc;
        }

        return null;
    }
}
