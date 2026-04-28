<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Exception;

class CrawlSitemapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $url;
    protected Http $client;
    public $tries = 3;
    protected $timeout = 10;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    /**
     * Выполнить джобу.
     */
    public function handle(): void
    {
        try {
            $start = microtime(true);
            $response = Http::withHeaders([
                "Content-Type" => "application/json",
                'User-Agent' => 'LaravelSitemapCrawler/1.0'
            ])
                ->timeout(3600)
                ->connectTimeout(3600)
                ->accept('xml')
                ->get($this->url);
            $status = $response->getStatusCode();
            $time = round((microtime(true) - $start) * 1000); // мс
            echo ("URL: {$this->url} | Статус: {$status} | Время: {$time} мс") . "\r\n";
        } catch (Exception $e) {
            echo ("Ошибка при проверке URL {$this->url}: " . $e->getMessage()) . "\r\n";
        }
    }
}
