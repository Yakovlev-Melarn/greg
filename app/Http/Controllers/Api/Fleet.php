<?php

namespace App\Http\Controllers\Api;

use App\Models\FleetVehicle;
use App\Models\ExpenseCategory;
use App\Models\VehicleExpense;
use Carbon\Carbon;
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
        return VehicleExpense::with('expenseCategory')
            ->where('fleet_vehicle_id', (int) $request->input('vehicle_id'))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (VehicleExpense $expense) {
                $data = $expense->toArray();
                $data['category'] = $expense->expenseCategory?->name ?? $expense->category;
                $data['expense_category_id'] = $expense->expense_category_id;

                return $data;
            })
            ->toArray();
    }

    public function expenseCategoriesList(): array
    {
        return ExpenseCategory::withCount('expenses')
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function expenseCategoryStore(Request $request): JsonResponse
    {
        $validated = $this->validateExpenseCategory($request);
        $category = ExpenseCategory::create($validated);

        return response()->json($category, 201);
    }

    public function expenseCategoryUpdate(Request $request): JsonResponse
    {
        $category = ExpenseCategory::find((int) $request->input('id'));
        if (! $category) {
            return response()->json(['message' => 'Категория расхода не найдена'], 404);
        }

        $validated = $this->validateExpenseCategory($request, $category->id);
        $category->update($validated);

        VehicleExpense::where('expense_category_id', $category->id)->update(['category' => $category->name]);

        return response()->json($category);
    }

    public function expenseCategoryDestroy(Request $request): JsonResponse
    {
        $category = ExpenseCategory::find((int) $request->input('id'));
        if (! $category) {
            return response()->json(['message' => 'Категория расхода не найдена'], 404);
        }

        $hasExpenses = VehicleExpense::where('expense_category_id', $category->id)->exists();

        if ($hasExpenses) {
            $validated = $request->validate([
                'replacement_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            ]);

            $replacementId = (int) $validated['replacement_id'];
            if ($replacementId === $category->id) {
                throw ValidationException::withMessages([
                    'replacement_id' => ['Нужно выбрать другую категорию для переноса расходов'],
                ]);
            }

            if (ExpenseCategory::where('id', '!=', $category->id)->doesntExist()) {
                return response()->json([
                    'message' => 'Создайте другую категорию расходов, чтобы перенести записи перед удалением',
                ], 422);
            }

            $replacement = ExpenseCategory::find($replacementId);
            if (! $replacement || $replacement->id === $category->id) {
                return response()->json(['message' => 'Категория для переноса не найдена'], 404);
            }

            VehicleExpense::where('expense_category_id', $category->id)->update([
                'expense_category_id' => $replacement->id,
                'category' => $replacement->name,
            ]);
        }

        $category->delete();

        return response()->json(['success' => true]);
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

    public function expenseStats(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'vehicle_id' => ['nullable', 'integer', 'exists:fleet_vehicles,id'],
        ]);

        $dateFrom = isset($validated['date_from'])
            ? Carbon::parse($validated['date_from'])->startOfDay()
            : now()->startOfMonth();
        $dateTo = isset($validated['date_to'])
            ? Carbon::parse($validated['date_to'])->endOfDay()
            : now()->endOfDay();

        if ($dateFrom->gt($dateTo)) {
            throw ValidationException::withMessages([
                'date_from' => ['Дата начала должна быть меньше или равна дате окончания'],
            ]);
        }

        $expensesQuery = VehicleExpense::with(['expenseCategory', 'vehicle'])
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->orderBy('expense_date');
        if (! empty($validated['vehicle_id'])) {
            $expensesQuery->where('fleet_vehicle_id', (int) $validated['vehicle_id']);
        }
        $expenses = $expensesQuery->get();

        $buildStat = function ($items) use ($dateFrom, $dateTo): array {
            $totalAmount = (float) $items->sum('amount');

            $byCategory = $items
                ->groupBy(function (VehicleExpense $expense) {
                    return (string) ($expense->expense_category_id ?? 0);
                })
                ->map(function ($rows) {
                    /** @var VehicleExpense $first */
                    $first = $rows->first();

                    return [
                        'category_id' => (int) ($first->expense_category_id ?? 0),
                        'category_name' => $first->expenseCategory?->name ?? $first->category,
                        'total_amount' => (float) $rows->sum('amount'),
                    ];
                })
                ->values()
                ->sortByDesc('total_amount')
                ->values()
                ->toArray();

            $dailyIndexed = $items
                ->groupBy('expense_date')
                ->map(function ($rows) {
                    return (float) $rows->sum('amount');
                });

            $line = [];
            for ($day = $dateFrom->copy()->startOfDay(); $day->lte($dateTo); $day->addDay()) {
                $dateKey = $day->toDateString();
                $line[] = [
                    'date' => $dateKey,
                    'total_amount' => (float) ($dailyIndexed[$dateKey] ?? 0),
                ];
            }

            return [
                'total_amount' => $totalAmount,
                'by_category' => $byCategory,
                'line' => $line,
            ];
        };

        $overall = $buildStat($expenses);

        $byVehicle = VehicleExpense::query()
            ->with('vehicle')
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw('fleet_vehicle_id, SUM(amount) as total_amount')
            ->groupBy('fleet_vehicle_id')
            ->orderByDesc('total_amount')
            ->get()
            ->map(function ($row) {
                $vehicle = $row->vehicle;

                return [
                    'vehicle_id' => (int) $row->fleet_vehicle_id,
                    'vehicle_label' => $vehicle
                        ? trim(sprintf('%s %s (%s)', $vehicle->brand, $vehicle->model, $vehicle->plate_number))
                        : ('Машина #' . (int) $row->fleet_vehicle_id),
                    'total_amount' => (float) $row->total_amount,
                ];
            })
            ->values()
            ->toArray();

        return [
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'vehicle_id' => isset($validated['vehicle_id']) ? (int) $validated['vehicle_id'] : null,
            'total_amount' => $overall['total_amount'],
            'by_category' => $overall['by_category'],
            'line' => $overall['line'],
            'vehicles' => $byVehicle,
        ];
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
        $validated = $request->validate([
            'fleet_vehicle_id' => ['required', 'integer', 'exists:fleet_vehicles,id'],
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $category = ExpenseCategory::find((int) $validated['expense_category_id']);
        if (! $category) {
            throw ValidationException::withMessages([
                'expense_category_id' => ['Категория расхода не найдена'],
            ]);
        }

        $validated['category'] = $category->name;

        return $validated;
    }

    private function validateExpenseCategory(Request $request, ?int $categoryId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('expense_categories', 'name')->ignore($categoryId)],
        ]);
    }
}
