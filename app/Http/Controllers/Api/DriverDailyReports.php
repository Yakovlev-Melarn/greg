<?php

namespace App\Http\Controllers\Api;

use App\Models\Driver;
use App\Models\DriverDailyReport;
use App\Models\FleetVehicle;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DriverDailyReports
{
    public const DEFAULT_NIGHT_LOADING_AMOUNT = 3000.0;

    public function list(Request $request): array
    {
        $validated = $request->validate([
            'week_monday' => ['required', 'date_format:Y-m-d'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
        ]);

        $monday = Carbon::parse($validated['week_monday'])->startOfDay();
        if (! $monday->isMonday()) {
            throw ValidationException::withMessages([
                'week_monday' => 'Укажите понедельник недели (календарная неделя с понедельника по воскресенье).',
            ]);
        }

        $sunday = $monday->copy()->addDays(6);

        $query = DriverDailyReport::query()
            ->with(['driver', 'vehicle'])
            ->whereDate('report_date', '>=', $monday->toDateString())
            ->whereDate('report_date', '<=', $sunday->toDateString());

        if (! empty($validated['driver_id'])) {
            $query->where('driver_id', (int) $validated['driver_id']);
        }

        return $query
            ->orderByDesc('report_date')
            ->orderBy('driver_id')
            ->get()
            ->map(fn (DriverDailyReport $r) => $this->toResource($r))
            ->toArray();
    }

    public function store(Request $request): JsonResponse
    {
        $uniqueRule = Rule::unique('driver_daily_reports', 'report_date')
            ->where(function ($q) use ($request) {
                $q->where('driver_id', (int) $request->input('driver_id'));
            });

        $validated = $this->validatePayload($request, $uniqueRule);
        $data = $this->normalizeForSave($validated);

        try {
            $report = DriverDailyReport::create($data);
        } catch (QueryException $e) {
            if ($this->isUniqueDriverDateViolation($e)) {
                throw ValidationException::withMessages([
                    'report_date' => 'На эту дату для выбранного водителя отчёт уже существует.',
                ]);
            }
            throw $e;
        }

        return response()->json($this->toResource($report->load(['driver', 'vehicle'])), 201);
    }

    public function update(Request $request): JsonResponse
    {
        $report = DriverDailyReport::find((int) $request->input('id'));
        if (! $report) {
            return response()->json(['message' => 'Отчёт не найден'], 404);
        }

        $uniqueRule = Rule::unique('driver_daily_reports', 'report_date')
            ->where(function ($q) use ($request) {
                $q->where('driver_id', (int) $request->input('driver_id'));
            })
            ->ignore($report->id);

        $validated = $this->validatePayload($request, $uniqueRule);
        $data = $this->normalizeForSave($validated);

        try {
            $report->update($data);
        } catch (QueryException $e) {
            if ($this->isUniqueDriverDateViolation($e)) {
                throw ValidationException::withMessages([
                    'report_date' => 'На эту дату для выбранного водителя отчёт уже существует.',
                ]);
            }
            throw $e;
        }

        return response()->json($this->toResource($report->fresh(['driver', 'vehicle'])));
    }

    public function destroy(Request $request): JsonResponse
    {
        $report = DriverDailyReport::find((int) $request->input('id'));
        if (! $report) {
            return response()->json(['message' => 'Отчёт не найден'], 404);
        }

        $report->delete();

        return response()->json(['success' => true]);
    }

    private function validatePayload(Request $request, $uniqueRule): array
    {
        return $request->validate([
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'fleet_vehicle_id' => ['nullable', 'integer', 'exists:fleet_vehicles,id'],
            'report_date' => ['required', 'date', $uniqueRule],
            'work_hours' => ['nullable', 'numeric', 'min:0'],
            'extra_work_hours' => ['nullable', 'numeric', 'min:0'],
            'night_loading' => ['sometimes', 'boolean'],
            'night_loading_amount' => ['nullable', 'numeric', 'min:0'],
            'manual_floor_lift' => ['sometimes', 'boolean'],
            'manual_floor_lift_amount' => ['nullable', 'numeric', 'min:0'],
            'route_sheet_total' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function normalizeForSave(array $validated): array
    {
        $night = (bool) ($validated['night_loading'] ?? false);
        $manual = (bool) ($validated['manual_floor_lift'] ?? false);

        $nightAmount = $validated['night_loading_amount'] ?? null;
        if ($night) {
            if ($nightAmount === null || $nightAmount === '') {
                $nightAmount = self::DEFAULT_NIGHT_LOADING_AMOUNT;
            }
            $nightAmount = (float) $nightAmount;
        } else {
            $nightAmount = null;
        }

        $manualAmount = null;
        if ($manual && isset($validated['manual_floor_lift_amount']) && $validated['manual_floor_lift_amount'] !== '' && $validated['manual_floor_lift_amount'] !== null) {
            $manualAmount = (float) $validated['manual_floor_lift_amount'];
        }

        return [
            'driver_id' => (int) $validated['driver_id'],
            'fleet_vehicle_id' => empty($validated['fleet_vehicle_id']) ? null : (int) $validated['fleet_vehicle_id'],
            'report_date' => Carbon::parse($validated['report_date'])->toDateString(),
            'work_hours' => $this->nullableFloat($validated['work_hours'] ?? null),
            'extra_work_hours' => $this->nullableFloat($validated['extra_work_hours'] ?? null),
            'night_loading' => $night,
            'night_loading_amount' => $nightAmount,
            'manual_floor_lift' => $manual,
            'manual_floor_lift_amount' => $manualAmount,
            'route_sheet_total' => $this->nullableFloat($validated['route_sheet_total'] ?? null),
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function isUniqueDriverDateViolation(QueryException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'UNIQUE constraint')
            && (str_contains($msg, 'driver_daily_reports.driver_id') || str_contains($msg, 'driver_id'));
    }

    private function toResource(DriverDailyReport $report): array
    {
        $driver = $report->driver ?? Driver::find($report->driver_id);
        $vehicle = $report->vehicle;
        if (! $vehicle && $driver?->fleet_vehicle_id) {
            $vehicle = FleetVehicle::find((int) $driver->fleet_vehicle_id);
        }

        return [
            'id' => $report->id,
            'driver_id' => $report->driver_id,
            'driver_name' => $driver?->full_name,
            'fleet_vehicle_id' => $report->fleet_vehicle_id,
            'vehicle_label' => $vehicle
                ? trim(sprintf('%s %s (%s)', $vehicle->brand, $vehicle->model, $vehicle->plate_number))
                : null,
            'report_date' => $report->report_date instanceof Carbon
                ? $report->report_date->toDateString()
                : (string) $report->report_date,
            'work_hours' => $report->work_hours,
            'extra_work_hours' => $report->extra_work_hours,
            'night_loading' => (bool) $report->night_loading,
            'night_loading_amount' => $report->night_loading_amount,
            'manual_floor_lift' => (bool) $report->manual_floor_lift,
            'manual_floor_lift_amount' => $report->manual_floor_lift_amount,
            'route_sheet_total' => $report->route_sheet_total,
        ];
    }
}
