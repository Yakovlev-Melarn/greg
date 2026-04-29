@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — копирование карточки')
@section('content')
    <div class="page-content-wrapper page-copycard">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                @if(session()->has('error'))
                    <div class="alert alert-danger alert-dismissible fade show ui-alert" role="alert">
                        <strong>Ошибка!</strong>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                @endif
                @if(session()->has('success'))
                    <div class="alert alert-success alert-dismissible fade show ui-alert" role="alert">
                        <strong>Карточка скопирована.</strong>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                @endif
                <div class="glass-panel card-form mt-4 ui-form-shell">
                    <div class="card-title ui-form-shell__title">
                        Копирование карточки товара
                    </div>
                    <div class="card-body">
                        <form method="post" action="" class="ui-form-grid">
                            @csrf
                            <div class="form-group">
                                <label for="nmID">Артикул nmID:</label>
                                <input type="text" class="form-control" id="nmID" name="nmID" value="" required>
                            </div>

                            <div class="form-group">
                                <label for="price">Цена:</label>
                                <input type="text" class="form-control" id="price" name="price" value="" required>
                            </div>

                            <div class="form-group">
                                <label for="prefix">Префикс артикула:</label>
                                <input type="text" class="form-control" id="prefix" name="prefix" value="LC-S" required>
                            </div>

                            <div class="form-group">
                                <label for="store_article">Артикул магазина:</label>
                                <input type="text" class="form-control" id="store_article" value=""
                                       name="store_article"
                                       required>
                            </div>

                            <div class="form-group">
                                <label for="package">Упаковка:</label>
                                <input type="number" class="form-control" id="package" name="package" value="1" min="1"
                                       required>
                            </div>

                            <button type="submit" class="btn btn-primary has-icon ui-action-btn"><i
                                    class="mdi mdi-content-copy"></i> Копировать
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script src="{{ asset('assets/js/CopyCard/index.js') }}"></script>
@endsection
