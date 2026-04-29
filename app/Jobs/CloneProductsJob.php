<?php

namespace App\Jobs;

use App\Libs\WBContent;
use App\Models\Cards;
use App\Models\Category;
use App\Models\ProductQueue;
use App\Models\Sellers;
use App\Models\SkuMapping;
use App\Models\Supplier;
use App\Services\WildberriesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as CollectionAlias;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class CloneProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;
    protected $jobId;
    protected $supplier;
    protected int $increment;
    protected string $logFile;

    public function __construct($data, $jobId)
    {
        $this->data = $data;
        $this->jobId = $jobId;
        $this->logFile = "clone_logs/{$this->jobId}.log";
        $this->increment = 0;
    }

    /**
     * @throws ConnectionException
     */
    public function handle(): void
    {
        Storage::disk('local')->append($this->logFile, "✅ Получаю WB Supplier ID\n");
        $wbSupplierID = $this->getWbSupplierID();
        Storage::disk('local')->append(
            $this->logFile,
            "✅ WB Supplier ID получен для поставщика {$this->supplier->name} - {$wbSupplierID}\n"
        );
        Storage::disk('local')->append($this->logFile, "✅ Собираю категории\n");
        if (empty(!$categories = $this->fetchCategoriesWithRetry($wbSupplierID, 5, 10))) {
            $this->saveCategories($categories);
        }
        if ($categories = Category::where('checked', 0)->get()) {
            foreach ($categories as $category) {
                $this->getProducts($wbSupplierID, $category);
                $productsToQueue = ProductQueue::where("blocked", 0)->get();
                if ($productsToQueue->count()) {
                    Storage::disk('local')->append($this->logFile, "✅ Начало клонирования товаров лимит {$this->data['quantity']} шт.\n");
                    $this->processQueue($productsToQueue);
                    Storage::disk('local')->append($this->logFile, "✅ Клонирование завершено успешно\n");
                }
                $category->checked = 1;
                $category->save();
                if ($this->increment >= $this->data['quantity']) {
                    break;
                }
            }
            Storage::disk('local')->append($this->logFile, "🏁 Джоба завершена: " . now() . "\n");
        }
    }

    public function failed($exception)
    {
        $logFile = "clone_logs/{$this->jobId}.log";
        Storage::disk('local')->append($logFile, "❌ Джоба завершена с ошибкой: " . $exception->getMessage() . "\n");
    }

    private function getWbSupplierID(): ?string
    {
        $this->supplier = Supplier::find($this->data['supplier_id']);
        $urlChunk = explode('/', $this->supplier->link);
        return array_pop($urlChunk);
    }

    private function fetchCategoriesWithRetry(int $wbSupplierID, int $maxAttempts, int $delayBetweenAttempts): ?array
    {
        Storage::disk('local')->append($this->logFile, "✅ Начинаю сбор категорий для поставщика ID: {$wbSupplierID}\n");
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            Storage::disk('local')->append($this->logFile, "🔎 Попытка {$attempt} из {$maxAttempts}\n");
            if (Category::where('checked', 0)->count()) {
                Storage::disk('local')->append($this->logFile, "⚠️  Категории уже клонированы\n");
                return null;
            }
            $categories = WBContent::getCategoriesBySupplier($wbSupplierID);
            if (is_array($categories) && !empty($categories)) {
                Storage::disk('local')->append(
                    $this->logFile,
                    "✅ Успешно получено " . count($categories['data']['filters'][0]['items']) . " категорий на попытке {$attempt}\n"
                );
                return $categories['data']['filters'][0]['items'];
            }
            if ($categories === null) {
                Storage::disk('local')->append($this->logFile, "⚠️  Получен пустой ответ (null) на попытке {$attempt}\n");
            } else {
                Storage::disk('local')->append($this->logFile, "⚠️  Получены данные, но массив пуст на попытке {$attempt}\n");
            }
            if ($attempt < $maxAttempts) {
                Storage::disk('local')->append(
                    $this->logFile,
                    "⏱️  Ожидание {$delayBetweenAttempts} секунд перед следующей попыткой...\n"
                );
                sleep($delayBetweenAttempts);
            }
        }
        Storage::disk('local')->append(
            $this->logFile,
            "❌  Все попытки завершены. Не удалось получить категории для поставщика ID: {$wbSupplierID}\n"
        );
        return null;
    }

    private function saveCategories(array $categoriesData): array
    {
        $stats = [
            'processed' => 0,
            'created' => 0,
            'errors' => []
        ];
        foreach ($categoriesData as $categoryData) {
            $stats['processed']++;
            try {
                $existingCategory = Category::where('category_id', $categoryData['id'])->first();
                if (!$existingCategory) {
                    Category::create([
                        'category_id' => $categoryData['id'],
                        'name' => $categoryData['name'],
                        'parent_id' => $categoryData['parentId'] ?? null,
                        'parent_name' => $categoryData['parentName'] ?? null
                    ]);
                    $stats['created']++;
                }
            } catch (\Exception $e) {
                $stats['errors'][] = "Error processing category ID {$categoryData['id']}: " . $e->getMessage();
            }
        }
        return $stats;
    }

    private function getProducts($wbSupplierID, $category): void
    {
        $products = $this->fetchCategoryProductsWithRetry($wbSupplierID, $category->category_id);
        if ($products !== null) {
            $this->processCategoryProducts($products, $wbSupplierID, $category->category_id);
        }
    }

    private function processProductsPage(array $products): void
    {
        foreach ($products as $productData) {
            $this->addProductQueue($productData);
        }
        Storage::disk('local')->append(
            $this->logFile,
            "📦 Обработано " . count($products) . " товаров с текущей страницы\n"
        );
    }

    /**
     * Добавляет товар в очередь на обработку, если он отсутствует в таблице Cards
     *
     * @param mixed $productData Данные товара, должны содержать ключ 'id'
     * @return bool Успешность операции
     */
    private function addProductQueue(mixed $productData): bool
    {
        try {
            if (!isset($productData['id'])) {
                $this->logError('Недостаточно данных для добавления в очередь: отсутствует id товара');
                return false;
            }
            $sku = $productData['id'];
            $price = 1;
            if (isset($productData['sizes'][0]['price']['product'])) {
                $price = $productData['sizes'][0]['price']['product'] / 100;
            }
            $prefix = $this->data['prefix'] ?? null;
            $this->logInfo("Попытка добавления товара с SKU: {$sku} в очередь");
            $existsInCards = Cards::where('sku', $sku)->exists();
            $existsInSkuMapping = SkuMapping::where('wbSku', $sku)
                ->orWhere('origSku', $sku)
                ->exists();
            if ($this->data['in_stock_only'] && $productData['totalQuantity'] < 5) {
                $message = "Товар с nmID: {$sku} в количестве меньше минимального, пропуск добавления в очередь";
                $this->logWarning($message);
                return false;
            }
            if ($existsInCards) {
                $message = "Товар с nmID: {$sku} уже существует в таблице Cards, пропуск добавления в очередь";
                $this->logWarning($message);
                return false;
            }
            if ($existsInSkuMapping) {
                $message = "Товар с SKU: {$sku} уже существует в таблице skuMapping, пропуск добавления в очередь";
                $this->logWarning($message);
                return false;
            }
            $alreadyInQueue = ProductQueue::where('sku', $sku)->exists();
            if ($alreadyInQueue) {
                $message = "Товар с SKU: {$sku} уже находится в очереди обработки";
                $this->logWarning($message);
                return false;
            }
            $productQueue = ProductQueue::create([
                'sku' => $sku,
                'prefix' => $prefix,
                'price' => $price,
            ]);
            if ($productQueue) {
                $message = "Товар с SKU: {$sku} успешно добавлен в очередь обработки";
                $this->logSuccess($message);
                return true;
            } else {
                $this->logError("Не удалось добавить товар с SKU: {$sku} в очередь обработки");
                return false;
            }
        } catch (\Exception $e) {
            $this->logError("Ошибка при добавлении товара в очередь: " . $e->getMessage());
            return false;
        }
    }


    private function fetchCategoryProductsWithRetry($wbSupplierID, $categoryId)
    {
        $maxRetries = 3;
        $retryCount = 0;
        $products = null;
        do {
            try {
                $products = WBContent::getPagesProductsByCategory($wbSupplierID, $categoryId);
                if ($products !== null) {
                    $this->logSuccess("Успешно получен ответ для категории ID: {$categoryId}");
                    return $products;
                } else {
                    $this->handleRetry($categoryId, $retryCount, $maxRetries, "Получен null для категории ID {$categoryId}");
                }
            } catch (\Exception $e) {
                $this->handleRetry($categoryId, $retryCount, $maxRetries, "Ошибка запроса для категории ID {$categoryId}: {$e->getMessage()}");
            }
        } while ($retryCount < $maxRetries && $products === null);
        return null;
    }

    private function processCategoryProducts($products, $wbSupplierID, $categoryId): void
    {
        $totalProducts = $products['total'] ?? 0;
        $pages = ceil($totalProducts / 100);
        $this->logInfo("Категория ID: {$categoryId}. Всего товаров: {$totalProducts}, страниц: {$pages}");
        $this->processProductsPage($products['products'] ?? []);
        if ($pages > 1) {
            $this->fetchAndProcessRemainingPages($wbSupplierID, $categoryId, $pages);
        }
    }

    private function fetchAndProcessRemainingPages($wbSupplierID, $categoryId, $totalPages): void
    {
        for ($page = 2; $page <= $totalPages; $page++) {
            try {
                $nextPageProducts = WBContent::getPagesProductsByCategory(
                    $wbSupplierID,
                    $categoryId,
                    $page
                );

                if ($nextPageProducts !== null && isset($nextPageProducts['products'])) {
                    $this->logSuccess("Получены данные страницы {$page} для категории ID: {$categoryId}");
                    $this->processProductsPage($nextPageProducts['products']);
                } else {
                    $this->logWarning("Не удалось получить страницу {$page} для категории ID: {$categoryId}");
                }
            } catch (\Exception $e) {
                $this->logWarning("Ошибка при запросе страницы {$page} для категории ID: {$categoryId}: {$e->getMessage()}");
            }
        }
    }

    private function handleRetry($categoryId, &$retryCount, $maxRetries, $message): void
    {
        $retryCount++;
        if ($retryCount < $maxRetries) {
            $this->logWarning("{$message}. Попытка {$retryCount} из {$maxRetries}. Ожидание 30 сек…");
            sleep(30);
        } else {
            $this->logError("Все попытки завершены. {$message}");
        }
    }

    /**
     * @throws ConnectionException
     */
    private function processQueue(CollectionAlias $productsToQueue): void
    {
        $seller = Sellers::find($this->data['seller_id']);
        $batchSize = (int)($this->data['batch_size'] ?? 20);
        $batchSize = max(1, min($batchSize, 100));

        foreach ($productsToQueue->chunk($batchSize) as $productChunk) {
            if ($this->increment >= $this->data['quantity']) {
                break;
            }

            $batchItems = [];
            $service = new WildberriesService($seller->wb_api_key, [
                'prefix' => '',
                'nmID' => '0',
                'package' => 1,
                'price' => 0,
            ]);

            foreach ($productChunk as $product) {
                if ($this->increment >= $this->data['quantity']) {
                    break;
                }

                try {
                    $this->logInfo("Начинаем обработку продукта SKU: {$product->sku}");

                    if (Cards::where('sku', $product->sku)->exists()) {
                        $product->delete();
                        $this->logWarning("Товар ранее был создан: {$product->sku} ");
                        $this->increment++;
                        continue;
                    }

                    $info = WBContent::getCardInfo($product->sku);

                    $batchItems[] = [
                        'product' => $product,
                        'info' => $info,
                        'sourceData' => $info,
                        'sku' => $info['vendor_code'] ?? null,
                        'queueSku' => $product->sku,
                    ];
                    $this->increment++;
                } catch (\Exception $e) {
                    $product->blocked = 1;
                    $product->save();
                    $this->logError("Критическая ошибка при подготовке продукта {$product->sku}: " . $e->getMessage());
                    $this->increment++;
                }
            }

            if (empty($batchItems)) {
                continue;
            }

            try {
                $payload = array_map(static fn($item) => [
                    'sourceData' => $item['sourceData'],
                    'sku' => $item['sku'],
                    'queueSku' => $item['queueSku'],
                    'itemCardConfig' => [
                        'prefix' => $item['product']->prefix ?? '',
                        'nmID' => $item['product']->sku,
                        'package' => 1,
                        'price' => $item['product']->price,
                    ],
                ], $batchItems);

                $result = $service->addProductsFromSourceBatch($payload);
                $skippedByQueueSku = [];
                foreach ($result['skipped_items'] ?? [] as $skippedItem) {
                    $skippedByQueueSku[(string)$skippedItem['queueSku']] = $skippedItem;
                }
                $uploadedVendorCodeByQueueSku = [];
                foreach ($result['items'] ?? [] as $uploadedItem) {
                    if (empty($uploadedItem['queueSku'])) {
                        continue;
                    }
                    $uploadedVendorCodeByQueueSku[(string)$uploadedItem['queueSku']] = $uploadedItem['vendorCode'] ?? null;
                }

                foreach ($batchItems as $item) {
                    /** @var ProductQueue $product */
                    $product = $item['product'];
                    $queueSku = (string)$product->sku;
                    if (!isset($skippedByQueueSku[$queueSku])) {
                        continue;
                    }

                    $skipError = $skippedByQueueSku[$queueSku]['error'] ?? 'Unknown error';
                    if ($skipError === 'Amount is null') {
                        $product->blocked = 1;
                        $product->save();
                        $this->logWarning(
                            "Продукт {$product->sku} помещен в блокировку: {$skipError}. " .
                                "Товар не отправлен в WB"
                        );
                    } else {
                        $this->logWarning(
                            "Пропуск продукта {$product->sku}: {$skipError}. " .
                                "Товар не отправлен в WB и удален из очереди без блокировки"
                        );
                        $product->delete();
                    }
                }

                if ($result['success']) {
                    foreach ($batchItems as $item) {
                        /** @var ProductQueue $product */
                        $product = $item['product'];
                        $info = $item['info'];
                        if (isset($skippedByQueueSku[(string)$product->sku])) {
                            continue;
                        }

                        SkuMapping::updateOrCreate(
                            ['origSku' => $info['vendor_code']],
                            [
                                'origSku' => $info['vendor_code'],
                                'wbSku' => $product->sku,
                                'wbPrice' => $product->price
                            ]
                        );
                        WbJob::dispatch('getCardList', [
                            'seller_id' => $seller->id,
                            'sku' => $info['vendor_code'],
                            'nmID' => $product->sku,
                            'settings' => [
                                'settings' => [
                                    'filter' => [
                                        'textSearch' => $uploadedVendorCodeByQueueSku[(string)$product->sku] ?? $item['info']['vendor_code'],
                                        'withPhoto' => -1
                                    ]
                                ]
                            ]
                        ])->onQueue('updateCardsProcess')->delay(now()->addMinute());
                        SimJob::dispatch('calcPrice', ['sid' => $info['vendor_code']])->onQueue('updateCardsProcess');
                        $product->delete();
                        $this->logSuccess("Продукт {$product->sku} успешно добавлен и удален из очереди");
                    }
                } else {
                    foreach ($batchItems as $item) {
                        $product = $item['product'];
                        $this->logWarning("Ошибка batch upload для продукта {$product->sku}");
                    }
                    print_r($result);
                }
            } catch (\Exception $e) {
                foreach ($batchItems as $item) {
                    $product = $item['product'];
                    $product->blocked = 1;
                    $product->save();
                    $this->logError("Критическая ошибка при обработке продукта {$product->sku}: " . $e->getMessage());
                }
            }

            if ($this->increment >= $this->data['quantity']) {
                $this->logSuccess("Обработано {$this->increment} товаров из {$this->data['quantity']}. Конец очереди");
                break;
            }

            $this->logInfo("Пауза 1 секунда...");
            sleep(1);
        }
    }

    private function logSuccess($message): void
    {
        Storage::disk('local')->append($this->logFile, "✅ {$message}\n");
    }

    private function logWarning($message): void
    {
        Storage::disk('local')->append($this->logFile, "⚠️  {$message}\n");
    }

    private function logError($message): void
    {
        Storage::disk('local')->append($this->logFile, "❌  {$message}\n");
    }

    private function logInfo($message): void
    {
        Storage::disk('local')->append($this->logFile, "📝 {$message}\n");
    }
}
