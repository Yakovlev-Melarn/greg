@extends('components.sellers')
@extends('layouts.app')
@section('content')
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
                                                 src="/assets/images/profile/male/image_1.png"
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
                                                 src="/assets/images/profile/male/image_1.png"
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
                                                 src="/assets/images/profile/male/image_1.png"
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
                                                 src="/assets/images/profile/male/image_1.png"
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
                                                 src="/assets/images/profile/male/image_1.png"
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
@endsection
@section('js')
    <script src="{{ asset('assets/js/Index/index.js') }}"></script>
@endsection
