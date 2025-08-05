<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="token" content="{{ session()->get('token') }}">
    <meta name="csrf" content="{{ csrf_token() }}">
    <title>G.R.E.G.</title>
    <link rel="stylesheet" href="{{ url('/assets/vendors/iconfonts/mdi/css/materialdesignicons.css') }}">
    <link rel="stylesheet" href="{{ url('/assets/css/shared/style.css') }}">
    <link rel="stylesheet" href="{{ url('/assets/css/greg1/style.css') }}">
    <link rel="shortcut icon" href="{{ url('/assets/images/profile/male/image_1.png') }}"/>
    @viteReactRefresh
    @vite('resources/js/main.jsx')
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
            <div class="info-wrapper">
                <ul class="nav ml-auto justify-center">
                    <li class="nav-item dropdown">
                        <a href="#" class="user-name nav-link" id="appsDropdown" data-toggle="dropdown" aria-expanded="false">ООО "РИДИКОН"</a>
                        <div class="dropdown-menu navbar-dropdown dropdown-menu-right" aria-labelledby="appsDropdown">
                            <div class="dropdown-body border-top pt-0">
                                <a href="#" class="dropdown-grid w-100">
                                    <span class="grid-tittle">ИП "КУЗЬМИН В.В."</span>
                                </a>
                                <a href="#" class="dropdown-grid w-100">
                                    <span class="grid-tittle">ООО "МУЗА"</span>
                                </a>
                            </div>
                        </div>
                    </li>
                </ul>
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
                <a href="#">
                    <span class="link-title">Все товары</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
                <a href="#">
                    <span class="link-title">Копирование карточки</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
                <a href="#">
                    <span class="link-title">Товары конкурентов</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
                <a href="#">
                    <span class="link-title">Каталоги поставщиков</span>
                    <i class="mdi mdi-chart-donut link-icon"></i>
                </a>
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
                                    <p>30%</p>
                                    <p>+06.2%</p>
                                </div>
                                <p class="text-black">Traffic</p>
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
                                    <p>43%</p>
                                    <p>+15.7%</p>
                                </div>
                                <p class="text-black">Conversion</p>
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
                                    <p>23%</p>
                                    <p>+02.7%</p>
                                </div>
                                <p class="text-black">Bounce Rate</p>
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
                                    <p>75%</p>
                                    <p>- 53.34%</p>
                                </div>
                                <p class="text-black">Marketing</p>
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
                                            <img class="img-ss mt-1 mb-1 mr-2"
                                                 src="../assets/images/social-icons/instagram.svg" alt="instagram">
                                            <p class="card-title">Followers Growth</p>
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
                                        <h4>16.2K</h4>
                                        <p class="ml-2 text-muted">New Followers</p>
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
                                <p class="card-title">Campaign</p>
                                <div id="radial-chart"></div>
                                <h4 class="text-center">$23,350.00</h4>
                                <p class="text-center text-muted">Used balance this billing cycle</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 equel-grid">
                        <div class="grid table-responsive">
                            <table class="table table-stretched">
                                <thead>
                                <tr>
                                    <th>Symbol</th>
                                    <th>Price</th>
                                    <th>Change</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td>
                                        <p class="mb-n1 font-weight-medium">AAPL</p>
                                        <small class="text-gray">Apple Inc.</small>
                                    </td>
                                    <td class="font-weight-medium">198.18</td>
                                    <td class="text-danger font-weight-medium">
                                        <div class="badge badge-success">-1.39%</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <p class="mb-n1 font-weight-medium">NKE</p>
                                        <small class="text-gray">Nike,Inc.</small>
                                    </td>
                                    <td class="font-weight-medium">03.95</td>
                                    <td class="text-danger font-weight-medium">
                                        <div class="badge badge-danger">-1.17%</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <p class="mb-n1 font-weight-medium">NSEI</p>
                                        <small class="text-gray">Nifty 50</small>
                                    </td>
                                    <td class="font-weight-medium">11,278</td>
                                    <td class="text-danger font-weight-medium">
                                        <div class="badge badge-success">-0.24%</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <p class="mb-n1 font-weight-medium">BA</p>
                                        <small class="text-gray">The Boeing Company</small>
                                    </td>
                                    <td class="font-weight-medium">354.67</td>
                                    <td class="text-success font-weight-medium">
                                        <div class="badge badge-success">+0.15%</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <p class="mb-n1 font-weight-medium">SBUX</p>
                                        <small class="text-gray">Starbucks Corporation</small>
                                    </td>
                                    <td class="font-weight-medium">08.42</td>
                                    <td class="text-success font-weight-medium">
                                        <div class="badge badge-success">+0.67%</div>
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
                                    <p class="card-title">Available Balance</p>
                                    <span class="btn action-btn btn-xs component-flat" data-toggle="tooltip"
                                          data-placement="left" title="Available balance since the last week">
                        <i class="mdi mdi-information-outline text-muted mdi-2x"></i>
                      </span>
                                </div>
                                <div class="d-flex align-items-end mt-2">
                                    <h3>26.00453100</h3>
                                    <p class="ml-1 font-weight-bold">BTC</p>
                                </div>
                                <div class="d-flex mt-2">
                                    <div class="wrapper d-flex pr-4">
                                        <small class="text-success font-weight-medium mr-2">USD</small>
                                        <small class="text-gray">$103,342.50</small>
                                    </div>
                                    <div class="wrapper d-flex">
                                        <small class="text-primary font-weight-medium mr-2">EUR</small>
                                        <small class="text-gray">$91,105.00</small>
                                    </div>
                                </div>
                                <div class="d-flex flex-row mt-4 mb-4">
                                    <button class="btn btn-outline-light text-gray component-flat w-50 mr-2"
                                            type="button">SEND
                                    </button>
                                    <button class="btn btn-primary w-50 ml-2" type="button">RECEIVE</button>
                                </div>
                                <div class="d-flex mt-5 mb-3">
                                    <small class="mb-0 text-primary">Recent Transaction (3)</small>
                                </div>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <p class="text-black">Received Bitcoin</p>
                                    <p class="text-gray">+0.00005462 BTC</p>
                                </div>
                                <div class="d-flex justify-content-between border-bottom py-2">
                                    <p class="text-black">Sent Bitcoin</p>
                                    <p class="text-gray">-0.00001446 BTC</p>
                                </div>
                                <div class="d-flex justify-content-between pt-2">
                                    <p class="text-black">Sent Bitcoin</p>
                                    <p class="text-gray">-0.00003573 BTC</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7 col-md-12 equel-grid">
                        <div class="grid widget-revenue-card">
                            <div class="grid-body d-flex flex-column h-100">
                                <div class="split-header">
                                    <p class="card-title">Server Load</p>
                                    <div class="content-wrapper v-centered">
                                        <small class="text-muted">2h ago</small>
                                        <span class="btn action-btn btn-refresh btn-xs component-flat">
                          <i class="mdi mdi-autorenew text-muted mdi-2x"></i>
                        </span>
                                    </div>
                                </div>
                                <div class="mt-auto">
                                    <canvas id="cpu-performance" height="80"></canvas>
                                    <h3 class="font-weight-medium mt-4">69.05%</h3>
                                    <p class="text-gray">Storage is getting full</p>
                                    <div class="w-50">
                                        <div class="d-flex justify-content-between text-muted mt-3">
                                            <small>Usage</small> <small>35.62 GB / 2TB</small>
                                        </div>
                                        <div class="progress progress-slim mt-2">
                                            <div class="progress-bar bg-primary" role="progressbar" style="width: 68%"
                                                 aria-valuenow="68" aria-valuemin="0" aria-valuemax="100">
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
                                <p class="card-title ml-n1">Order History</p>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead>
                                    <tr class="solid-header">
                                        <th colspan="2" class="pl-4">Customer</th>
                                        <th>Order No</th>
                                        <th>Purchased On</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <tr>
                                        <td class="pr-0 pl-4">
                                            <img class="profile-img img-sm"
                                                 src="../assets/images/profile/male/image_4.png" alt="profile image">
                                        </td>
                                        <td class="pl-md-0">
                                            <small class="text-black font-weight-medium d-block">Barbara Curtis</small>
                                            <span class="text-gray">
                              <span class="status-indicator rounded-indicator small bg-primary"></span>Account Deactivated </span>
                                        </td>
                                        <td>
                                            <small>8523537435</small>
                                        </td>
                                        <td> Just Now</td>
                                    </tr>
                                    <tr>
                                        <td class="pr-0 pl-4">
                                            <img class="profile-img img-sm"
                                                 src="../assets/images/profile/male/image_3.png" alt="profile image">
                                        </td>
                                        <td class="pl-md-0">
                                            <small class="text-black font-weight-medium d-block">Charlie Hawkins</small>
                                            <span class="text-gray">
                              <span
                                  class="status-indicator rounded-indicator small bg-success"></span>Email Verified </span>
                                        </td>
                                        <td>
                                            <small>9537537436</small>
                                        </td>
                                        <td> Mar 04, 2018 11:37am</td>
                                    </tr>
                                    <tr>
                                        <td class="pr-0 pl-4">
                                            <img class="profile-img img-sm"
                                                 src="../assets/images/profile/female/image_2.png" alt="profile image">
                                        </td>
                                        <td class="pl-md-0">
                                            <small class="text-black font-weight-medium d-block">Nina Bates</small>
                                            <span class="text-gray">
                              <span class="status-indicator rounded-indicator small bg-warning"></span>Payment On Hold </span>
                                        </td>
                                        <td>
                                            <small>7533567437</small>
                                        </td>
                                        <td> Mar 13, 2018 9:41am</td>
                                    </tr>
                                    <tr>
                                        <td class="pr-0 pl-4">
                                            <img class="profile-img img-sm"
                                                 src="../assets/images/profile/male/image_10.png" alt="profile image">
                                        </td>
                                        <td class="pl-md-0">
                                            <small class="text-black font-weight-medium d-block">Hester Richards</small>
                                            <span class="text-gray">
                              <span
                                  class="status-indicator rounded-indicator small bg-success"></span>Email Verified </span>
                                        </td>
                                        <td>
                                            <small>5673467743</small>
                                        </td>
                                        <td> Feb 21, 2018 8:34am</td>
                                    </tr>
                                    </tbody>
                                </table>
                            </div>
                            <a class="border-top px-3 py-2 d-block text-gray" href="#">
                                <small class="font-weight-medium"><i class="mdi mdi-chevron-down mr-2"></i>View All
                                    Order History</small>
                            </a>
                        </div>
                    </div>
                    <div class="col-md-4 equel-grid">
                        <div class="grid">
                            <div class="grid-body">
                                <div class="split-header">
                                    <p class="card-title">Activity Log</p>
                                    <div class="btn-group">
                                        <button type="button"
                                                class="btn btn-trasnparent action-btn btn-xs component-flat pr-0"
                                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                            <i class="mdi mdi-dots-vertical"></i>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-right">
                                            <a class="dropdown-item" href="#">Expand View</a>
                                            <a class="dropdown-item" href="#">Edit</a>
                                        </div>
                                    </div>
                                </div>
                                <div class="vertical-timeline-wrapper">
                                    <div class="timeline-vertical dashboard-timeline">
                                        <div class="activity-log">
                                            <p class="log-name">Agnes Holt</p>
                                            <div class="log-details">Analytics dashboard has been created<span
                                                    class="text-primary ml-1">#Slack</span></div>
                                            <small class="log-time">8 mins Ago</small>
                                        </div>
                                        <div class="activity-log">
                                            <p class="log-name">Ronald Edwards</p>
                                            <div class="log-details">Report has been updated
                                                <div class="grouped-images mt-2">
                                                    <img class="img-sm" src="../assets/images/profile/male/image_4.png"
                                                         alt="Profile Image" data-toggle="tooltip"
                                                         title="Gerald Pierce">
                                                    <img class="img-sm" src="../assets/images/profile/male/image_5.png"
                                                         alt="Profile Image" data-toggle="tooltip"
                                                         title="Edward Wilson">
                                                    <img class="img-sm"
                                                         src="../assets/images/profile/female/image_6.png"
                                                         alt="Profile Image" data-toggle="tooltip"
                                                         title="Billy Williams">
                                                    <img class="img-sm" src="../assets/images/profile/male/image_6.png"
                                                         alt="Profile Image" data-toggle="tooltip"
                                                         title="Lelia Hampton">
                                                    <span class="plus-text img-sm">+3</span>
                                                </div>
                                            </div>
                                            <small class="log-time">3 Hours Ago</small>
                                        </div>
                                        <div class="activity-log">
                                            <p class="log-name">Charlie Newton</p>
                                            <div class="log-details"> Approved your request
                                                <div class="wrapper mt-2">
                                                    <button type="button" class="btn btn-xs btn-primary">Approve
                                                    </button>
                                                    <button type="button" class="btn btn-xs btn-inverse-primary">
                                                        Reject
                                                    </button>
                                                </div>
                                            </div>
                                            <small class="log-time">2 Hours Ago</small>
                                        </div>
                                        <div class="activity-log">
                                            <p class="log-name">Gussie Page</p>
                                            <div class="log-details">Added new task: Slack home page</div>
                                            <small class="log-time">4 Hours Ago</small>
                                        </div>
                                        <div class="activity-log">
                                            <p class="log-name">Ina Mendoza</p>
                                            <div class="log-details">Added new images</div>
                                            <small class="log-time">8 Hours Ago</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <a class="border-top px-3 py-2 d-block text-gray" href="#">
                                <small class="font-weight-medium"><i class="mdi mdi-chevron-down mr-2"></i> View All
                                </small>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <footer class="footer">
            <div class="row">
                <div class="col-sm-6 text-center text-sm-right order-sm-1">
                    <ul class="text-gray">
                        <li><a href="#">Terms of use</a></li>
                        <li><a href="#">Privacy Policy</a></li>
                    </ul>
                </div>
                <div class="col-sm-6 text-center text-sm-left mt-3 mt-sm-0">
                    <small class="text-muted d-block">Copyright © 2019 <a href="http://www.uxcandy.co" target="_blank">UXCANDY</a>.
                        All rights reserved</small>
                    <small class="text-gray mt-2">Handcrafted With <i class="mdi mdi-heart text-danger"></i></small>
                </div>
            </div>
        </footer>
    </div>
</div>
<script src="../assets/vendors/js/core.js"></script>
<script src="../assets/vendors/apexcharts/apexcharts.min.js"></script>
<script src="../assets/vendors/chartjs/Chart.min.js"></script>
<script src="../assets/js/charts/chartjs.addon.js"></script>
<script src="../assets/js/template.js"></script>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
