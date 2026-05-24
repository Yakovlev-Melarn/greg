<?php

namespace App\Http\Controllers;

use App\Models\Cards;
use App\Models\Sellers;
use App\Models\SimaSupplierAuditRun;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;

class SimaSupplierAuditController
{
    public function index(): Factory|View
    {
        $sellerId = Session::get('seller');
        $currentSellerName = null;
        $simaCardsCount = 0;
        $pendingAuditCount = 0;
        $hasActiveRun = false;

        if ($sellerId) {
            $currentSellerName = Sellers::query()->whereKey($sellerId)->value('name');
            $simaCardsCount = Cards::query()
                ->where('sellerID', $sellerId)
                ->where('supplier', 20)
                ->count();

            if (Schema::hasColumn('skuMapping', 'user_blocked')) {
                $pendingAuditCount = Cards::query()
                    ->where('sellerID', $sellerId)
                    ->where('supplier', 20)
                    ->whereNotExists(function ($sub) {
                        $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                            ->from('skuMapping as sm')
                            ->whereColumn('sm.origSku', 'cards.vendorCode')
                            ->where('sm.user_blocked', true);
                    })
                    ->count();
            } else {
                $pendingAuditCount = $simaCardsCount;
            }

            $hasActiveRun = SimaSupplierAuditRun::hasActiveRunForSeller((int) $sellerId);
        }

        return view('SimaSupplierAudit/index', [
            'sellerId' => $sellerId,
            'currentSellerName' => $currentSellerName,
            'simaCardsCount' => $simaCardsCount,
            'pendingAuditCount' => $pendingAuditCount,
            'hasActiveRun' => $hasActiveRun,
        ]);
    }
}
