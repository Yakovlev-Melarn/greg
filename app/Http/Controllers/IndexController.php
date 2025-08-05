<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class IndexController
{
    public function index(Request $request)
    {
        return view('Index/index');
    }
}
