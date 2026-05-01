@extends('components.sellers')
@extends('layouts.app')
@section('title', ' — водители')
@section('content')
    <div class="page-content-wrapper page-fleet">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="glass-panel cards-toolbar">
                    <div class="cards-toolbar__title">
                        <h4 class="mb-1">Водители</h4>
                        <p class="text-muted mb-0">Список водителей и закрепление за машиной из парка.</p>
                    </div>
                    <div class="d-flex">
                        <button class="btn btn-primary" id="addDriverBtn">
                            <i class="mdi mdi-plus"></i> Добавить водителя
                        </button>
                    </div>
                </div>

                <div class="alert mt-3 d-none" id="driversAlert"></div>

                <div class="glass-panel cards-content mt-3">
                    <div class="table-responsive">
                        <table class="table table-hover ui-data-table">
                            <thead>
                            <tr>
                                <th>ФИО</th>
                                <th>Телефон</th>
                                <th>Машина</th>
                                <th>Комментарий</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody id="driversTableBody"></tbody>
                        </table>
                    </div>
                    <div class="text-center text-muted py-4 d-none" id="driversEmptyState">
                        Водителей пока нет. Добавьте первого.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="driverModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form id="driverForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="driverModalTitle">Новый водитель</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="driverId" name="id">
                        <div class="form-group">
                            <label for="driverFullName">ФИО</label>
                            <input type="text" class="form-control" id="driverFullName" name="full_name" required>
                        </div>
                        <div class="form-group">
                            <label for="driverPhone">Телефон</label>
                            <input type="text" class="form-control" id="driverPhone" name="phone" placeholder="+7 …">
                        </div>
                        <div class="form-group">
                            <label for="driverVehicleId">Машина</label>
                            <select class="form-control" id="driverVehicleId" name="fleet_vehicle_id">
                                <option value="">Не закреплено</option>
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label for="driverNotes">Комментарий</label>
                            <textarea class="form-control" id="driverNotes" name="notes" rows="3" placeholder="Заметки"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script src="{{ asset('assets/js/Drivers/index.js') }}"></script>
@endsection
