<?php

namespace App\DTO\Wb;

final class PriceUpdatePayload
{
    public function __construct(
        public readonly int $sellerId,
        public readonly int $nmId,
        public readonly int $price,
        public readonly int $mappingId,
    ) {}
}
