<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="token" content="{{ session()->get('token') }}">
    <meta name="csrf" content="{{ csrf_token() }}">
    <meta name="sellerId" content="{{ session()->get('seller') }}">
    <title>G.R.E.G.</title>
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
                            <a class="dropdown-grid">
                                <i class="grid-icon mdi mdi-jira mdi-2x"></i>
                                <span class="grid-tittle">Магазины</span>
                            </a>
                            <a class="dropdown-grid">
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
                <x-sellers />
            </div>
        </div>
        <ul class="navigation-menu">
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
                <a href="#" data-toggle="modal" data-target="#windowModal">
                    <span class="link-title">Все товары</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
                    <span class="link-title">Копирование карточки</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
            </li>
            <li>
                <a href="#">
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
                <a href="#">
                    <span class="link-title">Удаление карточек</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
            </li>
        </ul>
    </div>
    <div class="page-content-wrapper">
        <div class="page-content-wrapper-inner">
            <div class="content-viewport">
                <div class="row">
                    <div class="col-md-3 col-sm-6 col-6 equel-grid">
                        <div class="grid">
                            <div class="grid-body text-gray">
                                <div class="d-flex justify-content-between">
                                    <p>5 шт.</p>
                                    <p>15700 руб.</p>
                                </div>
                                <p class="text-black">Заказано</p>
                                <div class="wrapper w-50 mt-4">
                                    <canvas height="45" id="stat-line_1"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 col-6 equel-grid">
                        <div class="grid">
                            <div class="grid-body text-gray">
                                <div class="d-flex justify-content-between">
                                    <p>3 шт.</p>
                                    <p>1570 руб.</p>
                                </div>
                                <p class="text-black">Выкуплено</p>
                                <div class="wrapper w-50 mt-4">
                                    <canvas height="45" id="stat-line_2"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 col-6 equel-grid">
                        <div class="grid">
                            <div class="grid-body text-gray">
                                <div class="d-flex justify-content-between">
                                    <p>1 шт.</p>
                                    <p>740 руб.</p>
                                </div>
                                <p class="text-black">В пути к клиенту</p>
                                <div class="wrapper w-50 mt-4">
                                    <canvas height="45" id="stat-line_3"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 col-6 equel-grid">
                        <div class="grid">
                            <div class="grid-body text-gray">
                                <div class="d-flex justify-content-between">
                                    <p>5 шт.</p>
                                    <p>5334 руб.</p>
                                </div>
                                <p class="text-black">В пути от клиента</p>
                                <div class="wrapper w-50 mt-4">
                                    <canvas height="45" id="stat-line_4"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 equel-grid">
                        <div class="grid">
                            <div class="grid-body d-flex flex-column h-100">
                                <div class="wrapper">
                                    <div class="d-flex justify-content-between">
                                        <div class="split-header">
                                            <p class="card-title">Остатки на складах</p>
                                        </div>
                                        <div class="wrapper">
                                            <button class="btn action-btn btn-xs component-flat pr-0" type="button"><i
                                                    class="mdi mdi-access-point text-muted mdi-2x"></i></button>
                                            <button class="btn action-btn btn-xs component-flat pr-0" type="button"><i
                                                    class="mdi mdi-cloud-download-outline text-muted mdi-2x"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-end pt-2 mb-4">
                                        <h4>202563 руб.</h4>
                                        <p class="ml-2 text-muted">101 шт.</p>
                                    </div>
                                </div>
                                <div class="mt-auto">
                                    <canvas class="curved-mode" id="followers-bar-chart" height="220"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 equel-grid">
                        <div class="grid">
                            <div class="grid-body">
                                <p class="card-title">Карточки товара</p>
                                <div id="radial-chart"></div>
                                <h4 class="text-center">5360 шт. / 8000 шт.</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 equel-grid">
                        <div class="grid table-responsive">
                            <table class="table table-stretched">
                                <thead>
                                <tr>
                                    <th>Товар</th>
                                    <th>Цена</th>
                                    <th>Продажи</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td>
                                        <p class="mb-n1 font-weight-medium"><a href="#">489363920</a></p>
                                        <small class="text-gray">Люстра "авиталь"</small>
                                    </td>
                                    <td class="font-weight-medium">24568</td>
                                    <td class="text-danger font-weight-medium">
                                        <div class="badge badge-success">1720 шт.</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <p class="mb-n1 font-weight-medium"><a href="#">489363920</a></p>
                                        <small class="text-gray">Люстра "авиталь"</small>
                                    </td>
                                    <td class="font-weight-medium">24568</td>
                                    <td class="text-danger font-weight-medium">
                                        <div class="badge badge-success">1720 шт.</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <p class="mb-n1 font-weight-medium"><a href="#">489363920</a></p>
                                        <small class="text-gray">Люстра "авиталь"</small>
                                    </td>
                                    <td class="font-weight-medium">24568</td>
                                    <td class="text-danger font-weight-medium">
                                        <div class="badge badge-success">1720 шт.</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <p class="mb-n1 font-weight-medium"><a href="#">489363920</a></p>
                                        <small class="text-gray">Люстра "авиталь"</small>
                                    </td>
                                    <td class="font-weight-medium">24568</td>
                                    <td class="text-danger font-weight-medium">
                                        <div class="badge badge-success">1720 шт.</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <p class="mb-n1 font-weight-medium"><a href="#">489363920</a></p>
                                        <small class="text-gray">Люстра "авиталь"</small>
                                    </td>
                                    <td class="font-weight-medium">24568</td>
                                    <td class="text-danger font-weight-medium">
                                        <div class="badge badge-success">1720 шт.</div>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-5 col-md-6 col-sm-12 equel-grid">
                        <div class="grid">
                            <div class="grid-body">
                                <div class="split-header">
                                    <p class="card-title">Баланс</p>
                                    <span class="btn action-btn btn-xs component-flat" data-toggle="tooltip"
                                          data-placement="left" title="Available balance since the last week">
                        <i class="mdi mdi-information-outline text-muted mdi-2x"></i>
                      </span>
                                </div>
                                <div class="d-flex align-items-end mt-2">
                                    <h3>- 140850</h3>
                                    <p class="ml-1 font-weight-bold">РУБ</p>
                                </div>
                                <div class="d-flex mt-2">
                                    <div class="wrapper d-flex pr-4">
                                        <small class="text-success font-weight-medium mr-2">Доступно к выводу</small>
                                        <small class="text-gray">3,34 руб.</small>
                                    </div>
                                </div>
                                <div class="d-flex mt-5 mb-3">
                                    <small class="mb-0 text-primary">Документы</small>
                                </div>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <p class="text-black">УПД</p>
                                    <p class="text-gray"><a href="#">Отчет за период 01.01.2026 - 07.01.2026</a></p>
                                </div>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <p class="text-black">УПД</p>
                                    <p class="text-gray"><a href="#">Отчет за период 01.01.2026 - 07.01.2026</a></p>
                                </div>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <p class="text-black">УПД</p>
                                    <p class="text-gray"><a href="#">Отчет за период 01.01.2026 - 07.01.2026</a></p>
                                </div>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <p class="text-black">УПД</p>
                                    <p class="text-gray"><a href="#">Отчет за период 01.01.2026 - 07.01.2026</a></p>
                                </div>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <p class="text-black">УПД</p>
                                    <p class="text-gray"><a href="#">Отчет за период 01.01.2026 - 07.01.2026</a></p>
                                </div>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <p class="text-black">УПД</p>
                                    <p class="text-gray"><a href="#">Отчет за период 01.01.2026 - 07.01.2026</a></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7 col-md-12 equel-grid">
                        <div class="grid widget-revenue-card">
                            <div class="grid-body d-flex flex-column h-100">
                                <div class="split-header">
                                    <p class="card-title">Товары без остатка</p>
                                </div>
                                <div class="mt-auto">
                                    <canvas id="cpu-performance" height="80"></canvas>
                                    <h3 class="font-weight-medium mt-4">9%</h3>
                                    <div class="w-50">
                                        <div class="d-flex justify-content-between text-muted mt-3">
                                            <small>Не продавались за последние 7 дней</small> <small>1810 шт.</small>
                                        </div>
                                        <div class="progress progress-slim mt-2">
                                            <div class="progress-bar bg-primary" role="progressbar" style="width: 28%"
                                                 aria-valuenow="28" aria-valuemin="0" aria-valuemax="100">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8 equel-grid">
                        <div class="grid">
                            <div class="grid-body py-3">
                                <p class="card-title ml-n1">Последние заказы</p>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead>
                                    <tr class="solid-header">
                                        <th colspan="2" class="pl-4">Товар</th>
                                        <th>Номер заказа</th>
                                        <th>Заказано</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <tr>
                                        <td class="pr-0 pl-4">
                                            <img class="profile-img img-sm"
                                                 src="https://basket-12.wbbasket.ru/vol1769/part176901/176901881/images/tm/1.webp"
                                                 alt="profile image">
                                        </td>
                                        <td class="pl-md-0">
                                            <small class="text-black font-weight-medium d-block">Плакат учебный
                                                "Арифметические действия"</small>
                                            <span class="text-gray">
                              <span class="status-indicator rounded-indicator small bg-primary"></span>182 руб.</span>
                                        </td>
                                        <td>
                                            <small>3689483382</small>
                                        </td>
                                        <td>02.08.2025</td>
                                    </tr>
                                    <tr>
                                        <td class="pr-0 pl-4">
                                            <img class="profile-img img-sm"
                                                 src="https://basket-12.wbbasket.ru/vol1769/part176901/176901881/images/tm/1.webp"
                                                 alt="profile image">
                                        </td>
                                        <td class="pl-md-0">
                                            <small class="text-black font-weight-medium d-block">Плакат учебный
                                                "Арифметические действия"</small>
                                            <span class="text-gray">
                              <span class="status-indicator rounded-indicator small bg-primary"></span>182 руб.</span>
                                        </td>
                                        <td>
                                            <small>3689483382</small>
                                        </td>
                                        <td>02.08.2025</td>
                                    </tr>
                                    <tr>
                                        <td class="pr-0 pl-4">
                                            <img class="profile-img img-sm"
                                                 src="https://basket-12.wbbasket.ru/vol1769/part176901/176901881/images/tm/1.webp"
                                                 alt="profile image">
                                        </td>
                                        <td class="pl-md-0">
                                            <small class="text-black font-weight-medium d-block">Плакат учебный
                                                "Арифметические действия"</small>
                                            <span class="text-gray">
                              <span class="status-indicator rounded-indicator small bg-primary"></span>182 руб.</span>
                                        </td>
                                        <td>
                                            <small>3689483382</small>
                                        </td>
                                        <td>02.08.2025</td>
                                    </tr>
                                    <tr>
                                        <td class="pr-0 pl-4">
                                            <img class="profile-img img-sm"
                                                 src="https://basket-12.wbbasket.ru/vol1769/part176901/176901881/images/tm/1.webp"
                                                 alt="profile image">
                                        </td>
                                        <td class="pl-md-0">
                                            <small class="text-black font-weight-medium d-block">Плакат учебный
                                                "Арифметические действия"</small>
                                            <span class="text-gray">
                              <span class="status-indicator rounded-indicator small bg-primary"></span>182 руб.</span>
                                        </td>
                                        <td>
                                            <small>3689483382</small>
                                        </td>
                                        <td>02.08.2025</td>
                                    </tr>
                                    <tr>
                                        <td class="pr-0 pl-4">
                                            <img class="profile-img img-sm"
                                                 src="https://basket-12.wbbasket.ru/vol1769/part176901/176901881/images/tm/1.webp"
                                                 alt="profile image">
                                        </td>
                                        <td class="pl-md-0">
                                            <small class="text-black font-weight-medium d-block">Плакат учебный
                                                "Арифметические действия"</small>
                                            <span class="text-gray">
                              <span class="status-indicator rounded-indicator small bg-primary"></span>182 руб.</span>
                                        </td>
                                        <td>
                                            <small>3689483382</small>
                                        </td>
                                        <td>02.08.2025</td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                            <a class="border-top px-3 py-2 d-block text-gray" href="#">
                                <small class="font-weight-medium"><i class="mdi mdi-chevron-down mr-2"></i>Все
                                    заказы</small>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-4 equel-grid">
                        <div class="grid">
                            <div class="grid-body">
                                <div class="split-header">
                                    <p class="card-title">Последние задачи</p>
                                    <div class="btn-group">
                                        <button type="button"
                                                class="btn btn-trasnparent action-btn btn-xs component-flat pr-0"
                                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                            <i class="mdi mdi-dots-vertical"></i>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right">
                                            <a class="dropdown-item" href="#">Успешые</a>
                                            <a class="dropdown-item" href="#">С ошибками</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="vertical-timeline-wrapper">
                                    <div class="timeline-vertical dashboard-timeline">
                                        <div class="activity-log">
                                            <p class="log-name">Остатки</p>
                                            <div class="log-details">на складе WB<span
                                                    class="text-primary ml-1">#что изменилось</span></div>
                                            <small class="log-time">07.08.2025 14:38:17</small>
                                        </div>

                                    </div>
                                </div>
                                <div class="vertical-timeline-wrapper">
                                    <div class="timeline-vertical dashboard-timeline">
                                        <div class="activity-log">
                                            <p class="log-name">Остатки</p>
                                            <div class="log-details">на складе WB<span
                                                    class="text-primary ml-1">#что изменилось</span></div>
                                            <small class="log-time">07.08.2025 14:38:17</small>
                                        </div>

                                    </div>
                                </div>
                                <div class="vertical-timeline-wrapper">
                                    <div class="timeline-vertical dashboard-timeline">
                                        <div class="activity-log">
                                            <p class="log-name">Остатки</p>
                                            <div class="log-details">на складе WB<span
                                                    class="text-primary ml-1">#что изменилось</span></div>
                                            <small class="log-time">07.08.2025 14:38:17</small>
                                        </div>

                                    </div>
                                </div>
                                <div class="vertical-timeline-wrapper">
                                    <div class="timeline-vertical dashboard-timeline">
                                        <div class="activity-log">
                                            <p class="log-name">Остатки</p>
                                            <div class="log-details">на складе WB<span
                                                    class="text-primary ml-1">#что изменилось</span></div>
                                            <small class="log-time">07.08.2025 14:38:17</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="vertical-timeline-wrapper">
                                    <div class="timeline-vertical dashboard-timeline">
                                        <div class="activity-log">
                                            <p class="log-name">Остатки</p>
                                            <div class="log-details">на складе WB<span
                                                    class="text-primary ml-1">#что изменилось</span></div>
                                            <small class="log-time">07.08.2025 14:38:17</small>
                                        </div>

                                    </div>
                                </div>
                            </div>
                            <a class="border-top px-3 py-2 d-block text-gray" href="#">
                                <small class="font-weight-medium"><i class="mdi mdi-chevron-down mr-2"></i> Показать все
                                </small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <footer class="footer">
            <div class="row">
                <div class="col-sm-6 text-center text-sm-left mt-3 mt-sm-0">
                    <small class="text-muted d-block">Copyright © 2025 mr. Gregory.</small>
                </div>
            </div>
        </footer>
    </div>
</div>
<div class="modal fade" id="windowModal" tabindex="-1" role="dialog"
     aria-labelledby="myLargeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" style="max-width: 90%">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="exampleModalLabel">New message</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form>
                    <div class="form-group">
                        <label for="recipient-name" class="col-form-label">Recipient:</label>
                        <input type="text" class="form-control" id="recipient-name">
                    </div>
                    <div class="form-group">
                        <label for="message-text" class="col-form-label">Message:</label>
                        <textarea class="form-control" id="message-text"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary">Send message</button>
            </div>
        </div>
    </div>
</div>
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
