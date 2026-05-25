<?php

namespace App\Services;

use App\Libs\Helper;
use App\Models\Cards;
use App\Models\SkuMapping;
use Illuminate\Database\Eloquent\Builder;

/**
 * Удаление карточек Sima-Land и skuMapping без привязки к WB (wbSku).
 */
class SimaUnmappedSkuCleanup
{
    public const SUPPLIER_SIMA = 20;

    /**
     * Ключи для поиска skuMapping / карточек (origSku Sima, сегмент из LC-S-…, варианты sid).
     *
     * @return list<string>
     */
    public function resolveOrigSkuKeysFromCard(Cards $card): array
    {
        $keys = [];
        foreach ([
            trim((string) ($card->vendorCode ?? '')),
            trim((string) ($card->supplierVendorCode ?? '')),
            Helper::getVendorCode((string) ($card->supplierVendorCode ?? '')),
            Helper::getVendorCode((string) ($card->vendorCode ?? '')),
        ] as $candidate) {
            $this->addKey($keys, $candidate);
            foreach (SimService::normalizeSidCandidates($candidate) as $sid) {
                $this->addKey($keys, $sid);
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function resolveOrigSkuKeysFromSid(string $rawSid): array
    {
        $keys = [];
        $rawSid = trim($rawSid);
        $this->addKey($keys, $rawSid);
        $this->addKey($keys, Helper::getVendorCode($rawSid));
        foreach (SimService::normalizeSidCandidates($rawSid) as $sid) {
            $this->addKey($keys, $sid);
        }

        return $keys;
    }

    public function findMappingForCard(Cards $card): ?SkuMapping
    {
        $keys = $this->resolveOrigSkuKeysFromCard($card);
        if ($keys === []) {
            return null;
        }

        return SkuMapping::query()
            ->where(function (Builder $q) use ($keys) {
                $q->whereIn('origSku', $keys)->orWhereIn('wbSku', $keys);
            })
            ->first();
    }

    public function findMappingBySid(string $rawSid): ?SkuMapping
    {
        $keys = $this->resolveOrigSkuKeysFromSid($rawSid);
        if ($keys === []) {
            return null;
        }

        return SkuMapping::query()
            ->where(function (Builder $q) use ($keys) {
                $q->whereIn('origSku', $keys)->orWhereIn('wbSku', $keys);
            })
            ->first();
    }

    public function mappingHasWbSku(?SkuMapping $mapping): bool
    {
        if ($mapping === null) {
            return false;
        }

        return trim((string) $mapping->wbSku) !== '';
    }

    /**
     * Удаляет текущую карточку аудита, все Sima-карточки по ключам и строки skuMapping.
     *
     * @return array{cards_deleted: int, mapping_deleted: int, keys: list<string>}
     */
    public function purgeForCard(Cards $card): array
    {
        $keys = $this->resolveOrigSkuKeysFromCard($card);

        return $this->purgeByKeys($keys, (int) $card->id);
    }

    /**
     * @return array{cards_deleted: int, mapping_deleted: int, keys: list<string>}
     */
    public function purgeByOrigSku(string $origSku): array
    {
        $keys = $this->resolveOrigSkuKeysFromSid($origSku);

        return $this->purgeByKeys($keys, null);
    }

    /**
     * @param  list<string>  $keys
     * @return array{cards_deleted: int, mapping_deleted: int, keys: list<string>}
     */
    private function purgeByKeys(array $keys, ?int $forceCardId): array
    {
        if ($keys === [] && $forceCardId === null) {
            return ['cards_deleted' => 0, 'mapping_deleted' => 0, 'keys' => []];
        }

        $cardsQuery = Cards::query()->where('supplier', self::SUPPLIER_SIMA);
        $cardsQuery->where(function (Builder $q) use ($keys, $forceCardId) {
            $hasClause = false;
            if ($forceCardId !== null && $forceCardId > 0) {
                $q->where('id', $forceCardId);
                $hasClause = true;
            }
            if ($keys !== []) {
                $method = $hasClause ? 'orWhere' : 'where';
                $q->{$method}(function (Builder $inner) use ($keys) {
                    $inner->whereIn('vendorCode', $keys)
                        ->orWhereIn('supplierVendorCode', $keys);
                });
            }
        });
        $cardsDeleted = (int) $cardsQuery->delete();

        $mappingDeleted = $keys === []
            ? 0
            : (int) SkuMapping::query()
                ->where(function (Builder $q) use ($keys) {
                    $q->whereIn('origSku', $keys)->orWhereIn('wbSku', $keys);
                })
                ->delete();

        return [
            'cards_deleted' => (int) $cardsDeleted,
            'mapping_deleted' => (int) ($mappingDeleted ?? 0),
            'keys' => $keys,
        ];
    }

    /**
     * @param  list<string>  $keys
     */
    private function addKey(array &$keys, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        if (! in_array($value, $keys, true)) {
            $keys[] = $value;
        }
    }
}
