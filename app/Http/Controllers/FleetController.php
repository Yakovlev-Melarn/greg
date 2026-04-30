<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

class FleetController
{
    public function index(): Factory|View
    {
        return view('Fleet/index');
    }
}
