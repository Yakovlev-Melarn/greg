<?php

namespace App\Http\Middleware;

use App\Models\Sellers;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SessionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->session()->has('seller')) {
            $seller = Sellers::all()->first();
            $request->session()->put('seller', $seller->id);
        }
        if (!empty($request->get('seller'))) {
            $request->session()->put('seller', $request->get('seller'));
            return redirect('/');
        }
        return $next($request);
    }
}
