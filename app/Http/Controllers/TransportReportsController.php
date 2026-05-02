<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

class TransportReportsController
{
    public function index(): Factory|View
    {
        return view('TransportReports/index');
    }
}
