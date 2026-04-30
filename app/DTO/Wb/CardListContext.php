<?php

namespace App\DTO\Wb;

final class CardListContext
{
    public function __construct(
        public readonly int $sellerId,
        public readonly int|string|null $sourceSku,
        public readonly int|string|null $queueWbSku,
        public readonly array $settings,
    ) {}
}
