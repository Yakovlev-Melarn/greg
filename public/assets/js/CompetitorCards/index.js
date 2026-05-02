$(document).ready(function () {
    let currentJobId = null;
    let logPollingInterval = null;
    ajaxGetSuppliers(null, null, true);
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
        $('#logStatus').removeClass('bg-success bg-danger').addClass('bg-info').text('Запуск джобы...');
        let formData = {
            supplier_id: $('#supplier').val(),
            quantity: $('#quantity').val(),
            min_price: $('#minPrice').val() || 0,
            max_price: $('#maxPrice').val() || 100000,
            batch_size: $('#batchSize').val() || 20,
            in_stock_only: $('#inStockOnly').is(':checked') ? 1 : 0,
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

    function startLogPolling(jobId) {
        if (logPollingInterval) {
            clearInterval(logPollingInterval);
        }
        logPollingInterval = setInterval(function () {
            $.ajax({
                url: '/api/clone-products/logs/',
                data: {job_id: jobId},
                method: 'POST',
                global: false,
                success: function (response) {
                    if (response.logs && response.logs.length > 0) {
                        updateLogs(response.logs);
                        if (response.status === 'completed') {
                            $('#logStatus').removeClass('bg-info').addClass('bg-success').text('Завершено');
                            clearInterval(logPollingInterval);
                        } else if (response.status === 'failed') {
                            $('#logStatus').removeClass('bg-info').addClass('bg-danger').text('Ошибка');
                            clearInterval(logPollingInterval);
                        } else {
                            $('#logStatus').text('Выполняется...');
                        }
                    }
                },
                error: function () {
                    addLogMessage('⚠️ Ошибка получения логов');
                }
            });
        }, 2000);
    }

    function updateLogs(logs) {
        let $logContainer = $('#jobLogs');
        if (logs.length > 100) {
            logs = logs.slice(-100);
        }
        logs.forEach(function (log) {
            let existingLogs = $logContainer.children().length;
            if (existingLogs >= 100) {
                $logContainer.children().first().remove();
            }
            let $logEntry = $('<div>').text(log.message);
            if (log.type === 'error') {
                $logEntry.addClass('text-danger');
            } else if (log.type === 'success') {
                $logEntry.addClass('text-success');
            } else if (log.type === 'warning') {
                $logEntry.addClass('text-warning');
            }
            $logContainer.append($logEntry);
        });
        $logContainer.scrollTop($logContainer[0].scrollHeight);
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
        $('#jobLogs').empty();
        addLogMessage('🗑️ Лог очищен');
    });
});
