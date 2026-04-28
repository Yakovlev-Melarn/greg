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
            <form action="#" class="t-header-search-box">
                <div class="input-group">
                    <input type="text" class="form-control" id="inlineFormInputGroup" placeholder="Поиск"
                           autocomplete="off">
                    <button class="btn btn-primary" type="submit"><i class="mdi mdi-arrow-right-thick"></i></button>
                </div>
            </form>
            <ul class="nav ml-auto">
                <li class="nav-item dropdown">
                    <a class="nav-link" href="#" id="notificationDropdown" data-toggle="dropdown" aria-expanded="false">
                        <i class="mdi mdi-bell-outline mdi-1x"></i>
                    </a>
                    <div class="dropdown-menu navbar-dropdown dropdown-menu-right"
                         aria-labelledby="notificationDropdown">
                        <div class="dropdown-header">
                            <h6 class="dropdown-title">Уведомления</h6>
                            <p class="dropdown-title-text">Новых уведомлений: 4</p>
                        </div>
                        <div class="dropdown-body">
                            <div class="dropdown-list">
                                <div class="icon-wrapper rounded-circle bg-inverse-primary text-primary">
                                    <i class="mdi mdi-alert"></i>
                                </div>
                                <div class="content-wrapper">
                                    <small class="name">Поставка переполнена</small>
                                    <small class="content-text">более 50 заказов</small>
                                </div>
                            </div>
                            <div class="dropdown-list">
                                <div class="icon-wrapper rounded-circle bg-inverse-success text-success">
                                    <i class="mdi mdi-cloud-upload"></i>
                                </div>
                                <div class="content-wrapper">
                                    <small class="name">Синхронизация</small>
                                    <small class="content-text">успешно завершена</small>
                                </div>
                            </div>
                            <div class="dropdown-list">
                                <div class="icon-wrapper rounded-circle bg-inverse-warning text-warning">
                                    <i class="mdi mdi-security"></i>
                                </div>
                                <div class="content-wrapper">
                                    <small class="name">API ключ</small>
                                    <small class="content-text">заканчивается 10.01.2026</small>
                                </div>
                            </div>
                        </div>
                        <div class="dropdown-footer">
                            <a href="#">Смотреть все</a>
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
                    <div class="dropdown-menu navbar-dropdown dropdown-menu-right" aria-labelledby="appsDropdown">
                        <div class="dropdown-header">
                            <h6 class="dropdown-title">Настройки и утилиты</h6>
                        </div>
                        <div class="dropdown-body border-top pt-0">
                            <a class="dropdown-grid" id="sellersModal">
                                <i class="grid-icon mdi mdi-jira mdi-2x"></i>
                                <span class="grid-tittle">Магазины</span>
                            </a>
                            <a class="dropdown-grid" id="suppliersModal">
                                <i class="grid-icon mdi mdi-barcode mdi-2x"></i>
                                <span class="grid-tittle">Поставщики</span>
                            </a>
                            <a class="dropdown-grid">
                                <i class="grid-icon mdi mdi-artstation mdi-2x"></i>
                                <span class="grid-tittle">Конкуренты</span>
                            </a>
                            <a class="dropdown-grid">
                                <i class="grid-icon mdi mdi-circle mdi-2x"></i>
                                <span class="grid-tittle">Процессы</span>
                            </a>
                            <a class="dropdown-grid">
                                <i class="grid-icon mdi mdi-trello mdi-2x"></i>
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
        <ul class="navigation-menu">
            <li class="nav-category-divider">ПЕРЕВОЗКИ</li>
            <li>
                <a href="#">
                    <span class="link-title">Парк</span>
                    <i class="mdi mdi-truck link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">Водители</span>
                    <i class="mdi mdi-account link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">График</span>
                    <i class="mdi mdi-calendar-clock link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">Отчеты</span>
                    <i class="mdi mdi-file-chart link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">Надбавки / штрафы</span>
                    <i class="mdi mdi-currency-rub link-icon"></i>
                </a>
            </li>
            <li class="nav-category-divider">МАГАЗИН</li>
            <li>
                <a href="#">
                    <span class="link-title">Заказы</span>
                    <i class="mdi mdi-clipboard-outline link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">Остатки</span>
                    <i class="mdi mdi-clipboard-outline link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">Отгрузки</span>
                    <i class="mdi mdi-clipboard-outline link-icon"></i>
                </a>
            </li>
            <li class="nav-category-divider">ТОВАРЫ</li>
            <li>
                <a href="/cards">
                    <span class="link-title">Все товары</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
            </li>
            <li>
                <a href="/copycard">
                    <span class="link-title">Копирование карточки</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
            </li>
            <li>
                <a href="/competitorCards">
                    <span class="link-title">Товары конкурентов</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">Каталоги поставщиков</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
            </li>
            <li>
                <a href="/blockedCards">
                    <span class="link-title">Удаление карточек</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
            </li>
        </ul>
    </div>
    @yield('content')
</div>
<x-sellers />
<x-suppliers />
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
