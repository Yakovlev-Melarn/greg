<script type="text/template" id="price-recalc-template">
    <form id="priceRecalcForm" method="post">
        @csrf
        <p class="text-muted small mb-3">
            Укажите желаемую наценку к закупочной цене (в процентах). Для всех записей,
            у которых заданы закупка и логистика, будут пересчитаны цена продажи, итоговая стоимость,
            комиссия, налог и чистая прибыль; для строк установится флаг для синхронизации с WB.
        </p>
        <div class="mb-3">
            <label class="form-label" for="profit_margin_percent">Наценка, %</label>
            <input type="number" class="form-control" id="profit_margin_percent" name="profit_margin_percent"
                   step="0.01" min="0" max="99.99" required placeholder="например 17">
        </div>
    </form>
    <button type="button" class="btn btn-secondary" data-dismiss="modal">Отмена</button>
    <button type="submit" class="btn btn-primary" form="priceRecalcForm">Пересчитать</button>
</script>
