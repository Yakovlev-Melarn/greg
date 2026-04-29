@extends('layouts.app')
@section('title', ' — уведомления')
@section('content')
    <div class="page-content-wrapper notifications-page">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="grid">
                    <div class="grid-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h4 class="mb-0">Уведомления системы</h4>
                            <button type="button" class="btn btn-outline-primary" id="markAllNotificationsReadPage">Отметить все как прочитанные</button>
                        </div>

                        <form method="get" class="row mb-4">
                            <div class="col-md-3 mb-2">
                                <select name="level" class="form-control">
                                    <option value="">Все типы</option>
                                    @foreach(['info' => 'Info', 'success' => 'Success', 'warning' => 'Warning', 'error' => 'Error'] as $value => $label)
                                        <option value="{{ $value }}" @selected($selectedLevel === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2 mb-2">
                                <select name="read_status" class="form-control">
                                    <option value="">Все статусы</option>
                                    <option value="unread" @selected($selectedReadStatus === 'unread')>Непрочитанные</option>
                                    <option value="read" @selected($selectedReadStatus === 'read')>Прочитанные</option>
                                </select>
                            </div>
                            <div class="col-md-5 mb-2">
                                <input type="text" class="form-control" name="q" value="{{ $search }}"
                                       placeholder="Поиск по заголовку или тексту">
                            </div>
                            <div class="col-md-2 mb-2">
                                <button type="submit" class="btn btn-primary btn-block">Фильтр</button>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-hover ui-data-table">
                                <thead>
                                <tr>
                                    <th class="col-priority-2">Дата</th>
                                    <th class="col-priority-3">Тип</th>
                                    <th class="col-priority-2">Статус</th>
                                    <th class="col-priority-1">Заголовок</th>
                                    <th class="col-priority-2">Текст</th>
                                    <th class="col-priority-3"></th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($notifications as $notification)
                                    <tr>
                                        <td class="col-priority-2">{{ $notification->created_at?->format('d.m.Y H:i') }}</td>
                                        <td class="col-priority-3">
                                            <span class="badge badge-{{ $notification->level === 'error' ? 'danger' : ($notification->level === 'warning' ? 'warning' : ($notification->level === 'success' ? 'success' : 'info')) }}">
                                                {{ strtoupper($notification->level) }}
                                            </span>
                                        </td>
                                        <td class="col-priority-2">
                                            <span class="badge js-read-status-badge badge-{{ $notification->is_read ? 'secondary' : 'primary' }}">
                                                {{ $notification->is_read ? 'READ' : 'UNREAD' }}
                                            </span>
                                        </td>
                                        <td class="col-priority-1">{{ $notification->title }}</td>
                                        <td class="col-priority-2">{{ $notification->message }}</td>
                                        <td class="text-right col-priority-3">
                                            @if(! $notification->is_read)
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-primary js-mark-read-notification-page"
                                                        data-id="{{ $notification->id }}">
                                                    Прочитано
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">Уведомлений пока нет</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if($notifications->hasPages())
                            <div class="d-flex justify-content-between mt-3">
                                <div>
                                    @if($notifications->onFirstPage())
                                        <span class="btn btn-light disabled">Назад</span>
                                    @else
                                        <a class="btn btn-light" href="{{ $notifications->previousPageUrl() }}">Назад</a>
                                    @endif
                                </div>
                                <div class="text-muted align-self-center">
                                    Страница {{ $notifications->currentPage() }} из {{ $notifications->lastPage() }}
                                </div>
                                <div>
                                    @if($notifications->hasMorePages())
                                        <a class="btn btn-light" href="{{ $notifications->nextPageUrl() }}">Вперед</a>
                                    @else
                                        <span class="btn btn-light disabled">Вперед</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('js')
    <script>
        $(document).on('click', '#markAllNotificationsReadPage', function () {
            ajaxMarkAllNotificationsRead();
            window.location.reload();
        });
    </script>
@endsection
