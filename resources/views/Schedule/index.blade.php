@extends('layouts.app')
@section('title', ' — график')
@section('content')
    <div class="page-content-wrapper page-fleet page-schedule">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="glass-panel schedule-toolbar">
                    <div class="d-flex flex-wrap align-items-center justify-content-between">
                        <div>
                            <h4 class="mb-1">График</h4>
                            <p class="text-muted mb-0">Календарь смен, маршрутов и корректировок</p>
                        </div>
                        <div class="schedule-month-nav d-flex align-items-center mt-2 mt-md-0">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="schedulePrevMonth">
                                <i class="mdi mdi-chevron-left"></i>
                            </button>
                            <strong class="px-3" id="scheduleMonthLabel"></strong>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="scheduleNextMonth">
                                <i class="mdi mdi-chevron-right"></i>
                            </button>
                            <input type="month" class="form-control form-control-sm ml-2 schedule-month-picker" id="scheduleMonthPicker">
                            <button type="button" class="btn btn-light btn-sm ml-2" id="scheduleCurrentMonth">Текущий</button>
                        </div>
                    </div>
                </div>

                <div class="alert mt-3 d-none" id="scheduleAlert"></div>

                <div class="row mt-3">
                    <div class="col-12 col-xl-8">
                        <div class="glass-panel schedule-calendar-panel">
                            <div class="schedule-calendar-scroll">
                                <div class="schedule-weekdays-grid">
                                    <div>Пн</div><div>Вт</div><div>Ср</div><div>Чт</div><div>Пт</div><div>Сб</div><div>Вс</div>
                                </div>
                                <div class="schedule-days-grid" id="scheduleDaysGrid"></div>
                            </div>
                            <div class="text-center text-muted py-3 d-none" id="scheduleCalendarLoading">Загрузка...</div>
                        </div>
                    </div>
                    <div class="col-12 col-xl-4 mt-3 mt-xl-0">
                        <div class="glass-panel schedule-day-panel">
                            <h6 class="mb-2" id="scheduleDayTitle">День не выбран</h6>
                            <div id="scheduleDayContent" class="text-muted">Выберите день в календаре.</div>
                            <hr>
                            <h6 class="mb-2">Суммы маршрутных листов за месяц</h6>
                            <div class="schedule-monthly-chart-wrap">
                                <canvas id="scheduleMonthlyPieChart" height="220"></canvas>
                                <div id="scheduleMonthlyPieEmpty" class="text-muted d-none">Нет данных за выбранный месяц.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script src={{ url('/assets/js/Schedule/index.js') }}></script>
@endsection
