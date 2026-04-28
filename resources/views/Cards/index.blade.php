@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — товары')
@section('content')
    <div class="page-content-wrapper">
        <div class="alert alert-success" id="alert" style="display: none"></div>
        <button
            class="btn btn-primary has-icon btn-rounded"
            id="updateCardProcess"
            data-seller="{{ session()->get('seller') }}"
        >
            <i class="mdi mdi mdi-autorenew"></i> Обновить
        </button>
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="row">
                    <!-- Прелоадер -->
                    <div id="loader" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">Загрузка...</span>
                        </div>
                        <p class="mt-2">Загружаем карточки товаров...</p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover d-none" id="cardsTable">
                            <thead>
                            <tr>
                                <th></th>
                                <th>ID</th>
                                <th>Артикул</th>
                                <th>Поставщик</th>
                                <th>ID Поставщика</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody id="cardsTableBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="photoModal" class="modal fade" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Просмотр изображения</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-center" style="padding: 20px;">
                    <img id="modalImage" style="max-height: 80vh;object-fit: contain;" class="img-fluid" src=""
                         alt="Увеличенное фото">
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script src="{{ asset('assets/js/Cards/index.js') }}"></script>
@endsection
