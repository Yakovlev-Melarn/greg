<?php

namespace App\Console\Commands;

use App\Jobs\CrawlSitemapJob;
use Illuminate\Console\Command;

class CrawlSitemap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:crawl-sitemap {url}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        CrawlSitemapJob::dispatch($this->argument('url'));
        $this->info('Джоба запущена!');
    }
}
