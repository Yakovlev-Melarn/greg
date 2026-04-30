<?php

namespace App\Http\Controllers\Api;

use App\Models\TransportCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransportCompanies
{
    public function list(): array
    {
        return TransportCompany::orderBy('name')
            ->get()
            ->toArray();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:transport_companies,name'],
        ]);

        $company = TransportCompany::create($validated);

        return response()->json($company, 201);
    }

    public function update(Request $request): JsonResponse
    {
        $companyId = (int) $request->input('id');
        $company = TransportCompany::find($companyId);

        if (! $company) {
            return response()->json(['message' => 'Транспортная компания не найдена'], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('transport_companies', 'name')->ignore($company->id)],
        ]);

        $company->update($validated);

        return response()->json($company);
    }

    public function destroy(Request $request): JsonResponse
    {
        $companyId = (int) $request->input('id');
        $company = TransportCompany::withCount('vehicles')->find($companyId);

        if (! $company) {
            return response()->json(['message' => 'Транспортная компания не найдена'], 404);
        }

        if ($company->vehicles_count > 0) {
            return response()->json(['message' => 'Нельзя удалить ТК с привязанными машинами'], 422);
        }

        $company->delete();

        return response()->json(['success' => true]);
    }
}
