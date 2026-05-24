<?php

namespace App\DTO;

class SimaSupplierAuditCardResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly string $logLine,
        /** @var array<string, int> */
        public readonly array $counterDeltas = [],
        public readonly bool $markMappingProcessed = true,
    ) {}
}
