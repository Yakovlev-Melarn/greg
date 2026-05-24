<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BlockedCards as BlockedCardsAlias;
use App\Http\Controllers\Api\Cards as CardsAlias;
use App\Http\Controllers\Api\CloneProducts as CloneProductsAlias;
use App\Http\Controllers\Api\DriverAdjustments as DriverAdjustmentsAlias;
use App\Http\Controllers\Api\DriverDailyReports as DriverDailyReportsAlias;
use App\Http\Controllers\Api\Drivers as DriversAlias;
use App\Http\Controllers\Api\Fleet as FleetAlias;
use App\Http\Controllers\Api\Seller as SellerAlias;
use App\Http\Controllers\Api\SimaSupplierAudit as SimaSupplierAuditAlias;
use App\Http\Controllers\Api\SkuMapping as SkuMappingApi;
use App\Http\Controllers\Api\Suppliers as SuppliersAlias;
use App\Http\Controllers\Api\SystemNotifications as SystemNotificationsApi;
use App\Http\Controllers\Api\TransportCompanies as TransportCompaniesAlias;
use App\Http\Controllers\Api\TransportFinance as TransportFinanceAlias;
use Illuminate\Http\Request;

class Api
{
    private array $supportedEntities = [
        'sellers' => SellerAlias::class,
        'cards' => CardsAlias::class,
        'blocked-cards' => BlockedCardsAlias::class,
        'suppliers' => SuppliersAlias::class,
        'clone-products' => CloneProductsAlias::class,
        'sima-supplier-audit' => SimaSupplierAuditAlias::class,
        'sku-mapping' => SkuMappingApi::class,
        'system-notifications' => SystemNotificationsApi::class,
        'fleet' => FleetAlias::class,
        'drivers' => DriversAlias::class,
        'driver-daily-reports' => DriverDailyReportsAlias::class,
        'driver-adjustments' => DriverAdjustmentsAlias::class,
        'transport-companies' => TransportCompaniesAlias::class,
        'transport-finance' => TransportFinanceAlias::class,
    ];

    public function index(string $entity, string $method, Request $request): mixed
    {
        if (! isset($this->supportedEntities[$entity])) {
            return ['error' => true, 'message' => "$entity is not supported"];
        }
        $entity = new $this->supportedEntities[$entity];

        return $entity->{$method}($request);
    }
}
