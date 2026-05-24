(function () {
    const STORAGE_KEY = 'simaSupplierAuditJobId';
    let logPollingInterval = null;
    let currentJobId = null;

    function getSellerId() {
        const fromMeta = String($('meta[name="sellerId"]').attr('content') || '').trim();
        if (fromMeta) {
            return fromMeta;
        }
        if (typeof sellerId !== 'undefined' && sellerId) {
            return String(sellerId);
        }
        return '';
    }

    function getCurrentSellerLabel() {
        const fromSidebar = $('#sellersBlock #appsDropdown').text().trim();
        if (fromSidebar) {
            return fromSidebar;
        }
        const fromPage = $('#simaAuditCurrentSellerName').text().trim();
        return fromPage || '';
    }

    function updateSellerBannerUi() {
        const id = getSellerId();
        const $btn = $('#simaAuditStartBtn');
        const $banner = $('#simaAuditSellerBanner');
        if (!id) {
            $btn.prop('disabled', true);
            $banner
                .removeClass('alert-secondary')
                .addClass('alert-warning')
                .html(
                    '<strong>Магазин не выбран.</strong> ' +
                    'В левой колонке (над меню) нажмите на название магазина и выберите магазин из списка — страница обновится.'
                );
            return;
        }
        const label = getCurrentSellerLabel() || ('#' + id);
        $banner
            .removeClass('alert-warning')
            .addClass('alert-secondary')
            .html(
                '<strong>Текущий магазин:</strong> <span id="simaAuditCurrentSellerName">' +
                escapeHtml(label) +
                '</span>' +
                '<span class="text-muted small d-block mt-1 mb-0">Сменить магазин — в левой колонке над меню нажмите на название и выберите другой.</span>'
            );
        if (!$btn.data('audit-running')) {
            $btn.prop('disabled', false);
        }
    }

    function formatNum(n) {
        return Number(n || 0).toLocaleString('ru-RU');
    }

    function escapeHtml(text) {
        return $('<div>').text(text || '').html();
    }

    function renderLogs(logs) {
        const $c = $('#simaAuditLogContainer');
        if (!logs || !logs.length) {
            $c.html('<div class="text-muted">Нет записей</div>');
            return;
        }
        const html = logs.map(function (entry) {
            const cls = entry.type === 'error' ? 'text-danger'
                : entry.type === 'success' ? 'text-success'
                    : entry.type === 'warning' ? 'text-warning' : '';
            return '<div class="' + cls + '">' + escapeHtml(entry.message) + '</div>';
        }).join('');
        $c.html(html);
        $c.scrollTop($c[0].scrollHeight);
    }

    function updateCounters(run) {
        if (!run) {
            return;
        }
        $('#cntSwitchedToWb').text(formatNum(run.switched_to_wb));
        $('#cntTrashed').text(formatNum(run.trashed));
        $('#cntNotOnWb').text(formatNum(run.not_on_wb));
        $('#cntSimaCheaper').text(formatNum(run.sima_cheaper));
        $('#cntMissingMapping').text(formatNum(run.missing_mapping));
        $('#cntSkippedLowStock').text(formatNum(run.skipped_low_stock));
        $('#cntWbErrors').text(formatNum(run.wb_errors));
        $('#cntSkippedOther').text(formatNum(run.skipped_other));

        const progress = run.total > 0
            ? Math.round(100 * run.processed / run.total)
            : (run.status === 'completed' ? 100 : 0);
        $('#simaAuditProgressBar').css('width', progress + '%');
        $('#simaAuditStatProgress').text(run.processed + ' / ' + run.total + ' (' + progress + '%)');
    }

    function updateCountersFromStatus(run) {
        updateCounters({
            switched_to_wb: run.switched_to_wb,
            trashed: run.trashed,
            not_on_wb: run.not_on_wb,
            sima_cheaper: run.sima_cheaper,
            missing_mapping: run.missing_mapping,
            skipped_low_stock: run.skipped_low_stock,
            wb_errors: run.wb_errors,
            skipped_other: run.skipped_other,
            processed: run.processed,
            total: run.total,
            status: run.status,
        });
    }

    function loadRunsHistory() {
        const sellerId = getSellerId();
        if (!sellerId) {
            $('#simaAuditRunsBody').html('<tr><td colspan="7" class="text-muted text-center">Выберите магазин</td></tr>');
            return;
        }
        $.ajax({
            url: '/api/sima-supplier-audit/runs/',
            method: 'POST',
            data: { seller_id: sellerId, per_page: 15 },
            success: function (data) {
                const items = data.items || [];
                if (!items.length) {
                    $('#simaAuditRunsBody').html('<tr><td colspan="7" class="text-muted text-center">Нет прогонов</td></tr>');
                    return;
                }
                const rows = items.map(function (r) {
                    const prog = r.total > 0 ? r.processed + '/' + r.total : '—';
                    return '<tr>' +
                        '<td>' + r.id + '</td>' +
                        '<td>' + escapeHtml(r.status) + '</td>' +
                        '<td>' + prog + '</td>' +
                        '<td>' + formatNum(r.switched_to_wb) + '</td>' +
                        '<td>' + formatNum(r.trashed) + '</td>' +
                        '<td>' + escapeHtml(r.started_at || '—') + '</td>' +
                        '<td>' + escapeHtml(r.finished_at || '—') + '</td>' +
                        '</tr>';
                });
                $('#simaAuditRunsBody').html(rows.join(''));
            },
        });
    }

    function refreshPageStats() {
        const sellerId = getSellerId();
        if (!sellerId) {
            return;
        }
        $.ajax({
            url: '/api/sima-supplier-audit/status/',
            method: 'POST',
            data: { seller_id: sellerId },
            success: function (data) {
                const run = data.run;
                if (run && run.status === 'running') {
                    $('#simaAuditStartBtn').prop('disabled', true).data('audit-running', true);
                    $('#simaAuditProgressWrap').show();
                    updateCountersFromStatus(run);
                    if (!currentJobId && run.job_id) {
                        currentJobId = run.job_id;
                        localStorage.setItem(STORAGE_KEY, currentJobId);
                        startLogPolling(currentJobId);
                    }
                } else if (!run || run.status !== 'running') {
                    $('#simaAuditStartBtn').prop('disabled', false).removeData('audit-running');
                }
            },
        });
    }

    function startLogPolling(jobId) {
        if (logPollingInterval) {
            clearInterval(logPollingInterval);
        }
        currentJobId = jobId;
        localStorage.setItem(STORAGE_KEY, jobId);
        $('#simaAuditProgressWrap').show();

        function poll() {
            $.ajax({
                url: '/api/sima-supplier-audit/logs/',
                method: 'POST',
                data: { job_id: jobId },
                global: false,
                success: function (response) {
                    renderLogs(response.logs || []);
                    if (response.run) {
                        updateCounters(response.run);
                    }
                    if (response.progress) {
                        $('#simaAuditStatProgress').text(
                            response.progress.done + ' / ' + response.progress.total
                        );
                    }
                    if (response.status === 'completed') {
                        $('#simaAuditLogStatus').removeClass('bg-info').addClass('bg-success').text('Завершено');
                        clearInterval(logPollingInterval);
                        logPollingInterval = null;
                        $('#simaAuditStartBtn').prop('disabled', false).removeData('audit-running');
                        loadRunsHistory();
                        refreshPageStats();
                    } else if (response.status === 'failed') {
                        $('#simaAuditLogStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка');
                        clearInterval(logPollingInterval);
                        $('#simaAuditStartBtn').prop('disabled', false).removeData('audit-running');
                    } else if (response.status === 'not_found') {
                        $('#simaAuditLogStatus').addClass('bg-danger').text('Лог не найден');
                        clearInterval(logPollingInterval);
                    } else {
                        $('#simaAuditLogStatus').removeClass('bg-success bg-danger').addClass('bg-info').text('Выполняется…');
                    }
                },
            });
        }

        poll();
        logPollingInterval = setInterval(poll, 2000);
    }

    $('#simaAuditStartBtn').on('click', function () {
        const sellerId = getSellerId();
        if (!sellerId) {
            alert('Сначала выберите магазин: в левой колонке над меню нажмите на название магазина и выберите пункт из списка.');
            return;
        }
        const $btn = $(this);
        $btn.prop('disabled', true).data('audit-running', true);
        $.ajax({
            url: '/api/sima-supplier-audit/start/',
            method: 'POST',
            data: {
                seller_id: sellerId,
                force_reaudit: $('#simaAuditForceReaudit').is(':checked') ? 1 : 0,
            },
            success: function (response) {
                if (response.job_id) {
                    $('#simaAuditLogStatus').removeClass('bg-success bg-danger').addClass('bg-info').text('Запущено');
                    startLogPolling(response.job_id);
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false).removeData('audit-running');
                const msg = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Не удалось запустить аудит';
                alert(msg);
            },
        });
    });

    $(function () {
        updateSellerBannerUi();
        loadRunsHistory();
        refreshPageStats();

        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            startLogPolling(saved);
        }

        $(document).ajaxComplete(function (_e, _xhr, settings) {
            if (settings && settings.url && String(settings.url).indexOf('api/sellers/list') !== -1) {
                updateSellerBannerUi();
            }
        });
    });
})();
