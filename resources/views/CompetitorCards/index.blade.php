@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — товары конкурентов')
@section('content')
    <div class="page-content-wrapper">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Клонирование карточек товаров</h5>
            </div>
            <div class="card-body">
                <form id="cloneForm" class="needs-validation" method="post">
                    <div class="mb-3">
                        <label for="supplier" class="form-label">Поставщик</label>
                        <select class="form-select" id="supplier" required>
                            <option value="" disabled selected>Выберите поставщика</option>
                        </select>
                        <div class="invalid-feedback">
                            Пожалуйста, выберите поставщика.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="quantity" class="form-label">Количество товаров</label>
                        <select class="form-select" id="quantity" required>
                            <option value="" disabled selected>Выберите количество</option>
                            <option value="1">1</option>
                            <option value="10">10</option>
                            <option value="100">100</option>
                            <option value="1000">1 000</option>
                        </select>
                        <div class="invalid-feedback">
                            Пожалуйста, выберите количество.
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="minPrice" class="form-label">Минимальная цена, ₽</label>
                            <input type="number" class="form-control" id="minPrice" placeholder="0" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="maxPrice" class="form-label">Максимальная цена, ₽</label>
                            <input type="number" class="form-control" id="maxPrice" placeholder="100 000" min="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="batchSize" class="form-label">Размер пакета отправки в WB</label>
                        <input
                            type="number"
                            class="form-control"
                            id="batchSize"
                            value="20"
                            min="1"
                            max="100"
                            required
                        >
                        <div class="invalid-feedback">
                            Укажите размер пакета от 1 до 100.
                        </div>
                        <small class="text-muted">Рекомендуемое значение: 20-50</small>
                    </div>

                    <!-- Новое поле: Префикс артикула -->
                    <div class="mb-3">
                        <label for="prefix" class="form-label">Префикс артикула</label>
                        <input
                            type="text"
                            class="form-control"
                            id="prefix"
                            value="SM-L"
                            placeholder="Введите префикс (например: SM-L)"
                            required
                        >
                        <div class="invalid-feedback">
                            Пожалуйста, укажите префикс артикула.
                        </div>
                        <small class="text-muted">Используется для формирования артикулов новых товаров</small>
                    </div>

                    <div class="mb-3 form-check">
                        <input class="form-check-input" checked type="checkbox" id="inStockOnly">
                        <label class="form-check-label" for="inStockOnly">
                            Только в наличии
                        </label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            Запустить клонирование
                        </button>
                        <button type="reset" class="btn btn-secondary flex-grow-1">
                            Сбросить
                        </button>
                    </div>
                </form>

                <div class="mt-4" id="logSection" style="display: none;">
                    <h6>Лог выполнения</h6>
                    <div id="jobLogs" style="height: 300px; overflow-y: auto; background-color: #f8f9fa; border: 1px solid #ddd; padding: 10px; font-size: 0.9rem;">
                        <!-- Лог будет добавляться здесь -->
                    </div>
                    <div class="mt-2">
                        <button id="clearLogBtn" class="btn btn-sm btn-secondary">Очистить лог</button>
                        <span id="logStatus" class="badge bg-info ms-2">Ожидание...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script src="{{ asset('assets/js/CompetitorCards/index.js') }}"></script>
@endsection
