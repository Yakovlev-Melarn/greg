<?php

namespace App\Http\Controllers\Api;

use App\Models\Driver;
use App\Models\DriverAdjustment;
use App\Models\DriverAdjustmentPart;
use App\Models\DriverDailyReport;
use App\Models\DriverPayout;
use App\Models\FleetVehicle;
use App\Models\Logistician;
use App\Models\LogisticianPayout;
use App\Models\VehicleExpense;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransportFinance
{
    private const DRIVER_SHARE = 0.45;

    private const LOGISTICIAN_POLICY_START_DATE = '2026-03-15';

    public function weeklySummary(Request $request): array
    {
        [$monday, $sunday] = $this->validatedWeekRange($request);

        $mondayStr = $monday->toDateString();
        $sundayStr = $sunday->toDateString();

        $routeTotal = (float) DriverDailyReport::query()
            ->whereDate('report_date', '>=', $mondayStr)
            ->whereDate('report_date', '<=', $sundayStr)
            ->sum(DB::raw('COALESCE(route_sheet_total, 0)'));

        $routeByDriver = DriverDailyReport::query()
            ->whereDate('report_date', '>=', $mondayStr)
            ->whereDate('report_date', '<=', $sundayStr)
            ->selectRaw('driver_id, SUM(COALESCE(route_sheet_total, 0)) as route_total')
            ->groupBy('driver_id')
            ->pluck('route_total', 'driver_id');

        $bonusByDriver = DriverAdjustment::query()
            ->where('adjustment_type', 'bonus')
            ->whereDate('event_date', '>=', $mondayStr)
            ->whereDate('event_date', '<=', $sundayStr)
            ->selectRaw('driver_id, SUM(total_amount) as total')
            ->groupBy('driver_id')
            ->pluck('total', 'driver_id');

        $penaltyByDriver = DriverAdjustmentPart::query()
            ->join('driver_adjustments', 'driver_adjustment_parts.driver_adjustment_id', '=', 'driver_adjustments.id')
            ->where('driver_adjustments.adjustment_type', 'penalty')
            ->whereDate('driver_adjustment_parts.due_date', '>=', $mondayStr)
            ->whereDate('driver_adjustment_parts.due_date', '<=', $sundayStr)
            ->selectRaw('driver_adjustments.driver_id, SUM(driver_adjustment_parts.amount) as total')
            ->groupBy('driver_adjustments.driver_id')
            ->pluck('total', 'driver_id');

        $driverIds = collect([$routeByDriver->keys(), $bonusByDriver->keys(), $penaltyByDriver->keys()])
            ->flatten()
            ->unique()
            ->filter()
            ->values();
        $carryDriverIds = $this->driverIdsWithCarryOver($mondayStr);
        $driverIds = $driverIds->merge($carryDriverIds)->unique()->values();

        $payoutsByDriver = DriverPayout::query()
            ->whereDate('week_monday', $mondayStr)
            ->get()
            ->keyBy('driver_id');

        $driversOut = [];
        $driversPayroll = 0.0;

        $drivers = $driverIds->isEmpty()
            ? collect()
            : Driver::query()->whereIn('id', $driverIds)->get()->keyBy('id');

        foreach ($driverIds as $driverId) {
            $route = $this->round2((float) ($routeByDriver[$driverId] ?? 0));
            $bonus = $this->round2((float) ($bonusByDriver[$driverId] ?? 0));
            $penalty = $this->round2((float) ($penaltyByDriver[$driverId] ?? 0));
            $accrual = $this->round2($route * self::DRIVER_SHARE);
            $payableCurrent = $this->round2(max(0, $accrual - $penalty + $bonus));
            $carryOver = $this->computeDriverCarryOverAmount((int) $driverId, $mondayStr);
            $payable = $this->round2($payableCurrent + $carryOver);

            if ($route <= 0 && $bonus <= 0 && $penalty <= 0 && $carryOver <= 0) {
                continue;
            }

            $payout = $payoutsByDriver->get($driverId);
            $driversPayroll += $payable;

            $driversOut[] = [
                'id' => (int) $driverId,
                'name' => $drivers[$driverId]->full_name ?? ('Водитель #'.$driverId),
                'route_total' => $route,
                'accrual' => $accrual,
                'bonus' => $bonus,
                'penalty' => $penalty,
                'current_payable' => $payableCurrent,
                'carry_over' => $carryOver,
                'payable' => $payable,
                'is_paid' => $payout !== null,
                'paid_at' => $payout?->paid_at?->toDateTimeString(),
                'payout_id' => $payout?->id,
            ];
        }

        usort($driversOut, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        $logistician = Logistician::query()->where('is_active', true)->orderBy('id')->first();

        $logisticianOut = $this->buildLogisticianWeekPayload(
            $logistician,
            $monday,
            $sunday,
            $mondayStr,
            $sundayStr
        );

        [$manualExp, $rentExp] = $this->vehicleExpensesForWeek($mondayStr, $sundayStr);
        $vehicleExpensesTotal = $this->round2($manualExp + $rentExp);

        $afterPayroll = $this->round2($routeTotal - $driversPayroll - $logisticianOut['amount']);

        return [
            'week_monday' => $mondayStr,
            'week_sunday' => $sundayStr,
            'route_sheets_total' => $this->round2($routeTotal),
            'drivers_payroll' => $this->round2($driversPayroll),
            'logistician_payroll' => $logisticianOut,
            'after_payroll' => $afterPayroll,
            'vehicle_expenses_total' => $vehicleExpensesTotal,
            'vehicle_expenses_breakdown' => [
                'manual' => $this->round2($manualExp),
                'rent' => $this->round2($rentExp),
            ],
            'net_profit' => $this->round2($afterPayroll - $vehicleExpensesTotal),
            'drivers' => $driversOut,
        ];
    }

    public function driverDayBreakdown(Request $request): array
    {
        $validated = $request->validate([
            'week_monday' => ['required', 'date_format:Y-m-d'],
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
        ]);

        $monday = Carbon::parse($validated['week_monday'])->startOfDay();
        if (! $monday->isMonday()) {
            throw ValidationException::withMessages([
                'week_monday' => 'Укажите понедельник недели.',
            ]);
        }

        $sunday = $monday->copy()->addDays(6);
        $mondayStr = $monday->toDateString();
        $sundayStr = $sunday->toDateString();
        $driverId = (int) $validated['driver_id'];

        $driver = Driver::find($driverId);

        $weekdayLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $monday->copy()->addDays($i);
            $dayStr = $day->toDateString();

            $route = (float) DriverDailyReport::query()
                ->where('driver_id', $driverId)
                ->whereDate('report_date', $dayStr)
                ->sum(DB::raw('COALESCE(route_sheet_total, 0)'));
            $route = $this->round2($route);

            $bonus = $this->round2((float) DriverAdjustment::query()
                ->where('driver_id', $driverId)
                ->where('adjustment_type', 'bonus')
                ->whereDate('event_date', $dayStr)
                ->sum('total_amount'));

            $penalty = $this->round2((float) DriverAdjustmentPart::query()
                ->join('driver_adjustments', 'driver_adjustment_parts.driver_adjustment_id', '=', 'driver_adjustments.id')
                ->where('driver_adjustments.driver_id', $driverId)
                ->where('driver_adjustments.adjustment_type', 'penalty')
                ->whereDate('driver_adjustment_parts.due_date', $dayStr)
                ->sum('driver_adjustment_parts.amount'));

            $accrual = $this->round2($route * self::DRIVER_SHARE);
            $netDay = $this->round2($accrual - $penalty + $bonus);

            $days[] = [
                'date' => $dayStr,
                'weekday' => $weekdayLabels[$day->dayOfWeekIso - 1] ?? $dayStr,
                'route_total' => $route,
                'accrual' => $accrual,
                'bonus' => $bonus,
                'penalty' => $penalty,
                'net' => $netDay,
            ];
        }

        return [
            'driver_id' => $driverId,
            'driver_name' => $driver?->full_name,
            'week_monday' => $mondayStr,
            'week_sunday' => $sundayStr,
            'days' => $days,
        ];
    }

    public function payDriver(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'week_monday' => ['required', 'date_format:Y-m-d'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        [$monday, $sunday] = $this->parseWeekMondayOrFail($validated['week_monday']);
        $this->assertWeekNotFuture($monday);

        $driverId = (int) $validated['driver_id'];
        $mondayStr = $monday->toDateString();

        $weeksToPay = $this->computeUnpaidDriverWeeksUpTo($driverId, $mondayStr);
        if ($weeksToPay === []) {
            throw ValidationException::withMessages([
                'driver_id' => 'Нет данных для выплаты за эту неделю.',
            ]);
        }

        $totalPayable = $this->round2((float) collect($weeksToPay)->sum('payable'));
        if ($totalPayable <= 0) {
            throw ValidationException::withMessages([
                'driver_id' => 'Сумма к выплате равна нулю.',
            ]);
        }

        if (DriverPayout::query()->where('driver_id', $driverId)->whereDate('week_monday', $mondayStr)->exists()) {
            throw ValidationException::withMessages([
                'driver_id' => 'Выплата за эту неделю уже проведена.',
            ]);
        }

        DB::transaction(function () use ($driverId, $weeksToPay, $validated) {
            foreach ($weeksToPay as $weekMonday => $weekData) {
                $weekEnd = Carbon::parse($weekMonday)->addDays(6)->toDateString();
                DriverPayout::create([
                    'driver_id' => $driverId,
                    'week_monday' => $weekMonday,
                    'amount' => $weekData['payable'],
                    'accrual_amount' => $weekData['accrual'],
                    'bonus_amount' => $weekData['bonus'],
                    'penalty_amount' => $weekData['penalty'],
                    'paid_at' => now(),
                    'comment' => isset($validated['comment']) ? trim((string) $validated['comment']) : null,
                ]);

                $this->applyPenaltyPartsForDriverWeek($driverId, $weekMonday, $weekEnd);
            }
        });

        $payout = DriverPayout::query()
            ->where('driver_id', $driverId)
            ->whereDate('week_monday', $mondayStr)
            ->first();

        return response()->json([
            'success' => true,
            'payout' => $payout ? $this->driverPayoutResource($payout) : null,
        ], 201);
    }

    public function unpayDriver(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'week_monday' => ['required', 'date_format:Y-m-d'],
        ]);

        [$monday, $sunday] = $this->parseWeekMondayOrFail($validated['week_monday']);
        $driverId = (int) $validated['driver_id'];
        $mondayStr = $monday->toDateString();
        $sundayStr = $sunday->toDateString();

        $payout = DriverPayout::query()
            ->where('driver_id', $driverId)
            ->whereDate('week_monday', $mondayStr)
            ->first();

        if (! $payout) {
            return response()->json(['message' => 'Выплата не найдена'], 404);
        }

        DB::transaction(function () use ($payout, $driverId, $mondayStr, $sundayStr) {
            $payout->delete();
            $this->unapplyPenaltyPartsForDriverWeek($driverId, $mondayStr, $sundayStr);
        });

        return response()->json(['success' => true]);
    }

    public function payLogistician(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'week_monday' => ['required', 'date_format:Y-m-d'],
        ]);

        [$monday, $sunday] = $this->parseWeekMondayOrFail($validated['week_monday']);
        $this->assertWeekNotFuture($monday);

        $logistician = Logistician::query()->where('is_active', true)->orderBy('id')->first();
        if (! $logistician) {
            throw ValidationException::withMessages([
                'week_monday' => 'Нет активного логиста в системе.',
            ]);
        }

        $mondayStr = $monday->toDateString();
        $sundayStr = $sunday->toDateString();

        $weeksToPay = $this->computeUnpaidLogisticianWeeksUpTo($logistician, $monday);
        $payload = $weeksToPay[$mondayStr] ?? ['base' => 0.0, 'percent' => 0.0, 'amount' => 0.0];

        if ($payload['amount'] <= 0) {
            throw ValidationException::withMessages([
                'week_monday' => 'Нет начисления логисту за выбранную неделю.',
            ]);
        }

        if (LogisticianPayout::query()
            ->where('logistician_id', $logistician->id)
            ->whereDate('week_monday', $mondayStr)
            ->exists()) {
            throw ValidationException::withMessages([
                'week_monday' => 'Выплата логисту за эту неделю уже проведена.',
            ]);
        }

        foreach ($weeksToPay as $weekMonday => $weekData) {
            LogisticianPayout::create([
                'logistician_id' => $logistician->id,
                'week_monday' => $weekMonday,
                'route_sheets_base_amount' => $weekData['base'],
                'percent' => $weekData['percent'],
                'amount' => $weekData['amount'],
                'paid_at' => now(),
            ]);
        }
        $payout = LogisticianPayout::query()
            ->where('logistician_id', $logistician->id)
            ->whereDate('week_monday', $mondayStr)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'payout' => $this->logisticianPayoutResource($payout),
        ], 201);
    }

    public function unpayLogistician(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'week_monday' => ['required', 'date_format:Y-m-d'],
        ]);

        [$monday] = $this->parseWeekMondayOrFail($validated['week_monday']);
        $mondayStr = $monday->toDateString();

        $logistician = Logistician::query()->where('is_active', true)->orderBy('id')->first();
        if (! $logistician) {
            return response()->json(['message' => 'Нет активного логиста'], 404);
        }

        $payout = LogisticianPayout::query()
            ->where('logistician_id', $logistician->id)
            ->whereDate('week_monday', $mondayStr)
            ->first();

        if (! $payout) {
            return response()->json(['message' => 'Выплата не найдена'], 404);
        }

        $payout->delete();

        return response()->json(['success' => true]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function validatedWeekRange(Request $request): array
    {
        $validated = $request->validate([
            'week_monday' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->parseWeekMondayOrFail($validated['week_monday']);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function parseWeekMondayOrFail(string $weekMonday): array
    {
        $monday = Carbon::parse($weekMonday)->startOfDay();
        if (! $monday->isMonday()) {
            throw ValidationException::withMessages([
                'week_monday' => 'Укажите понедельник недели.',
            ]);
        }

        $sunday = $monday->copy()->addDays(6)->endOfDay();

        return [$monday, $sunday];
    }

    private function assertWeekNotFuture(Carbon $weekMonday): void
    {
        if ($weekMonday->gt(Carbon::today())) {
            throw ValidationException::withMessages([
                'week_monday' => 'Нельзя оплатить будущий период.',
            ]);
        }
    }

    private function round2(float $value): float
    {
        return round($value, 2);
    }

    /**
     * @return array{route: float, bonus: float, penalty: float, accrual: float, payable: float}
     */
    private function computeDriverWeekAmounts(int $driverId, string $mondayStr, string $sundayStr): array
    {
        $route = $this->round2((float) DriverDailyReport::query()
            ->where('driver_id', $driverId)
            ->whereDate('report_date', '>=', $mondayStr)
            ->whereDate('report_date', '<=', $sundayStr)
            ->sum(DB::raw('COALESCE(route_sheet_total, 0)')));

        $bonus = $this->round2((float) DriverAdjustment::query()
            ->where('driver_id', $driverId)
            ->where('adjustment_type', 'bonus')
            ->whereDate('event_date', '>=', $mondayStr)
            ->whereDate('event_date', '<=', $sundayStr)
            ->sum('total_amount'));

        $penalty = $this->round2((float) DriverAdjustmentPart::query()
            ->join('driver_adjustments', 'driver_adjustment_parts.driver_adjustment_id', '=', 'driver_adjustments.id')
            ->where('driver_adjustments.driver_id', $driverId)
            ->where('driver_adjustments.adjustment_type', 'penalty')
            ->whereDate('driver_adjustment_parts.due_date', '>=', $mondayStr)
            ->whereDate('driver_adjustment_parts.due_date', '<=', $sundayStr)
            ->sum('driver_adjustment_parts.amount'));

        $accrual = $this->round2($route * self::DRIVER_SHARE);
        $payable = $this->round2(max(0, $accrual - $penalty + $bonus));

        return [
            'route' => $route,
            'bonus' => $bonus,
            'penalty' => $penalty,
            'accrual' => $accrual,
            'payable' => $payable,
        ];
    }

    private function computeDriverCarryOverAmount(int $driverId, string $currentMonday): float
    {
        $weeks = $this->computeUnpaidDriverWeeksUpTo($driverId, $currentMonday, false);

        return $this->round2((float) collect($weeks)->sum('payable'));
    }

    /**
     * @return array<int, int>
     */
    private function driverIdsWithCarryOver(string $currentMonday): array
    {
        $lastDate = Carbon::parse($currentMonday)->subDay()->toDateString();
        $candidateIds = collect([
            DriverDailyReport::query()
                ->whereDate('report_date', '<=', $lastDate)
                ->distinct()
                ->pluck('driver_id')
                ->all(),
            DriverAdjustment::query()
                ->whereDate('event_date', '<=', $lastDate)
                ->distinct()
                ->pluck('driver_id')
                ->all(),
            DriverAdjustmentPart::query()
                ->join('driver_adjustments', 'driver_adjustment_parts.driver_adjustment_id', '=', 'driver_adjustments.id')
                ->whereDate('driver_adjustment_parts.due_date', '<=', $lastDate)
                ->distinct()
                ->pluck('driver_adjustments.driver_id')
                ->all(),
        ])->flatten()->unique()->filter()->values();

        $result = [];
        foreach ($candidateIds as $driverId) {
            if ($this->computeDriverCarryOverAmount((int) $driverId, $currentMonday) > 0) {
                $result[] = (int) $driverId;
            }
        }

        return $result;
    }

    /**
     * @return array<string, array{route: float, bonus: float, penalty: float, accrual: float, payable: float}>
     */
    private function computeUnpaidDriverWeeksUpTo(int $driverId, string $currentMonday, bool $includeCurrent = true): array
    {
        $lastDate = Carbon::parse($currentMonday)->addDays($includeCurrent ? 6 : -1)->toDateString();
        if (Carbon::parse($lastDate)->lt(Carbon::parse('2000-01-01'))) {
            return [];
        }

        $reports = DriverDailyReport::query()
            ->where('driver_id', $driverId)
            ->whereDate('report_date', '<=', $lastDate)
            ->get(['report_date', 'route_sheet_total']);
        $bonuses = DriverAdjustment::query()
            ->where('driver_id', $driverId)
            ->where('adjustment_type', 'bonus')
            ->whereDate('event_date', '<=', $lastDate)
            ->get(['event_date', 'total_amount']);
        $penalties = DriverAdjustmentPart::query()
            ->join('driver_adjustments', 'driver_adjustment_parts.driver_adjustment_id', '=', 'driver_adjustments.id')
            ->where('driver_adjustments.driver_id', $driverId)
            ->where('driver_adjustments.adjustment_type', 'penalty')
            ->whereDate('driver_adjustment_parts.due_date', '<=', $lastDate)
            ->get(['driver_adjustment_parts.due_date', 'driver_adjustment_parts.amount']);

        $weeks = [];
        foreach ($reports as $row) {
            $week = Carbon::parse($row->report_date)->startOfWeek(Carbon::MONDAY)->toDateString();
            $weeks[$week]['route'] = ($weeks[$week]['route'] ?? 0) + (float) ($row->route_sheet_total ?? 0);
        }
        foreach ($bonuses as $row) {
            $week = Carbon::parse($row->event_date)->startOfWeek(Carbon::MONDAY)->toDateString();
            $weeks[$week]['bonus'] = ($weeks[$week]['bonus'] ?? 0) + (float) $row->total_amount;
        }
        foreach ($penalties as $row) {
            $week = Carbon::parse($row->due_date)->startOfWeek(Carbon::MONDAY)->toDateString();
            $weeks[$week]['penalty'] = ($weeks[$week]['penalty'] ?? 0) + (float) $row->amount;
        }

        $paidUntil = $includeCurrent ? $currentMonday : Carbon::parse($currentMonday)->subWeek()->toDateString();
        $paidWeeks = DriverPayout::query()
            ->where('driver_id', $driverId)
            ->whereDate('week_monday', '<=', $paidUntil)
            ->pluck('week_monday')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip()
            ->all();

        ksort($weeks);
        $result = [];
        foreach ($weeks as $week => $vals) {
            if (isset($paidWeeks[$week])) {
                continue;
            }
            $route = $this->round2((float) ($vals['route'] ?? 0));
            $bonus = $this->round2((float) ($vals['bonus'] ?? 0));
            $penalty = $this->round2((float) ($vals['penalty'] ?? 0));
            $accrual = $this->round2($route * self::DRIVER_SHARE);
            $payable = $this->round2(max(0, $accrual - $penalty + $bonus));
            if ($payable <= 0 && $route <= 0 && $bonus <= 0 && $penalty <= 0) {
                continue;
            }
            $result[$week] = [
                'route' => $route,
                'bonus' => $bonus,
                'penalty' => $penalty,
                'accrual' => $accrual,
                'payable' => $payable,
            ];
        }

        return $result;
    }

    private function applyPenaltyPartsForDriverWeek(int $driverId, string $mondayStr, string $sundayStr): void
    {
        $parts = DriverAdjustmentPart::query()
            ->whereHas('adjustment', function ($q) use ($driverId) {
                $q->where('driver_id', $driverId)->where('adjustment_type', 'penalty');
            })
            ->whereDate('due_date', '>=', $mondayStr)
            ->whereDate('due_date', '<=', $sundayStr)
            ->get();

        $adjustmentIds = $parts->pluck('driver_adjustment_id')->unique()->filter()->values();

        foreach ($parts as $part) {
            $part->update([
                'is_applied' => true,
                'applied_at' => now(),
            ]);
        }

        foreach ($adjustmentIds as $adjId) {
            $adj = DriverAdjustment::with('parts')->find((int) $adjId);
            $this->syncPenaltyAdjustmentStatus($adj);
        }
    }

    private function unapplyPenaltyPartsForDriverWeek(int $driverId, string $mondayStr, string $sundayStr): void
    {
        $parts = DriverAdjustmentPart::query()
            ->whereHas('adjustment', function ($q) use ($driverId) {
                $q->where('driver_id', $driverId)->where('adjustment_type', 'penalty');
            })
            ->whereDate('due_date', '>=', $mondayStr)
            ->whereDate('due_date', '<=', $sundayStr)
            ->get();

        $adjustmentIds = $parts->pluck('driver_adjustment_id')->unique()->filter()->values();

        foreach ($parts as $part) {
            $part->update([
                'is_applied' => false,
                'applied_at' => null,
            ]);
        }

        foreach ($adjustmentIds as $adjId) {
            $adj = DriverAdjustment::with('parts')->find((int) $adjId);
            $this->syncPenaltyAdjustmentStatus($adj);
        }
    }

    private function syncPenaltyAdjustmentStatus(?DriverAdjustment $adj): void
    {
        if (! $adj || $adj->adjustment_type !== 'penalty') {
            return;
        }

        $adj->load('parts');
        $parts = $adj->parts;
        if ($parts->isEmpty()) {
            return;
        }

        $allApplied = $parts->every(fn (DriverAdjustmentPart $p) => (bool) $p->is_applied);
        $adj->update(['status' => $allApplied ? 'closed' : 'open']);
    }

    private function buildLogisticianWeekPayload(
        ?Logistician $logistician,
        Carbon $monday,
        Carbon $sunday,
        string $mondayStr,
        string $sundayStr
    ): array {
        if (! $logistician) {
            return [
                'id' => null,
                'name' => null,
                'payout_start_date' => null,
                'percent' => null,
                'base' => 0.0,
                'amount' => 0.0,
                'is_paid' => false,
                'paid_at' => null,
                'payout_id' => null,
                'inactive_reason' => 'no_active_logistician',
            ];
        }

        $start = $this->resolveLogisticianEffectiveStart($logistician);

        $sundayDate = $sunday->copy()->startOfDay();

        if ($sundayDate->lt($start)) {
            return [
                'id' => $logistician->id,
                'name' => $logistician->full_name,
                'payout_start_date' => $start->toDateString(),
                'percent' => $this->round2((float) $logistician->payout_percent),
                'base' => 0.0,
                'amount' => 0.0,
                'is_paid' => false,
                'paid_at' => null,
                'payout_id' => null,
                'inactive_reason' => 'before_start_date',
            ];
        }

        $computed = $this->computeLogisticianAmounts($logistician, $monday, $sunday, $mondayStr, $sundayStr);
        $carryOver = $this->computeLogisticianCarryOverAmount($logistician, $monday);

        $payout = LogisticianPayout::query()
            ->where('logistician_id', $logistician->id)
            ->whereDate('week_monday', $mondayStr)
            ->first();

        return [
            'id' => $logistician->id,
            'name' => $logistician->full_name,
            'payout_start_date' => $start->toDateString(),
            'percent' => $computed['percent'],
            'base' => $computed['base'],
            'current_amount' => $computed['amount'],
            'carry_over' => $carryOver,
            'amount' => $this->round2($computed['amount'] + $carryOver),
            'is_paid' => $payout !== null,
            'paid_at' => $payout?->paid_at?->toDateTimeString(),
            'payout_id' => $payout?->id,
            'inactive_reason' => null,
        ];
    }

    /**
     * @return array{base: float, percent: float, amount: float}
     */
    private function computeLogisticianAmounts(
        Logistician $logistician,
        Carbon $monday,
        Carbon $sunday,
        string $mondayStr,
        string $sundayStr
    ): array {
        $start = $this->resolveLogisticianEffectiveStart($logistician);

        $sundayDate = $sunday->copy()->startOfDay();
        if ($sundayDate->lt($start)) {
            return ['base' => 0.0, 'percent' => $this->round2((float) $logistician->payout_percent), 'amount' => 0.0];
        }

        $rangeStart = $monday->copy()->startOfDay()->max($start);

        $base = (float) DriverDailyReport::query()
            ->whereDate('report_date', '>=', $mondayStr)
            ->whereDate('report_date', '<=', $sundayStr)
            ->whereDate('report_date', '>=', $rangeStart->toDateString())
            ->sum(DB::raw('COALESCE(route_sheet_total, 0)'));

        $base = $this->round2($base);
        $percent = $this->round2((float) $logistician->payout_percent);
        $amount = $this->round2($base * ($percent / 100.0));

        return [
            'base' => $base,
            'percent' => $percent,
            'amount' => $amount,
        ];
    }

    private function computeLogisticianCarryOverAmount(Logistician $logistician, Carbon $currentMonday): float
    {
        $weeks = $this->computeUnpaidLogisticianWeeksUpTo($logistician, $currentMonday->copy()->subWeek());

        return $this->round2((float) collect($weeks)->sum('amount'));
    }

    /**
     * @return array<string, array{base: float, percent: float, amount: float}>
     */
    private function computeUnpaidLogisticianWeeksUpTo(Logistician $logistician, Carbon $upToMonday): array
    {
        $start = $this->resolveLogisticianEffectiveStart($logistician);
        if ($upToMonday->lt($start->copy()->startOfWeek(Carbon::MONDAY))) {
            return [];
        }

        $reports = DriverDailyReport::query()
            ->whereDate('report_date', '>=', $start->toDateString())
            ->whereDate('report_date', '<=', $upToMonday->copy()->addDays(6)->toDateString())
            ->get(['report_date', 'route_sheet_total']);

        $paidWeeks = LogisticianPayout::query()
            ->where('logistician_id', $logistician->id)
            ->whereDate('week_monday', '<=', $upToMonday->toDateString())
            ->pluck('week_monday')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip()
            ->all();

        $weeksBase = [];
        foreach ($reports as $row) {
            $reportDate = Carbon::parse($row->report_date)->startOfDay();
            if ($reportDate->lt($start)) {
                continue;
            }
            $week = $reportDate->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
            $weeksBase[$week] = ($weeksBase[$week] ?? 0) + (float) ($row->route_sheet_total ?? 0);
        }

        ksort($weeksBase);
        $percent = $this->round2((float) $logistician->payout_percent);
        $result = [];
        foreach ($weeksBase as $week => $baseRaw) {
            if (isset($paidWeeks[$week])) {
                continue;
            }
            $base = $this->round2((float) $baseRaw);
            $amount = $this->round2($base * ($percent / 100.0));
            if ($amount <= 0 && $base <= 0) {
                continue;
            }
            $result[$week] = [
                'base' => $base,
                'percent' => $percent,
                'amount' => $amount,
            ];
        }

        return $result;
    }

    private function resolveLogisticianEffectiveStart(Logistician $logistician): Carbon
    {
        $configuredStart = $logistician->payout_start_date instanceof Carbon
            ? $logistician->payout_start_date->copy()->startOfDay()
            : Carbon::parse($logistician->payout_start_date)->startOfDay();
        $policyStart = Carbon::parse(self::LOGISTICIAN_POLICY_START_DATE)->startOfDay();

        return $configuredStart->lt($policyStart) ? $policyStart : $configuredStart;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function vehicleExpensesForWeek(string $mondayStr, string $sundayStr): array
    {
        $manual = (float) VehicleExpense::query()
            ->whereDate('expense_date', '>=', $mondayStr)
            ->whereDate('expense_date', '<=', $sundayStr)
            ->sum('amount');

        $usageRows = DriverDailyReport::query()
            ->whereDate('report_date', '>=', $mondayStr)
            ->whereDate('report_date', '<=', $sundayStr)
            ->whereNotNull('fleet_vehicle_id')
            ->selectRaw('fleet_vehicle_id, COUNT(DISTINCT report_date) as usage_days')
            ->groupBy('fleet_vehicle_id')
            ->get();

        $rent = 0.0;
        foreach ($usageRows as $row) {
            $vehicle = FleetVehicle::query()->find((int) $row->fleet_vehicle_id);
            if (! $vehicle || $vehicle->ownership_type !== 'rented') {
                continue;
            }
            $perDay = (float) ($vehicle->rent_per_day ?? 0);
            if ($perDay <= 0) {
                continue;
            }
            $days = (int) $row->usage_days;
            $rent += $perDay * $days;
        }

        return [$manual, $rent];
    }

    private function driverPayoutResource(DriverPayout $payout): array
    {
        return [
            'id' => $payout->id,
            'driver_id' => $payout->driver_id,
            'week_monday' => $payout->week_monday instanceof Carbon
                ? $payout->week_monday->toDateString()
                : (string) $payout->week_monday,
            'amount' => $this->round2((float) $payout->amount),
            'paid_at' => $payout->paid_at?->toDateTimeString(),
        ];
    }

    private function logisticianPayoutResource(LogisticianPayout $payout): array
    {
        return [
            'id' => $payout->id,
            'logistician_id' => $payout->logistician_id,
            'week_monday' => $payout->week_monday instanceof Carbon
                ? $payout->week_monday->toDateString()
                : (string) $payout->week_monday,
            'amount' => $this->round2((float) $payout->amount),
            'paid_at' => $payout->paid_at?->toDateTimeString(),
        ];
    }
}
