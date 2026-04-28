<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

class CompetitorCardsController
{
    public function index(): Factory|View
    {
        return view('CompetitorCards/index');
    }
}
