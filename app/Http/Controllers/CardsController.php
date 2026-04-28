<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

class CardsController
{
    public function index(): Factory|View
    {
        return view('Cards/index');
    }
}
