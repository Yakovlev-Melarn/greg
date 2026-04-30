<?php

namespace App\DTO\Wb;

final class PhotoUploadPayload
{
    public function __construct(
        public readonly int $sellerId,
        public readonly int $nmId,
        public readonly int $supplierId,
    ) {}
}
