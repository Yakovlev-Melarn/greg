$(document).ready(function () {
    let currentJobId = null;
    let logPollingInterval = null;
    let competitorStatsPollInterval = null;
    /** Тип запущенной джобы сирот + лимит (для счётчика в реальном времени). */
    let competitorOrphanJob = null;
    const COMPETITOR_STATS_POLL_MS = 10000;
    /** Лог последней джобы клонирования — восстановление после обновления страницы */
    const CLONE_JOB_STORAGE_KEY = 'greg_competitorCards_clone_job';

    ajaxGetSuppliers(null, null, true);

    function getSellerIdMeta() {
        return String($('meta[name="sellerId"]').attr('content') || '');
    }

    function persistCloneJobId(jobId, meta) {
        try {
            var payload = { job_id: jobId, seller_id: getSellerIdMeta() };
            if (meta && typeof meta === 'object' && meta.orphan_type) {
                payload.orphan_type = meta.orphan_type;
                payload.orphan_limit = Number(meta.orphan_limit);
            }
            sessionStorage.setItem(CLONE_JOB_STORAGE_KEY, JSON.stringify(payload));
        } catch (e) {
            /* ignore */
        }
    }

    function readPersistedCloneJobRecord() {
        try {
            var raw = sessionStorage.getItem(CLONE_JOB_STORAGE_KEY);
            if (!raw) {
                return null;
            }
            var data = JSON.parse(raw);
            if (!data || !data.job_id) {
                return null;
            }
            if (data.seller_id && data.seller_id !== getSellerIdMeta()) {
                return null;
            }

            return data;
        } catch (e) {
            return null;
        }
    }

    function readPersistedCloneJobId() {
        var rec = readPersistedCloneJobRecord();

        return rec ? String(rec.job_id) : null;
    }

    function clearPersistedCloneJob() {
        try {
            sessionStorage.removeItem(CLONE_JOB_STORAGE_KEY);
        } catch (e) {
            /* ignore */
        }
        competitorOrphanJob = null;
        hideOrphanProgressPanels();
    }

    function hideOrphanProgressPanels() {
        $('#orphanScanProgressLive, #orphanCatalogProgressLive').prop('hidden', true).removeClass('is-live-active');
    }

    function showOrphanProgressForType(type, limit) {
        hideOrphanProgressPanels();
        var id = type === 'queue' ? '#orphanScanProgressLive' : '#orphanCatalogProgressLive';
        var lim = Number(limit) || 0;
        var $el = $(id);
        $el.prop('hidden', false).addClass('is-live-active');
        $el.find('.orphan-progress-live__limit').text(formatRuInteger(lim));
        $el.find('.orphan-progress-live__done').text(formatRuInteger(0));
        $el.find('.orphan-progress-live__remaining').text(formatRuInteger(lim));
    }

    function setCompetitorOrphanJob(type, limit) {
        competitorOrphanJob = { type: type, limit: Number(limit) || 0 };
    }

    function updateOrphanProgressFromResponse(response) {
        if (!competitorOrphanJob || !response || !response.orphan_progress) {
            return;
        }
        var type = competitorOrphanJob.type;
        var slot = type === 'queue' ? response.orphan_progress.queue : response.orphan_progress.catalog;
        if (!slot || typeof slot.done !== 'number') {
            return;
        }
        var limit = slot.limit != null ? slot.limit : competitorOrphanJob.limit;
        var remaining = Math.max(0, limit - slot.done);
        var id = type === 'queue' ? '#orphanScanProgressLive' : '#orphanCatalogProgressLive';
        var $el = $(id);
        $el.prop('hidden', false).addClass('is-live-active');
        $el.find('.orphan-progress-live__limit').text(formatRuInteger(limit));
        $el.find('.orphan-progress-live__done').text(formatRuInteger(slot.done));
        $el.find('.orphan-progress-live__remaining').text(formatRuInteger(remaining));
    }

    function formatRuInteger(n) {
        return Number(n).toLocaleString('ru-RU');
    }

    function parseRuIntegerFromDom(text) {
        if (text === null || text === undefined) {
            return 0;
        }
        var cleaned = String(text).replace(/\s/g, '').replace(/\u00a0/g, '').trim();
        var num = parseInt(cleaned, 10);

        return Number.isFinite(num) ? num : 0;
    }

    function easeOutCubic(t) {
        return 1 - Math.pow(1 - t, 3);
    }

    /**
     * Плавный пересчёт числа; при смене значения подсвечиваем плитку.
     */
    function animateStatCounter($valueEl, targetInt, options) {
        options = options || {};
        var animate = options.animate !== false;
        var $tile = $valueEl.closest('[data-competitor-stat-tile]');
        var startInt = parseRuIntegerFromDom($valueEl.text());
        if (targetInt === startInt) {
            return;
        }
        if (!animate) {
            $valueEl.text(formatRuInteger(targetInt));
            return;
        }
        var durationMs = 420;
        var t0 = typeof performance !== 'undefined' ? performance.now() : Date.now();
        function done() {
            $valueEl.text(formatRuInteger(targetInt));
            if ($tile.length) {
                $tile.removeClass('is-value-updated');
                void $tile[0].offsetWidth;
                $tile.addClass('is-value-updated');
                setTimeout(function () {
                    $tile.removeClass('is-value-updated');
                }, 750);
            }
        }
        function frame(now) {
            var t = Math.min(1, (now - t0) / durationMs);
            var eased = easeOutCubic(t);
            var current = Math.round(startInt + (targetInt - startInt) * eased);
            $valueEl.text(formatRuInteger(current));
            if (t < 1) {
                requestAnimationFrame(frame);
            } else {
                done();
            }
        }
        requestAnimationFrame(frame);
    }

    function refreshCompetitorStats(opts) {
        opts = opts || {};
        $.ajax({
            url: '/api/clone-products/stats',
            method: 'POST',
            data: { seller_id: $('meta[name="sellerId"]').attr('content') },
            global: false,
            success: function (response) {
                if (response && typeof response.orphan_cards_count !== 'undefined') {
                    animateStatCounter($('#competitorStatOrphans'), Number(response.orphan_cards_count), {
                        animate: opts.animate !== false,
                    });
                }
                if (response && typeof response.clone_queue_ready_count !== 'undefined') {
                    animateStatCounter($('#competitorStatQueue'), Number(response.clone_queue_ready_count), {
                        animate: opts.animate !== false,
                    });
                }
                if (response && typeof response.categories_unchecked_count !== 'undefined') {
                    $('#competitorStatCategoriesUnchecked').text(
                        Number(response.categories_unchecked_count).toLocaleString('ru-RU')
                    );
                }
            },
        });
    }

    function startCompetitorStatsPolling() {
        if (competitorStatsPollInterval) {
            clearInterval(competitorStatsPollInterval);
        }
        competitorStatsPollInterval = setInterval(function () {
            if (document.hidden) {
                return;
            }
            refreshCompetitorStats({ animate: true });
        }, COMPETITOR_STATS_POLL_MS);
    }

    startCompetitorStatsPolling();

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            refreshCompetitorStats({ animate: true });
        }
    });

    $(window).on('beforeunload', function () {
        if (competitorStatsPollInterval) {
            clearInterval(competitorStatsPollInterval);
        }
    });

    function applyQueueOnlyQuantityMode() {
        const queueOnly = $('#queueOnly').is(':checked');
        const opts = queueOnly
            ? window.COMPETITOR_QUANTITY_OPTIONS_QUEUE || []
            : window.COMPETITOR_QUANTITY_OPTIONS_WB || [];
        const $qty = $('#quantity');
        const prev = $qty.val();
        $qty.empty();
        $qty.append($('<option>', { value: '', text: 'Выберите количество', disabled: true, selected: true }));
        opts.forEach(function (q) {
            const label = Number(q).toLocaleString('ru-RU');
            $qty.append($('<option>', { value: q, text: label }));
        });
        if (prev && opts.map(String).includes(String(prev))) {
            $qty.val(prev);
        }
        const canRunClone = queueOnly || (window.COMPETITOR_QUANTITY_REMAINING || 0) > 0;
        $('#cloneSubmitBtn').prop('disabled', !canRunClone || opts.length === 0);
        $qty.prop('disabled', opts.length === 0);
    }

    $('#queueOnly').on('change', applyQueueOnlyQuantityMode);
    applyQueueOnlyQuantityMode();

    // Запуск клонирования
    $('#cloneForm').on('submit', function (e) {
        const formElement = this;
        e.preventDefault();
        if (!formElement.checkValidity()) {
            e.stopPropagation();
            $(formElement).addClass('was-validated');
            return;
        }
        $('#logSection').show();
        $('#jobLogs').empty();
        competitorOrphanJob = null;
        hideOrphanProgressPanels();
        $('#logStatus').removeClass('bg-success bg-danger').addClass('bg-info').text('Запуск джобы...');
        let formData = {
            supplier_id: $('#supplier').val(),
            quantity: $('#quantity').val(),
            min_price: $('#minPrice').val() || 0,
            max_price: $('#maxPrice').val() || 100000,
            batch_size: $('#batchSize').val() || 20,
            in_stock_only: $('#inStockOnly').is(':checked') ? 1 : 0,
            queue_only: $('#queueOnly').is(':checked') ? 1 : 0,
            prefix: $('#prefix').val(),
            seller_id: $('meta[name="sellerId"]').attr('content')
        };
        $.ajax({
            url: '/api/clone-products/start',
            method: 'POST',
            data: formData,
            success: function (response) {
                if (response.job_id) {
                    currentJobId = response.job_id;
                    $('#logStatus').text('Джоба запущена...');
                    startLogPolling(currentJobId);
                } else {
                    $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка запуска');
                }
            },
            error: function (xhr) {
                $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка: ' + xhr.responseText);
                addLogMessage('❌ Ошибка при запуске джобы');
            }
        });
    });

    $('#orphanCatalogForm').on('submit', function (e) {
        e.preventDefault();
        const formElement = this;
        if (!formElement.checkValidity()) {
            e.stopPropagation();
            $(formElement).addClass('was-validated');
            return;
        }
        const supplierId = $('#supplier').val();
        if (!supplierId) {
            $('#logSection').show();
            $('#logStatus').removeClass('bg-success bg-danger').addClass('bg-danger').text('Выберите поставщика в форме клонирования выше');
            addLogMessage('❌ Укажите поставщика в блоке «Клонирование карточек товаров»');
            return;
        }
        $('#logSection').show();
        $('#jobLogs').empty();
        $('#logStatus').removeClass('bg-success bg-danger').addClass('bg-info').text('Запуск обхода каталога WB…');
        const formData = {
            supplier_id: supplierId,
            quantity: $('#orphanCatalogQuantity').val(),
            in_stock_only: $('#orphanCatalogInStockOnly').is(':checked') ? 1 : 0,
            orphan_catalog_retry_unchecked_only: $('#orphanCatalogRetryUncheckedOnly').is(':checked') ? 1 : 0,
            seller_id: $('meta[name="sellerId"]').attr('content'),
        };
        $.ajax({
            url: '/api/clone-products/startOrphanCatalogScan',
            method: 'POST',
            data: formData,
            success: function (response) {
                if (response.job_id) {
                    currentJobId = response.job_id;
                    $('#logStatus').text('Джоба запущена…');
                    var lim = Number($('#orphanCatalogQuantity').val());
                    setCompetitorOrphanJob('catalog', lim);
                    showOrphanProgressForType('catalog', lim);
                    startLogPolling(currentJobId, { orphan_type: 'catalog', orphan_limit: lim });
                } else {
                    $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка запуска');
                }
            },
            error: function (xhr) {
                let msg = xhr.responseText;
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка: ' + msg);
                addLogMessage('❌ ' + msg, 'error');
            },
        });
    });

    $('#orphanScanForm').on('submit', function (e) {
        e.preventDefault();
        const formElement = this;
        if (!formElement.checkValidity()) {
            e.stopPropagation();
            $(formElement).addClass('was-validated');
            return;
        }
        $('#logSection').show();
        $('#jobLogs').empty();
        $('#logStatus').removeClass('bg-success bg-danger').addClass('bg-info').text('Запуск проверки сирот...');
        const formData = {
            quantity: $('#orphanScanQuantity').val(),
            batch_size: $('#orphanScanBatchSize').val() || 20,
            seller_id: $('meta[name="sellerId"]').attr('content')
        };
        $.ajax({
            url: '/api/clone-products/startOrphanScan',
            method: 'POST',
            data: formData,
            success: function (response) {
                if (response.job_id) {
                    currentJobId = response.job_id;
                    $('#logStatus').text('Джоба запущена...');
                    var lim = Number($('#orphanScanQuantity').val());
                    setCompetitorOrphanJob('queue', lim);
                    showOrphanProgressForType('queue', lim);
                    startLogPolling(currentJobId, { orphan_type: 'queue', orphan_limit: lim });
                } else {
                    $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка запуска');
                }
            },
            error: function (xhr) {
                let msg = xhr.responseText;
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка: ' + msg);
                addLogMessage('❌ ' + msg, 'error');
            }
        });
    });

    $('#sendQueueForm').on('submit', function (e) {
        e.preventDefault();
        const formElement = this;
        if (!formElement.checkValidity()) {
            e.stopPropagation();
            $(formElement).addClass('was-validated');
            return;
        }
        $('#logSection').show();
        $('#jobLogs').empty();
        competitorOrphanJob = null;
        hideOrphanProgressPanels();
        $('#logStatus').removeClass('bg-success bg-danger').addClass('bg-info').text('Запуск отправки очереди...');
        const formData = {
            quantity: $('#queueSendQuantity').val(),
            batch_size: $('#queueBatchSize').val() || 20,
            seller_id: $('meta[name="sellerId"]').attr('content')
        };
        $.ajax({
            url: '/api/clone-products/processQueue',
            method: 'POST',
            data: formData,
            success: function (response) {
                if (response.job_id) {
                    currentJobId = response.job_id;
                    $('#logStatus').text('Джоба запущена...');
                    startLogPolling(currentJobId);
                } else {
                    $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка запуска');
                }
            },
            error: function (xhr) {
                let msg = xhr.responseText;
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка: ' + msg);
                addLogMessage('❌ ' + msg, 'error');
            }
        });
    });

    function startLogPolling(jobId, meta) {
        if (logPollingInterval) {
            clearInterval(logPollingInterval);
            logPollingInterval = null;
        }
        currentJobId = jobId;
        persistCloneJobId(jobId, meta);

        function pollLogs() {
            $.ajax({
                url: '/api/clone-products/logs/',
                data: { job_id: jobId },
                method: 'POST',
                global: false,
                success: function (response) {
                    renderLogsFull(response.logs || []);
                    updateOrphanProgressFromResponse(response);
                    if (response.status === 'completed') {
                        $('#logStatus').removeClass('bg-info').addClass('bg-success').text('Завершено');
                        clearInterval(logPollingInterval);
                        logPollingInterval = null;
                        refreshCompetitorStats();
                    } else if (response.status === 'failed') {
                        $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка');
                        clearInterval(logPollingInterval);
                        logPollingInterval = null;
                    } else if (response.status === 'not_found') {
                        $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Файл лога не найден');
                        clearInterval(logPollingInterval);
                        logPollingInterval = null;
                        clearPersistedCloneJob();
                    } else {
                        $('#logStatus').removeClass('bg-success bg-danger').addClass('bg-info').text('Выполняется...');
                    }
                },
                error: function () {
                    addLogMessage('⚠️ Ошибка получения логов');
                },
            });
        }

        pollLogs();
        logPollingInterval = setInterval(pollLogs, 2000);
    }

    /**
     * Полная перерисовка лога из ответа API (без дублей при каждом опросе).
     */
    function renderLogsFull(logs) {
        var $logContainer = $('#jobLogs');
        $logContainer.empty();
        if (logs.length > 100) {
            logs = logs.slice(-100);
        }
        logs.forEach(function (log) {
            var msg = String(log.message || '');
            if (msg.indexOf('ORPHAN_PROGRESS\t') === 0) {
                return;
            }
            var $logEntry = $('<div>').text(log.message);
            if (log.type === 'error') {
                $logEntry.addClass('text-danger');
            } else if (log.type === 'success') {
                $logEntry.addClass('text-success');
            } else if (log.type === 'warning') {
                $logEntry.addClass('text-warning');
            }
            $logContainer.append($logEntry);
        });
        if ($logContainer[0]) {
            $logContainer.scrollTop($logContainer[0].scrollHeight);
        }
    }

    function restoreLogsFromSession() {
        var rec = readPersistedCloneJobRecord();
        if (!rec || !rec.job_id) {
            return;
        }
        var jobId = String(rec.job_id);
        currentJobId = jobId;
        if (rec.orphan_type && rec.orphan_limit != null) {
            setCompetitorOrphanJob(rec.orphan_type, rec.orphan_limit);
            showOrphanProgressForType(rec.orphan_type, rec.orphan_limit);
        }
        $('#logSection').show();
        $('#logStatus').removeClass('bg-success bg-danger').addClass('bg-info').text('Загрузка лога…');
        $.ajax({
            url: '/api/clone-products/logs/',
            data: { job_id: jobId },
            method: 'POST',
            global: false,
            success: function (response) {
                if (response.status === 'not_found') {
                    clearPersistedCloneJob();
                    $('#logSection').hide();
                    currentJobId = null;

                    return;
                }
                renderLogsFull(response.logs || []);
                updateOrphanProgressFromResponse(response);
                if (response.status === 'completed') {
                    $('#logStatus').removeClass('bg-info').addClass('bg-success').text('Завершено');
                    refreshCompetitorStats();
                } else if (response.status === 'failed') {
                    $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка');
                } else {
                    $('#logStatus').removeClass('bg-success bg-danger').addClass('bg-info').text('Выполняется…');
                    var pollMeta =
                        rec.orphan_type != null && rec.orphan_type !== ''
                            ? { orphan_type: rec.orphan_type, orphan_limit: rec.orphan_limit }
                            : undefined;
                    startLogPolling(jobId, pollMeta);
                }
            },
            error: function () {
                $('#logStatus').removeClass('bg-info').addClass('bg-warning').text('Не удалось загрузить лог');
            },
        });
    }

    function addLogMessage(message, type = 'info') {
        let $logContainer = $('#jobLogs');
        let $logEntry = $('<div>').text(message);
        if (type === 'error') {
            $logEntry.addClass('text-danger');
        } else if (type === 'success') {
            $logEntry.addClass('text-success');
        }
        if ($logContainer.children().length >= 100) {
            $logContainer.children().first().remove();
        }
        $logContainer.append($logEntry);
        $logContainer.scrollTop($logContainer[0].scrollHeight);
    }

    $('#clearLogBtn').on('click', function () {
        if (logPollingInterval) {
            clearInterval(logPollingInterval);
            logPollingInterval = null;
        }
        clearPersistedCloneJob();
        currentJobId = null;
        $('#jobLogs').empty();
        $('#logSection').hide();
        $('#logStatus').removeClass('bg-success bg-danger bg-warning').addClass('bg-info').text('Ожидание...');
    });

    function parseOrphansCountFromDom() {
        var raw = ($('#competitorStatOrphans').text() || '0').replace(/\u00a0/g, ' ').trim();

        return parseInt(raw.replace(/\s+/g, '').replace(/[^\d]/g, ''), 10) || 0;
    }

    function copyTextToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            try {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                var ok = document.execCommand('copy');
                document.body.removeChild(ta);
                if (ok) {
                    resolve();
                } else {
                    reject(new Error('execCommand'));
                }
            } catch (e) {
                reject(e);
            }
        });
    }

    function showCompetitorCopyNotice(message) {
        var $host = $('.page-competitors .glass-panel.ui-form-shell').first();
        if (!$host.length) {
            return;
        }
        var $el = $('#competitorCopyNotice');
        if (!$el.length) {
            $el = $('<div id="competitorCopyNotice" class="competitor-copy-notice" role="status" aria-live="polite"></div>');
            $host.prepend($el);
        }
        $el.text(message).addClass('is-visible');
        clearTimeout(showCompetitorCopyNotice._t);
        showCompetitorCopyNotice._t = setTimeout(function () {
            $el.removeClass('is-visible');
        }, 3200);
    }

    $(document).on('click', '[data-competitor-copy-orphan-codes]', function (e) {
        e.preventDefault();
        var n = parseOrphansCountFromDom();
        if (n <= 0) {
            showCompetitorCopyNotice('Сирот нет — копировать нечего.');
            return;
        }
        var sellerId = getSellerIdMeta();
        if (!sellerId) {
            showCompetitorCopyNotice('Выберите магазин в шапке сайта.');
            return;
        }
        $.post({
            url: '/api/clone-products/orphanSupplierVendorCodes',
            global: false,
            data: { seller_id: sellerId },
        })
            .done(function (res) {
                var codes = res && Array.isArray(res.codes) ? res.codes : [];
                if (!codes.length) {
                    showCompetitorCopyNotice('Список артикулов пуст.');
                    return;
                }
                var body = codes.join('\n');
                copyTextToClipboard(body).then(
                    function () {
                        showCompetitorCopyNotice('Скопировано в буфер: ' + codes.length + ' ' + (codes.length === 1 ? 'артикул' : 'артикулов') + '.');
                    },
                    function () {
                        showCompetitorCopyNotice('Не удалось записать в буфер обмена.');
                    }
                );
            })
            .fail(function (xhr) {
                var msg =
                    xhr && xhr.responseJSON && xhr.responseJSON.message
                        ? String(xhr.responseJSON.message)
                        : 'Не удалось получить список артикулов.';
                showCompetitorCopyNotice(msg);
            });
    });

    $(document).on('keydown', '[data-competitor-copy-orphan-codes]', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $(this).trigger('click');
        }
    });

    restoreLogsFromSession();
});
