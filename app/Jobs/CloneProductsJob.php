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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class CloneProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Воркер очереди по умолчанию обрывает джобу через ~60 с; здесь до 9×10 с пауз на категорию + HTTP — иначе «attempted too many times».
     */
    public int $timeout = 7200;

    protected $data;

    protected $jobId;

    protected $supplier;

    protected int $increment;

    protected string $logFile;

    /**
     * В режиме «только очередь»: SKU строк product_queues, созданных в этой джобе (финальный processQueue только по ним).
     *
     * @var array<string, true>
     */
    protected array $skusAddedThisQueueOnlyRun = [];

    /** Счётчик позиций для строк ORPHAN_PROGRESS в режиме проверки очереди. */
    protected int $orphanQueueProgressDone = 0;

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
        if (! empty($this->data['send_queue_to_wb'])) {
            $this->handleSendQueueToWb();

            return;
        }

        if (! empty($this->data['orphan_scan_only'])) {
            $this->handleOrphanScanOnly();

            return;
        }

        if (! empty($this->data['orphan_catalog_scan_only'])) {
            $this->handleOrphanCatalogScanOnly();

            return;
        }

        Storage::disk('local')->append($this->logFile, "✅ Получаю WB Supplier ID\n");
        $wbSupplierID = $this->getWbSupplierID();
        Storage::disk('local')->append(
            $this->logFile,
            "✅ WB Supplier ID получен для поставщика {$this->supplier->name} - {$wbSupplierID}\n"
        );
        Storage::disk('local')->append($this->logFile, "✅ Собираю категории\n");
        if (empty(! $categories = $this->fetchCategoriesWithRetry($wbSupplierID, 5, 10))) {
            $this->saveCategories($categories);
        }
        if ($categories = Category::where('checked', 0)->get()) {
            if ($this->isQueueOnly()) {
                Storage::disk('local')->append(
                    $this->logFile,
                    "✅ Режим только очереди: товары копятся в product_queues, загрузка в WB отключена\n"
                );
            }
            foreach ($categories as $category) {
                $categoryLoaded = $this->getProducts($wbSupplierID, $category);

                // Режим «только очередь»: не опрашиваем всю product_queues после каждой категории —
                // один проход в конце (см. ниже), иначе getCardInfo по всей очереди на каждой итерации.
                if (! $this->isQueueOnly()) {
                    $productsToQueue = ProductQueue::where('blocked', 0)->get();
                    if ($productsToQueue->count()) {
                        Storage::disk('local')->append($this->logFile, "✅ Начало клонирования товаров лимит {$this->data['quantity']} шт.\n");
                        $this->processQueue($productsToQueue, true);
                        Storage::disk('local')->append($this->logFile, "✅ Клонирование завершено успешно\n");
                    }
                }

                if ($categoryLoaded) {
                    $category->checked = 1;
                    $category->save();
                } else {
                    Storage::disk('local')->append(
                        $this->logFile,
                        "⚠️  Категория ID {$category->category_id} остаётся с checked=0 — товары не получены после всех попыток, повторите позже\n"
                    );
                }
                if ($this->increment >= $this->data['quantity']) {
                    break;
                }
            }

            if ($this->isQueueOnly()) {
                $newSkus = array_keys($this->skusAddedThisQueueOnlyRun);
                if ($newSkus === []) {
                    Storage::disk('local')->append(
                        $this->logFile,
                        "✅ Финальная проверка сирот/дубликатов пропущена: в этой джобе не было новых позиций в product_queues\n"
                    );
                } else {
                    $productsToQueue = ProductQueue::query()
                        ->where('blocked', 0)
                        ->whereIn('sku', $newSkus)
                        ->orderBy('id')
                        ->get();
                    if ($productsToQueue->isEmpty()) {
                        Storage::disk('local')->append(
                            $this->logFile,
                            '⚠️  Новые SKU в этой джобе ('.count($newSkus).') не найдены в очереди (возможно удалены) — финальный processQueue пропущен'."\n"
                        );
                    } else {
                        Storage::disk('local')->append(
                            $this->logFile,
                            '✅ Финальная обработка только позиций, добавленных в этой джобе ('.$productsToQueue->count().' из '.count($newSkus).' SKU): сироты и дубликаты без отправки в WB'."\n"
                        );
                        $this->processQueue($productsToQueue, false);
                    }
                }
            }

            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");
        }
    }

    public function failed($exception)
    {
        $logFile = "clone_logs/{$this->jobId}.log";
        Storage::disk('local')->append($logFile, '❌ Джоба завершена с ошибкой: '.$exception->getMessage()."\n");
    }

    private function isQueueOnly(): bool
    {
        return ! empty($this->data['queue_only']);
    }

    /**
     * Режим «только проверка сирот» из очереди: без категорий и без загрузки в WB; строки с blocked тоже обрабатываются.
     */
    private function isOrphanScanOnly(): bool
    {
        return ! empty($this->data['orphan_scan_only']);
    }

    /**
     * Обход категорий поставщика WB (как при клонировании), только поиск и восстановление сирот по каталогу.
     */
    private function isOrphanCatalogScanOnly(): bool
    {
        return ! empty($this->data['orphan_catalog_scan_only']);
    }

    /**
     * Не увеличивать increment как при полном клонировании (как в режиме только очереди).
     */
    private function skipIncrementLikeQueueOnly(): bool
    {
        return $this->isQueueOnly() || $this->isOrphanScanOnly();
    }

    /**
     * Лимит «сколько позиций каталога WB обработать» для queue_only и orphan_catalog_scan_only.
     */
    private function reachedCatalogProductLimit(): bool
    {
        $max = (int) ($this->data['quantity'] ?? 0);
        if ($max <= 0) {
            return false;
        }

        return ($this->isQueueOnly() || $this->isOrphanCatalogScanOnly()) && $this->increment >= $max;
    }

    /**
     * Обход категорий WB: для каждого товара из выдачи — getCardInfo; при совпадении vendorCode с сиротой магазина — восстановление.
     */
    private function handleOrphanCatalogScanOnly(): void
    {
        Storage::disk('local')->append(
            $this->logFile,
            "✅ Режим: проверка сирот по каталогу WB (категории поставщика), без очереди и без загрузки в WB\n"
        );

        $sellerId = $this->data['seller_id'] ?? null;
        if (empty($sellerId)) {
            Storage::disk('local')->append($this->logFile, "❌ Не указан магазин (seller_id)\n");
            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");

            return;
        }
        if (Sellers::find($sellerId) === null) {
            Storage::disk('local')->append($this->logFile, "❌ Магазин не найден\n");
            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");

            return;
        }

        if (empty($this->data['supplier_id'])) {
            Storage::disk('local')->append($this->logFile, "❌ Не указан поставщик (supplier_id) для категорий WB\n");
            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");

            return;
        }

        if (Supplier::find($this->data['supplier_id']) === null) {
            Storage::disk('local')->append($this->logFile, "❌ Поставщик не найден\n");
            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");

            return;
        }

        $maxProducts = (int) ($this->data['quantity'] ?? 50_000);
        $this->data['quantity'] = max(1, min($maxProducts, 500_000));

        $wbSupplierID = $this->getWbSupplierID();
        if ($wbSupplierID === null || $wbSupplierID === '') {
            Storage::disk('local')->append($this->logFile, "❌ Не удалось определить WB Supplier ID по ссылке поставщика\n");
            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");

            return;
        }

        Storage::disk('local')->append(
            $this->logFile,
            "✅ WB Supplier ID: {$wbSupplierID}, поставщик {$this->supplier->name}, лимит просмотра товаров: {$this->data['quantity']}\n"
        );

        $this->increment = 0;

        $retryUncheckedOnly = ! empty($this->data['orphan_catalog_retry_unchecked_only']);

        if (Category::count() === 0) {
            Storage::disk('local')->append($this->logFile, "✅ Категории в БД отсутствуют — загружаем дерево категорий WB\n");
            if (empty(! $categories = $this->fetchCategoriesWithRetry((int) $wbSupplierID, 5, 10))) {
                $this->saveCategories($categories);
            }
        } elseif ($retryUncheckedOnly) {
            $pending = Category::query()->where('checked', 0)->count();
            Storage::disk('local')->append(
                $this->logFile,
                "✅ Режим переобхода: только категории с checked=0 ({$pending} шт.), остальные checked не сбрасываем\n"
            );
        } else {
            $resetCount = Category::query()->update(['checked' => 0]);
            Storage::disk('local')->append(
                $this->logFile,
                "✅ Сброшен checked для {$resetCount} категорий — полный обход каталога для поиска сирот\n"
            );
        }

        $categories = Category::where('checked', 0)->get();
        if ($categories->isEmpty()) {
            if ($retryUncheckedOnly) {
                Storage::disk('local')->append(
                    $this->logFile,
                    "⚠️  Нет категорий с checked=0 — переобход нечего. Снимите «только переобход» для полного сброса и обхода или дождитесь незавершённых категорий после клонирования.\n"
                );
            } else {
                Storage::disk('local')->append(
                    $this->logFile,
                    "⚠️  Нет категорий для обхода (таблица category пуста или не удалось загрузить список)\n"
                );
            }
            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");

            return;
        }

        foreach ($categories as $category) {
            if ($this->reachedCatalogProductLimit()) {
                Storage::disk('local')->append(
                    $this->logFile,
                    "✅ Достигнут лимит просмотра {$this->data['quantity']} товаров — остановка\n"
                );
                break;
            }

            $categoryLoaded = $this->getProducts((int) $wbSupplierID, $category);

            if ($categoryLoaded) {
                $category->checked = 1;
                $category->save();
            } else {
                Storage::disk('local')->append(
                    $this->logFile,
                    "⚠️  Категория ID {$category->category_id} остаётся с checked=0 — товары не получены после попыток\n"
                );
            }
        }

        Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");
    }

    /**
     * Только обход product_queues: восстановление сирот и разбор дубликатов (getCardInfo), включая заблокированные позиции.
     */
    private function handleOrphanScanOnly(): void
    {
        Storage::disk('local')->append(
            $this->logFile,
            "✅ Режим: только проверка сирот по очереди (включая blocked), без отправки в WB\n"
        );
        $sellerId = $this->data['seller_id'] ?? null;
        if (empty($sellerId)) {
            Storage::disk('local')->append($this->logFile, "❌ Не указан магазин (seller_id)\n");
            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");

            return;
        }
        if (Sellers::find($sellerId) === null) {
            Storage::disk('local')->append($this->logFile, "❌ Магазин не найден\n");
            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");

            return;
        }

        $limit = (int) ($this->data['quantity'] ?? 5000);
        $limit = max(1, min($limit, 10000));

        $queue = ProductQueue::query()
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($queue->isEmpty()) {
            Storage::disk('local')->append($this->logFile, "⚠️  Очередь пуста — нечего проверять\n");
            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");

            return;
        }

        Storage::disk('local')->append(
            $this->logFile,
            "✅ К проверке сирот: {$queue->count()} позиций из очереди (ограничение {$limit}, blocked учитываются)\n"
        );
        $this->orphanQueueProgressDone = 0;
        $this->processQueue($queue, false);
        Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");
    }

    /**
     * @throws ConnectionException
     */
    private function handleSendQueueToWb(): void
    {
        Storage::disk('local')->append($this->logFile, "✅ Режим: отправка накопленной очереди в WB\n");
        $sellerId = $this->data['seller_id'] ?? null;
        $seller = $sellerId ? Sellers::find($sellerId) : null;
        if (! $seller) {
            Storage::disk('local')->append($this->logFile, "❌ Продавец не найден (seller_id)\n");
            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");

            return;
        }

        $queue = ProductQueue::where('blocked', 0)->orderBy('id')->get();
        if ($queue->isEmpty()) {
            Storage::disk('local')->append($this->logFile, "⚠️  Очередь пуста\n");
            Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");

            return;
        }

        Storage::disk('local')->append(
            $this->logFile,
            "✅ В очереди {$queue->count()} позиций, лимит обработки {$this->data['quantity']} шт.\n"
        );
        $this->processQueue($queue);
        Storage::disk('local')->append($this->logFile, '🏁 Джоба завершена: '.now()."\n");
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
            if (is_array($categories) && ! empty($categories)) {
                Storage::disk('local')->append(
                    $this->logFile,
                    '✅ Успешно получено '.count($categories['data']['filters'][0]['items'])." категорий на попытке {$attempt}\n"
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
            'errors' => [],
        ];
        foreach ($categoriesData as $categoryData) {
            $stats['processed']++;
            try {
                $existingCategory = Category::where('category_id', $categoryData['id'])->first();
                if (! $existingCategory) {
                    Category::create([
                        'category_id' => $categoryData['id'],
                        'name' => $categoryData['name'],
                        'parent_id' => $categoryData['parentId'] ?? null,
                        'parent_name' => $categoryData['parentName'] ?? null,
                    ]);
                    $stats['created']++;
                }
            } catch (\Exception $e) {
                $stats['errors'][] = "Error processing category ID {$categoryData['id']}: ".$e->getMessage();
            }
        }

        return $stats;
    }

    /**
     * @return bool true, если ответ по категории получен и обработан; false, если после всех попыток — null
     */
    private function getProducts($wbSupplierID, $category): bool
    {
        $products = $this->fetchCategoryProductsWithRetry($wbSupplierID, $category->category_id);
        if ($products !== null) {
            $this->processCategoryProducts($products, $wbSupplierID, $category->category_id);

            return true;
        }

        return false;
    }

    private function processProductsPage(array $products): void
    {
        foreach ($products as $productData) {
            if ($this->isOrphanCatalogScanOnly()) {
                if ($this->reachedCatalogProductLimit()) {
                    break;
                }
                $seller = Sellers::find($this->data['seller_id'] ?? null);
                if ($seller !== null) {
                    $this->tryRestoreOrphanFromWbCatalogProduct($productData, $seller);
                }
                $this->increment++;
                $this->appendOrphanProgressLine(
                    'catalog',
                    $this->increment,
                    (int) ($this->data['quantity'] ?? 0)
                );

                continue;
            }
            if ($this->isQueueOnly() && $this->increment >= $this->data['quantity']) {
                break;
            }
            $this->addProductQueue($productData);
        }
        Storage::disk('local')->append(
            $this->logFile,
            '📦 Обработано '.count($products)." товаров с текущей страницы\n"
        );
    }

    /**
     * Добавляет товар в очередь на обработку, если он отсутствует в таблице Cards
     *
     * @param  mixed  $productData  Данные товара, должны содержать ключ 'id'
     * @return bool Успешность операции
     */
    private function addProductQueue(mixed $productData): bool
    {
        try {
            if ($this->isQueueOnly() && $this->increment >= $this->data['quantity']) {
                return false;
            }
            if (! isset($productData['id'])) {
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
            $existsInSkuMapping = SkuMapping::query()
                ->where(function ($q) use ($sku) {
                    $q->where('wbSku', $sku)->orWhere('origSku', $sku);
                })
                ->where(function ($q) {
                    $q->where('blocked', false)->orWhereNull('blocked');
                })
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
                if ($this->isQueueOnly()) {
                    $skuKey = (string) $sku;
                    $existingInQueue = ProductQueue::query()
                        ->where('sku', $skuKey)
                        ->where(function ($q): void {
                            $q->where('blocked', false)->orWhereNull('blocked');
                        })
                        ->get();
                    if ($existingInQueue->isNotEmpty()) {
                        if (Sellers::find($this->data['seller_id'] ?? null) === null) {
                            $this->logWarning(
                                "Проверка сирот для SKU {$skuKey} пропущена: в джобе нет seller_id (магазин). ".
                                'Без него processQueue не выполняется — проверьте сессию магазина и поле seller_id в запросе /api/clone-products/start.'
                            );
                        } else {
                            $this->logInfo("Повторный проход: сироты/дубликаты по уже существующей позиции очереди (SKU {$skuKey})");
                            $this->processQueue($existingInQueue, false);
                        }
                    } elseif (ProductQueue::query()->where('sku', $skuKey)->exists()) {
                        $seller = Sellers::find($this->data['seller_id'] ?? null);
                        $blockedRows = ProductQueue::query()
                            ->where('sku', $skuKey)
                            ->where('blocked', 1)
                            ->get();
                        if ($seller !== null && $blockedRows->isNotEmpty()) {
                            $this->logInfo(
                                "Повторный проход: проверка сирот по заблокированной позиции очереди (SKU {$skuKey})"
                            );
                            $this->processQueue($blockedRows, false);
                        } elseif ($seller === null) {
                            $this->logWarning(
                                "Проверка сирот для SKU {$skuKey} пропущена (blocked): нет seller_id в джобе"
                            );
                        }
                    }
                }

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
                if ($this->isQueueOnly()) {
                    $this->increment++;
                    $this->skusAddedThisQueueOnlyRun[(string) $sku] = true;
                }

                return true;
            } else {
                $this->logError("Не удалось добавить товар с SKU: {$sku} в очередь обработки");

                return false;
            }
        } catch (\Exception $e) {
            $this->logError('Ошибка при добавлении товара в очередь: '.$e->getMessage());

            return false;
        }
    }

    private function fetchCategoryProductsWithRetry($wbSupplierID, $categoryId)
    {
        $maxRetries = 10;
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
        if ($pages > 1 && ! $this->reachedCatalogProductLimit()) {
            $this->fetchAndProcessRemainingPages($wbSupplierID, $categoryId, $pages);
        }
    }

    private function fetchAndProcessRemainingPages($wbSupplierID, $categoryId, $totalPages): void
    {
        for ($page = 2; $page <= $totalPages; $page++) {
            if ($this->reachedCatalogProductLimit()) {
                break;
            }
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
            $this->logWarning("{$message}. Попытка {$retryCount} из {$maxRetries}. Ожидание 10 сек…");
            sleep(10);
        } else {
            $this->logError("Все попытки завершены. {$message}");
        }
    }

    /**
     * @param  bool  $uploadToWb  Если false — только сироты/дубликаты по очереди (без addProductsFromSourceBatch); строки очереди для новых карточек сохраняются.
     *
     * @throws ConnectionException
     */
    private function processQueue(CollectionAlias $productsToQueue, bool $uploadToWb = true): void
    {
        $seller = Sellers::find($this->data['seller_id'] ?? null);
        if ($seller === null) {
            Storage::disk('local')->append(
                $this->logFile,
                "⚠️  seller_id не задан или магазин не найден — обработка очереди пропущена\n"
            );

            return;
        }

        $batchSize = (int) ($this->data['batch_size'] ?? 20);
        $batchSize = max(1, min($batchSize, 100));

        foreach ($productsToQueue->chunk($batchSize) as $productChunk) {
            if ($uploadToWb && $this->increment >= $this->data['quantity']) {
                break;
            }

            $batchItems = [];
            $service = $uploadToWb
                ? new WildberriesService($seller->wb_api_key, [
                    'prefix' => '',
                    'nmID' => '0',
                    'package' => 1,
                    'price' => 0,
                ])
                : null;

            foreach ($productChunk as $product) {
                if ($uploadToWb && $this->increment >= $this->data['quantity']) {
                    break;
                }

                try {
                    $this->logInfo("Начинаем обработку продукта SKU: {$product->sku}");
                    if ($this->isOrphanScanOnly() && ! $uploadToWb) {
                        $this->orphanQueueProgressDone++;
                        $this->appendOrphanProgressLine(
                            'queue',
                            $this->orphanQueueProgressDone,
                            (int) ($this->data['quantity'] ?? 0)
                        );
                    }

                    $info = WBContent::getCardInfo($product->sku);
                    if (! is_array($info)) {
                        throw new \RuntimeException('getCardInfo вернул неожиданный ответ');
                    }

                    $vendorCode = isset($info['vendor_code']) ? (string) $info['vendor_code'] : '';

                    if ($vendorCode !== '') {
                        $orphanCard = Cards::query()
                            ->where('sellerID', $seller->id)
                            ->where('vendorCode', $vendorCode)
                            ->where('orphan_for_clone', true)
                            ->first();

                        if ($orphanCard) {
                            $queueSku = (string) $product->sku;
                            $this->restoreOrphanCardFromWbMatch($orphanCard, $queueSku, (float) $product->price);

                            $product->delete();
                            $this->logSuccess(
                                "Сирота по vendorCode {$vendorCode}: sku обновлен на {$queueSku}, пометка снята"
                            );
                            if (! $this->skipIncrementLikeQueueOnly()) {
                                $this->increment++;
                            }

                            continue;
                        }
                    }

                    // Дубликат ищем по vendorCode (у сироты sku донора может быть пустым). Если WB не вернул vendor_code — запасной поиск по sku донора.
                    $donorNmId = (string) $product->sku;
                    $existingCard = $this->findSellerCardDuplicate($seller->id, $vendorCode, $donorNmId);
                    if ($existingCard !== null) {
                        $ref = $vendorCode !== '' ? "vendorCode {$vendorCode}" : "sku донора {$donorNmId}";
                        if ($existingCard->orphan_for_clone) {
                            $this->logWarning(
                                "Карточка по {$ref} помечена сиротой — в батч WB не добавляем, очередь сохранена для восстановления"
                            );
                        } else {
                            $this->logWarning(
                                "Карточка по {$ref} уже есть у продавца в БД — в батч WB не добавляем, очередь очищена"
                            );
                            $product->delete();
                        }
                        if (! $this->skipIncrementLikeQueueOnly()) {
                            $this->increment++;
                        }

                        continue;
                    }

                    if (! $uploadToWb) {
                        continue;
                    }

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
                    $this->logError("Критическая ошибка при подготовке продукта {$product->sku}: ".$e->getMessage());
                    if (! $this->skipIncrementLikeQueueOnly()) {
                        $this->increment++;
                    }
                }
            }

            if (empty($batchItems)) {
                continue;
            }

            try {
                $payload = array_map(static fn ($item) => [
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
                    $skippedByQueueSku[(string) $skippedItem['queueSku']] = $skippedItem;
                }
                $uploadedVendorCodeByQueueSku = [];
                foreach ($result['items'] ?? [] as $uploadedItem) {
                    if (empty($uploadedItem['queueSku'])) {
                        continue;
                    }
                    $uploadedVendorCodeByQueueSku[(string) $uploadedItem['queueSku']] = $uploadedItem['vendorCode'] ?? null;
                }

                foreach ($batchItems as $item) {
                    /** @var ProductQueue $product */
                    $product = $item['product'];
                    $queueSku = (string) $product->sku;
                    if (! isset($skippedByQueueSku[$queueSku])) {
                        continue;
                    }

                    $skipError = $skippedByQueueSku[$queueSku]['error'] ?? 'Unknown error';
                    if ($skipError === 'Amount is null') {
                        $product->blocked = 1;
                        $product->save();
                        $this->logWarning(
                            "Продукт {$product->sku} помещен в блокировку: {$skipError}. ".
                                'Товар не отправлен в WB'
                        );
                    } else {
                        $this->logWarning(
                            "Пропуск продукта {$product->sku}: {$skipError}. ".
                                'Товар не отправлен в WB и удален из очереди без блокировки'
                        );
                        $product->delete();
                    }
                }

                if ($result['success']) {
                    foreach ($batchItems as $item) {
                        /** @var ProductQueue $product */
                        $product = $item['product'];
                        $info = $item['info'];
                        if (isset($skippedByQueueSku[(string) $product->sku])) {
                            continue;
                        }

                        SkuMapping::updateOrCreate(
                            ['origSku' => $info['vendor_code']],
                            [
                                'origSku' => $info['vendor_code'],
                                'wbSku' => $product->sku,
                                'wbPrice' => $product->price,
                                'blocked' => false,
                            ]
                        );
                        WbJob::dispatch('getCardList', [
                            'seller_id' => $seller->id,
                            'sourceSku' => $info['vendor_code'],
                            'queueWbSku' => $product->sku,
                            'settings' => [
                                'settings' => [
                                    'filter' => [
                                        'textSearch' => $uploadedVendorCodeByQueueSku[(string) $product->sku] ?? $item['info']['vendor_code'],
                                        'withPhoto' => -1,
                                    ],
                                ],
                            ],
                        ])->onQueue('updateCardsProcess')->delay(now()->addMinute());
                        SimJob::dispatch('calcPrice', ['sid' => $info['vendor_code']])->onQueue('updateCardsProcess');
                        $product->delete();
                        $this->logSuccess("Продукт {$product->sku} успешно добавлен и удален из очереди");
                    }
                } else {
                    $err = $result['error'] ?? [];
                    $errorText = is_array($err)
                        ? (string) ($err['errorText'] ?? $err['message'] ?? '')
                        : (string) $err;
                    if ($errorText === '') {
                        $errorText = 'Неизвестная ошибка';
                    }

                    foreach ($batchItems as $item) {
                        $product = $item['product'];
                        if (isset($skippedByQueueSku[(string) $product->sku])) {
                            continue;
                        }
                        $this->logWarning(
                            "Ошибка batch upload для продукта {$product->sku}: {$errorText}"
                        );
                    }

                    $this->logInfo(
                        'Ответ WB (batch failed): '.json_encode($result, JSON_UNESCAPED_UNICODE)
                    );
                }
            } catch (\Exception $e) {
                foreach ($batchItems as $item) {
                    $product = $item['product'];
                    $product->blocked = 1;
                    $product->save();
                    $this->logError("Критическая ошибка при обработке продукта {$product->sku}: ".$e->getMessage());
                }
            }

            if ($uploadToWb && $this->increment >= $this->data['quantity']) {
                $this->logSuccess("Обработано {$this->increment} товаров из {$this->data['quantity']}. Конец очереди");
                break;
            }

            if ($uploadToWb) {
                $this->logInfo('Пауза 1 секунда...');
                sleep(1);
            }
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

    /**
     * Машиночитаемый прогресс для UI (полный лог читается на стороне API).
     */
    private function appendOrphanProgressLine(string $kind, int $done, int $limit): void
    {
        Storage::disk('local')->append(
            $this->logFile,
            "ORPHAN_PROGRESS\t{$kind}\t{$done}\t{$limit}\n"
        );
    }

    /**
     * Общее восстановление сироты после сопоставления с nmID в WB (очередь или каталог).
     */
    private function restoreOrphanCardFromWbMatch(Cards $orphanCard, string $wbNmId, float $wbPrice): void
    {
        $vendorCode = (string) $orphanCard->vendorCode;
        $orphanCard->sku = $wbNmId;
        $orphanCard->orphan_for_clone = false;
        $orphanCard->save();

        $mappingCreated = $this->ensureSkuMappingIfMissing($vendorCode, $wbNmId, $wbPrice);
        if ($mappingCreated) {
            $this->logInfo("SkuMapping восстановлена: origSku={$vendorCode}, wbSku={$wbNmId}");
        }

        SimJob::dispatch('calcPrice', ['sid' => $vendorCode])->onQueue('updateCardsProcess');
    }

    /**
     * Карточка из выдачи категории WB: по nmID уточняем vendor_code и при необходимости восстанавливаем сироту магазина.
     *
     * @param  array<string, mixed>  $productData
     */
    private function tryRestoreOrphanFromWbCatalogProduct(array $productData, Sellers $seller): void
    {
        if (! isset($productData['id'])) {
            return;
        }

        if (! empty($this->data['in_stock_only']) && ($productData['totalQuantity'] ?? 0) < 5) {
            return;
        }

        $nmId = (string) $productData['id'];
        $price = 1.0;
        if (isset($productData['sizes'][0]['price']['product'])) {
            $price = (float) $productData['sizes'][0]['price']['product'] / 100;
        }

        try {
            $info = WBContent::getCardInfo($nmId);
        } catch (\Throwable $e) {
            $this->logWarning("getCardInfo nmID {$nmId}: ".$e->getMessage());

            return;
        }

        if (! is_array($info)) {
            return;
        }

        $vendorCode = isset($info['vendor_code']) ? (string) $info['vendor_code'] : '';
        if ($vendorCode === '') {
            return;
        }

        $orphanCard = Cards::query()
            ->where('sellerID', $seller->id)
            ->where('vendorCode', $vendorCode)
            ->where('orphan_for_clone', true)
            ->first();

        if ($orphanCard === null) {
            return;
        }

        $this->restoreOrphanCardFromWbMatch($orphanCard, $nmId, $price);
        $this->logSuccess(
            "Сирота восстановлена из каталога WB: vendorCode {$vendorCode}, nmID {$nmId}"
        );
    }

    /**
     * Карточка уже есть: сначала по vendorCode (надёжно для сирот с пустым sku), иначе по sku донора (nmID из очереди).
     */
    private function findSellerCardDuplicate(int $sellerId, string $vendorCode, string $donorNmId): ?Cards
    {
        if ($vendorCode !== '') {
            $byVendor = Cards::query()
                ->where('sellerID', $sellerId)
                ->where('vendorCode', $vendorCode)
                ->first();
            if ($byVendor !== null) {
                return $byVendor;
            }
        }

        return Cards::query()
            ->where('sellerID', $sellerId)
            ->where('sku', $donorNmId)
            ->first();
    }

    /**
     * Создаёт строку skuMapping для восстановленной сироты, если записи с таким origSku ещё нет.
     *
     * У таблицы уникальны и origSku, и wbSku; при восстановлении сироты второй ключ может конфликтовать —
     * тогда новую строку не создаём и не роняем джобу (карточка уже обновлена снаружи).
     */
    private function ensureSkuMappingIfMissing(string $origSku, string $wbSku, float $wbPrice): bool
    {
        if ($origSku === '') {
            return false;
        }

        $existing = SkuMapping::query()->where('origSku', $origSku)->first();
        if ($existing !== null) {
            if ($existing->blocked) {
                $existing->blocked = false;
                $wbTakenElsewhere = SkuMapping::query()
                    ->where('wbSku', $wbSku)
                    ->where('id', '!=', $existing->id)
                    ->exists();
                if ($wbTakenElsewhere) {
                    $other = SkuMapping::query()->where('wbSku', $wbSku)->first();
                    $this->logWarning(
                        'ensureSkuMappingIfMissing: wbSku '.$wbSku.' уже занят строкой skuMapping id='.
                        ($other->id ?? '?').', origSku='.($other->origSku ?? '')."; не меняем wbSku для origSku {$origSku}"
                    );
                } else {
                    $existing->wbSku = $wbSku;
                }
                $existing->wbPrice = $wbPrice;
                $existing->save();

                return true;
            }

            return false;
        }

        $rowByWb = SkuMapping::query()->where('wbSku', $wbSku)->first();
        if ($rowByWb !== null && (string) $rowByWb->origSku !== (string) $origSku) {
            $this->logWarning(
                "ensureSkuMappingIfMissing: не создаём mapping для origSku {$origSku}: wbSku {$wbSku} уже привязан к origSku {$rowByWb->origSku} (skuMapping id={$rowByWb->id})"
            );

            return false;
        }

        try {
            SkuMapping::create([
                'origSku' => $origSku,
                'wbSku' => $wbSku,
                'wbPrice' => $wbPrice,
            ]);
        } catch (QueryException $e) {
            $sqlState = (string) $e->getCode();
            if ($sqlState === '23000' || str_contains((string) $e->getMessage(), 'UNIQUE constraint failed')) {
                $this->logWarning(
                    'ensureSkuMappingIfMissing: не удалось создать skuMapping (уникальность): origSku '.
                    $origSku.', wbSku '.$wbSku.' — '.$e->getMessage()
                );

                return false;
            }

            throw $e;
        }

        return true;
    }
}
