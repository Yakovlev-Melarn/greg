<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="token" content="{{ session()->get('token') }}">
    <meta name="csrf" content="{{ csrf_token() }}">
    <meta name="sellerId" content="{{ session()->get('seller') }}">
    <title>G.R.E.G. @yield('title')</title>
    <link rel="stylesheet" href="{{ url('/assets/vendors/iconfonts/mdi/css/materialdesignicons.css') }}">
    <link rel="stylesheet" href="{{ url('/assets/css/shared/style.css') }}">
    <link rel="stylesheet" href="{{ url('/assets/css/greg1/style.css') }}">
    <link rel="shortcut icon" href="{{ url('/assets/images/profile/male/image_1.png') }}"/>
    @viteReactRefresh
</head>
<body class="header-fixed">
<div id="globalAjaxPreloader" class="global-ajax-preloader" aria-hidden="true">
    <div class="global-ajax-preloader__backdrop"></div>
    <div class="global-ajax-preloader__box">
        <div class="spinner-border text-primary" role="status">
            <span class="sr-only">Загрузка…</span>
        </div>
        <span class="global-ajax-preloader__text">Загрузка…</span>
    </div>
</div>
<nav class="t-header">
    <div class="t-header-brand-wrapper">
        <a href="/" class="logoword">
            G.R.E.G.
        </a>
    </div>
    <div class="t-header-content-wrapper">
        <div class="t-header-content">
            <button class="t-header-toggler t-header-mobile-toggler d-block d-lg-none">
                <i class="mdi mdi-menu"></i>
            </button>
            <ul class="nav ml-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link" href="#" id="notificationDropdown" data-toggle="dropdown" aria-expanded="false">
                        <i class="mdi mdi-bell-outline mdi-1x"></i>
                        <span id="notificationBellBadge"
                              class="notification-indicator notification-indicator-danger notification-indicator-ripple{{ ($unreadSystemNotificationsCount ?? 0) > 0 ? '' : ' d-none' }}"></span>
                    </a>
                    <div class="dropdown-menu navbar-dropdown dropdown-menu-right"
                         aria-labelledby="notificationDropdown">
                        <div class="dropdown-header">
                            <h6 class="dropdown-title">Уведомления</h6>
                            <p class="dropdown-title-text" id="notifications-unread-counter">Новых уведомлений: {{ $unreadSystemNotificationsCount ?? 0 }}</p>
                        </div>
                        <div class="dropdown-body" id="notifications-dropdown-body">
                            @forelse(($latestSystemNotifications ?? collect()) as $notification)
                                @php
                                    $iconClass = match ($notification->level) {
                                        'success' => 'mdi-check-circle text-success',
                                        'warning' => 'mdi-alert text-warning',
                                        'error' => 'mdi-alert-circle text-danger',
                                        default => 'mdi-information text-primary',
                                    };
                                @endphp
                                <div class="dropdown-list{{ $notification->is_read ? '' : ' js-mark-read-notification' }}" data-id="{{ $notification->id }}">
                                    <div class="icon-wrapper rounded-circle bg-inverse-primary">
                                        <i class="mdi {{ $iconClass }}"></i>
                                    </div>
                                    <div class="content-wrapper">
                                        <small class="name{{ $notification->is_read ? '' : ' font-weight-bold' }}">{{ $notification->title }}</small>
                                        <small class="content-text">{{ $notification->message }}</small>
                                    </div>
                                </div>
                            @empty
                                <div class="dropdown-list">
                                    <div class="content-wrapper">
                                        <small class="name">Пока пусто</small>
                                        <small class="content-text">Системные уведомления еще не поступали</small>
                                    </div>
                                </div>
                            @endforelse
                        </div>
                        <div class="dropdown-footer">
                            <a href="/notifications">Смотреть все</a>
                            <a href="#" id="markAllNotificationsRead">Отметить все как прочитанные</a>
                        </div>
                    </div>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link" href="#" id="messageDropdown" data-toggle="dropdown" aria-expanded="false">
                        <i class="mdi mdi-message-outline mdi-1x"></i>
                        <span
                            class="notification-indicator notification-indicator-primary notification-indicator-ripple"></span>
                    </a>
                    <div class="dropdown-menu navbar-dropdown dropdown-menu-right" aria-labelledby="messageDropdown">
                        <div class="dropdown-header">
                            <h6 class="dropdown-title">Сообщения</h6>
                            <p class="dropdown-title-text">У вас 410 непрочитанных сообщений</p>
                        </div>
                        <div class="dropdown-body">
                            <div class="dropdown-list">
                                <div class="image-wrapper">
                                    <div class="status-indicator rounded-indicator bg-success"></div>
                                </div>
                                <div class="content-wrapper">
                                    <small class="name">Copy Card Job</small>
                                    <small class="content-text">Все карточки скопированы.</small>
                                </div>
                            </div>
                            <div class="dropdown-list">
                                <div class="image-wrapper">
                                    <div class="status-indicator rounded-indicator bg-warning"></div>
                                </div>
                                <div class="content-wrapper">
                                    <small class="name">Delete Card Job</small>
                                    <small class="content-text">Удалено 172 карточки без остатка.</small>
                                </div>
                            </div>
                            <div class="dropdown-list">
                                <div class="image-wrapper">
                                    <div class="status-indicator rounded-indicator bg-danger"></div>
                                </div>
                                <div class="content-wrapper">
                                    <small class="name">Sync Card Job</small>
                                    <small class="content-text">Синхронизация карточек завершилась неудачей.</small>
                                </div>
                            </div>
                        </div>
                        <div class="dropdown-footer">
                            <a href="#">Смотреть все</a>
                            <a href="#">Отметить все как прочитанные</a>
                        </div>
                    </div>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link" href="#" id="appsDropdown" data-toggle="dropdown" aria-expanded="false">
                        <i class="mdi mdi-apps mdi-1x"></i>
                    </a>
                    <div class="dropdown-menu navbar-dropdown dropdown-menu-right apps-utilities-menu" aria-labelledby="appsDropdown">
                        <div class="dropdown-header">
                            <h6 class="dropdown-title">Настройки и утилиты</h6>
                        </div>
                        <div class="dropdown-body border-top pt-0 utilities-grid">
                            <a class="dropdown-grid utilities-grid__item" id="sellersModal">
                                <i class="grid-icon mdi mdi-store mdi-2x"></i>
                                <span class="grid-tittle">Магазины</span>
                            </a>
                            <a class="dropdown-grid utilities-grid__item" id="suppliersModal">
                                <i class="grid-icon mdi mdi-truck-fast mdi-2x"></i>
                                <span class="grid-tittle">Поставщики</span>
                            </a>
                            <a class="dropdown-grid utilities-grid__item" id="priceRecalcModal">
                                <i class="grid-icon mdi mdi-cash mdi-2x"></i>
                                <span class="grid-tittle">Пересчёт цен</span>
                            </a>
                            <a class="dropdown-grid utilities-grid__item">
                                <i class="grid-icon mdi mdi-sword-cross mdi-2x"></i>
                                <span class="grid-tittle">Конкуренты</span>
                            </a>
                            <a class="dropdown-grid utilities-grid__item">
                                <i class="grid-icon mdi mdi-settings mdi-2x"></i>
                                <span class="grid-tittle">Процессы</span>
                            </a>
                            <a class="dropdown-grid utilities-grid__item">
                                <i class="grid-icon mdi mdi-calendar mdi-2x"></i>
                                <span class="grid-tittle">Календари</span>
                            </a>
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>
<div class="page-body">
    <div class="sidebar">
        <div class="user-profile">
            <div class="info-wrapper" id="sellersBlock">

            </div>
        </div>
        <ul class="navigation-menu main-nav-menu">
            <li class="nav-category-divider">ПЕРЕВОЗКИ</li>
            <li>
                <a href="/fleet">
                    <span class="link-title">Парк</span>
                    <i class="mdi mdi-truck link-icon"></i>
                </a>
            </li>
            <li>
                <a href="/drivers">
                    <span class="link-title">Водители</span>
                    <i class="mdi mdi-account-group-outline link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">График</span>
                    <i class="mdi mdi-calendar link-icon"></i>
                </a>
            </li>
            <li>
                <a href="/transport-reports">
                    <span class="link-title">Отчеты</span>
                    <i class="mdi mdi-file-chart link-icon"></i>
                </a>
            </li>
            <li>
                <a href="/driver-adjustments">
                    <span class="link-title">Надбавки / штрафы</span>
                    <i class="mdi mdi-cash link-icon"></i>
                </a>
            </li>
            <li class="nav-category-divider">МАГАЗИН</li>
            <li>
                <a href="#">
                    <span class="link-title">Заказы</span>
                    <i class="mdi mdi-receipt link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">Остатки</span>
                    <i class="mdi mdi-archive link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">Отгрузки</span>
                    <i class="mdi mdi-truck-delivery link-icon"></i>
                </a>
            </li>
            <li class="nav-category-divider">ТОВАРЫ</li>
            <li>
                <a href="/cards">
                    <span class="link-title">Все товары</span>
                    <i class="mdi mdi-package-variant-closed link-icon"></i>
                </a>
            </li>
            <li>
                <a href="/copycard">
                    <span class="link-title">Копирование карточки</span>
                    <i class="mdi mdi-content-copy link-icon"></i>
                </a>
            </li>
            <li>
                <a href="/competitorCards">
                    <span class="link-title">Товары конкурентов</span>
                    <i class="mdi mdi-sword-cross link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">Каталоги поставщиков</span>
                    <i class="mdi mdi-book-open-page-variant link-icon"></i>
                </a>
            </li>
            <li>
                <a href="/blockedCards">
                    <span class="link-title">Удаление карточек</span>
                    <i class="mdi mdi-delete-outline link-icon"></i>
                </a>
            </li>
        </ul>
    </div>
    @yield('content')
</div>
<x-sellers />
<x-suppliers />
<x-price-recalc />
<x-shops/>
<x-modal/>
<script src={{ url('assets/js/lodash.js') }}></script>
<script src={{ url('assets/vendors/js/core.js') }}></script>
<script src={{ url('assets/vendors/apexcharts/apexcharts.min.js') }}></script>
<script src={{ url('assets/vendors/chartjs/Chart.min.js') }}></script>
<script src={{ url('assets/js/charts/chartjs.addon.js') }}></script>
<script src={{ url('assets/js/template.js') }}></script>
<script src={{ url('assets/js/dashboard.js') }}></script>
<script src={{ url('assets/js/layouts/ajax.js') }}></script>
<script src={{ url('assets/js/layouts/modal.js') }}></script>
<script src={{ url('assets/js/layouts/events.js') }}></script>
<script src={{ url('assets/js/layouts/init.js') }}></script>
<script src={{ url('assets/js/layouts/app.js') }}></script>
@yield('js')
</body>
</html>
