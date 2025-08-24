<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class IndexController
{
    public function index(Request $request)
    {
        if (!empty($request->get('seller'))) {
            session(['seller' => $request->get('seller')]);
        }
        return view('Index/index');
    }
}
