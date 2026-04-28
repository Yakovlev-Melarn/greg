<?php

namespace App\Jobs;

use App\Libs\Gpt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;

class GPTJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 10;
    public int $timeout = 3600;

    public function __construct(
        private readonly string $action,
        private readonly string $prompt,
        private readonly array $params = []
    )
    {
    }

    /**
     * @throws ConnectionException
     */
    public function handle(): void
    {
        Gpt::{$this->action}($this->prompt,$this->params);
    }
}
