<?php

namespace App\Http\Controllers\Api;

use App\Models\FleetVehicle;
use App\Models\VehicleExpense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Fleet
{
    public function list(): array
    {
        return FleetVehicle::with('transportCompany')
            ->withSum('expenses', 'amount')
            ->orderByDesc('id')
            ->get()
            ->map(function (FleetVehicle $vehicle) {
                return [
                    'id' => $vehicle->id,
                    'brand' => $vehicle->brand,
                    'model' => $vehicle->model,
                    'plate_number' => $vehicle->plate_number,
                    'tonnage' => $vehicle->tonnage,
                    'ownership_type' => $vehicle->ownership_type,
                    'rent_per_day' => $vehicle->rent_per_day,
                    'transport_company_id' => $vehicle->transport_company_id,
                    'transport_company_name' => $vehicle->transportCompany?->name,
                    'expenses_total' => (float) ($vehicle->expenses_sum_amount ?? 0),
                ];
            })
            ->toArray();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateVehicle($request);
        $vehicle = FleetVehicle::create($validated);

        return response()->json($vehicle, 201);
    }

    public function update(Request $request): JsonResponse
    {
        $vehicle = FleetVehicle::find((int) $request->input('id'));
        if (! $vehicle) {
            return response()->json(['message' => 'Машина не найдена'], 404);
        }

        $validated = $this->validateVehicle($request, $vehicle->id);
        $vehicle->update($validated);

        return response()->json($vehicle);
    }

    public function destroy(Request $request): JsonResponse
    {
        $vehicle = FleetVehicle::find((int) $request->input('id'));
        if (! $vehicle) {
            return response()->json(['message' => 'Машина не найдена'], 404);
        }

        $vehicle->delete();

        return response()->json(['success' => true]);
    }

    public function expensesList(Request $request): array
    {
        return VehicleExpense::where('fleet_vehicle_id', (int) $request->input('vehicle_id'))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get()
            ->toArray();
    }

    public function expenseStore(Request $request): JsonResponse
    {
        $validated = $this->validateExpense($request);
        $expense = VehicleExpense::create($validated);

        return response()->json($expense, 201);
    }

    public function expenseUpdate(Request $request): JsonResponse
    {
        $expense = VehicleExpense::find((int) $request->input('id'));
        if (! $expense) {
            return response()->json(['message' => 'Расход не найден'], 404);
        }

        $validated = $this->validateExpense($request);
        $expense->update($validated);

        return response()->json($expense);
    }

    public function expenseDestroy(Request $request): JsonResponse
    {
        $expense = VehicleExpense::find((int) $request->input('id'));
        if (! $expense) {
            return response()->json(['message' => 'Расход не найден'], 404);
        }

        $expense->delete();

        return response()->json(['success' => true]);
    }

    private function validateVehicle(Request $request, ?int $vehicleId = null): array
    {
        $validated = $request->validate([
            'brand' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'plate_number' => ['required', 'string', 'max:20', Rule::unique('fleet_vehicles', 'plate_number')->ignore($vehicleId)],
            'tonnage' => ['required', 'numeric', 'min:0.01'],
            'ownership_type' => ['required', Rule::in(['owned', 'rented'])],
            'rent_per_day' => ['nullable', 'numeric', 'min:0'],
            'transport_company_id' => ['nullable', 'integer', 'exists:transport_companies,id'],
        ]);

        if (($validated['ownership_type'] ?? null) === 'rented' && ! isset($validated['rent_per_day'])) {
            throw ValidationException::withMessages([
                'rent_per_day' => ['Поле аренды обязательно для арендованной машины'],
            ]);
        }

        if (($validated['ownership_type'] ?? null) === 'owned') {
            $validated['rent_per_day'] = null;
        }

        return $validated;
    }

    private function validateExpense(Request $request): array
    {
        return $request->validate([
            'fleet_vehicle_id' => ['required', 'integer', 'exists:fleet_vehicles,id'],
            'expense_date' => ['required', 'date'],
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
