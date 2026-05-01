<?php

namespace App\Http\Controllers\Api;

use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Drivers
{
    public function list(): array
    {
        return Driver::with('vehicle')
            ->orderBy('full_name')
            ->get()
            ->map(function (Driver $driver) {
                $vehicle = $driver->vehicle;

                return [
                    'id' => $driver->id,
                    'full_name' => $driver->full_name,
                    'phone' => $driver->phone,
                    'notes' => $driver->notes,
                    'fleet_vehicle_id' => $driver->fleet_vehicle_id,
                    'vehicle_label' => $vehicle
                        ? trim(sprintf('%s %s (%s)', $vehicle->brand, $vehicle->model, $vehicle->plate_number))
                        : null,
                ];
            })
            ->toArray();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateDriver($request);
        $this->releaseVehicleFromOtherDrivers($validated['fleet_vehicle_id'] ?? null, null);

        $driver = Driver::create($validated);

        return response()->json($driver->fresh('vehicle'), 201);
    }

    public function update(Request $request): JsonResponse
    {
        $driver = Driver::find((int) $request->input('id'));
        if (! $driver) {
            return response()->json(['message' => 'Водитель не найден'], 404);
        }

        $validated = $this->validateDriver($request);
        $this->releaseVehicleFromOtherDrivers($validated['fleet_vehicle_id'] ?? null, $driver->id);

        $driver->update($validated);

        return response()->json($driver->fresh('vehicle'));
    }

    public function destroy(Request $request): JsonResponse
    {
        $driver = Driver::find((int) $request->input('id'));
        if (! $driver) {
            return response()->json(['message' => 'Водитель не найден'], 404);
        }

        $driver->delete();

        return response()->json(['success' => true]);
    }

    private function validateDriver(Request $request): array
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'fleet_vehicle_id' => ['nullable', 'integer', 'exists:fleet_vehicles,id'],
        ]);

        if (empty($validated['fleet_vehicle_id'])) {
            $validated['fleet_vehicle_id'] = null;
        }

        return $validated;
    }

    private function releaseVehicleFromOtherDrivers(?int $vehicleId, ?int $keepDriverId): void
    {
        if ($vehicleId === null) {
            return;
        }

        Driver::query()
            ->where('fleet_vehicle_id', $vehicleId)
            ->when($keepDriverId, function ($query) use ($keepDriverId) {
                $query->where('id', '!=', $keepDriverId);
            })
            ->update(['fleet_vehicle_id' => null]);
    }
}
