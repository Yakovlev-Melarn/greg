<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

class DriverAdjustmentsController
{
    public function index(): Factory|View
    {
        return view('DriverAdjustments/index');
    }
}
