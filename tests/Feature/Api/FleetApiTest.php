<?php

namespace Tests\Feature\Api;

use App\Models\FleetVehicle;
use App\Models\TransportCompany;
use App\Models\VehicleExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class FleetApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('CRUD транспортной компании работает корректно')]
    public function test_transport_company_crud(): void
    {
        $create = $this->postJson('/api/transport-companies/store', [
            'name' => 'ТК Север',
        ]);
        $create->assertCreated()->assertJsonPath('name', 'ТК Север');

        $companyId = (int) $create->json('id');

        $list = $this->postJson('/api/transport-companies/list');
        $list->assertOk()->assertJsonFragment(['name' => 'ТК Север']);

        $update = $this->postJson('/api/transport-companies/update', [
            'id' => $companyId,
            'name' => 'ТК Восток',
        ]);
        $update->assertOk()->assertJsonPath('name', 'ТК Восток');

        $delete = $this->postJson('/api/transport-companies/destroy', [
            'id' => $companyId,
        ]);
        $delete->assertOk()->assertJsonPath('success', true);
    }

    #[TestDox('Транспортную компанию нельзя удалить если к ней привязаны машины')]
    public function test_transport_company_cannot_be_deleted_when_has_vehicles(): void
    {
        $company = TransportCompany::create(['name' => 'ТК Нельзя удалить']);
        FleetVehicle::create([
            'transport_company_id' => $company->id,
            'brand' => 'Volvo',
            'model' => 'FH',
            'plate_number' => 'A111AA77',
            'tonnage' => 20,
            'ownership_type' => 'owned',
        ]);

        $response = $this->postJson('/api/transport-companies/destroy', ['id' => $company->id]);

        $response->assertStatus(422)->assertJsonPath('message', 'Нельзя удалить ТК с привязанными машинами');
    }

    #[TestDox('CRUD машины и расходов выполняется успешно')]
    public function test_fleet_vehicle_and_expenses_crud(): void
    {
        $company = TransportCompany::create(['name' => 'ТК Логистика']);

        $createVehicle = $this->postJson('/api/fleet/store', [
            'brand' => 'MAN',
            'model' => 'TGS',
            'plate_number' => 'B222BB77',
            'tonnage' => 15.5,
            'ownership_type' => 'rented',
            'rent_per_day' => 7000,
            'transport_company_id' => $company->id,
        ]);
        $createVehicle->assertCreated()->assertJsonPath('plate_number', 'B222BB77');
        $vehicleId = (int) $createVehicle->json('id');

        $list = $this->postJson('/api/fleet/list');
        $list->assertOk()->assertJsonFragment([
            'plate_number' => 'B222BB77',
            'transport_company_name' => 'ТК Логистика',
        ]);

        $updateVehicle = $this->postJson('/api/fleet/update', [
            'id' => $vehicleId,
            'brand' => 'MAN',
            'model' => 'TGX',
            'plate_number' => 'B222BB77',
            'tonnage' => 16,
            'ownership_type' => 'owned',
            'transport_company_id' => $company->id,
        ]);
        $updateVehicle->assertOk()->assertJsonPath('model', 'TGX');
        $updateVehicle->assertJsonPath('rent_per_day', null);

        $createExpense = $this->postJson('/api/fleet/expenseStore', [
            'fleet_vehicle_id' => $vehicleId,
            'expense_date' => '2026-04-30',
            'category' => 'ГСМ',
            'amount' => 3500.50,
            'comment' => 'Заправка',
        ]);
        $createExpense->assertCreated()->assertJsonPath('category', 'ГСМ');
        $expenseId = (int) $createExpense->json('id');

        $expenseList = $this->postJson('/api/fleet/expensesList', ['vehicle_id' => $vehicleId]);
        $expenseList->assertOk()->assertJsonFragment(['id' => $expenseId, 'category' => 'ГСМ']);

        $updateExpense = $this->postJson('/api/fleet/expenseUpdate', [
            'id' => $expenseId,
            'fleet_vehicle_id' => $vehicleId,
            'expense_date' => '2026-05-01',
            'category' => 'Ремонт',
            'amount' => 1000,
            'comment' => 'Расходники',
        ]);
        $updateExpense->assertOk()->assertJsonPath('category', 'Ремонт');

        $deleteExpense = $this->postJson('/api/fleet/expenseDestroy', ['id' => $expenseId]);
        $deleteExpense->assertOk()->assertJsonPath('success', true);

        $deleteVehicle = $this->postJson('/api/fleet/destroy', ['id' => $vehicleId]);
        $deleteVehicle->assertOk()->assertJsonPath('success', true);
    }

    #[TestDox('Валидация машины проверяет уникальность госномера и аренду для rented')]
    public function test_vehicle_validation_for_unique_plate_and_rent_for_rented_type(): void
    {
        FleetVehicle::create([
            'brand' => 'Kamaz',
            'model' => '6520',
            'plate_number' => 'C333CC77',
            'tonnage' => 12,
            'ownership_type' => 'owned',
        ]);

        $duplicatePlate = $this->postJson('/api/fleet/store', [
            'brand' => 'Scania',
            'model' => 'R500',
            'plate_number' => 'C333CC77',
            'tonnage' => 14,
            'ownership_type' => 'owned',
        ]);
        $duplicatePlate->assertStatus(422)->assertJsonValidationErrors(['plate_number']);

        $rentMissing = $this->postJson('/api/fleet/store', [
            'brand' => 'Scania',
            'model' => 'R500',
            'plate_number' => 'D444DD77',
            'tonnage' => 14,
            'ownership_type' => 'rented',
        ]);
        $rentMissing->assertStatus(422)->assertJsonValidationErrors(['rent_per_day']);
    }

    #[TestDox('При удалении машины удаляются и ее расходы')]
    public function test_vehicle_expense_is_deleted_with_vehicle(): void
    {
        $vehicle = FleetVehicle::create([
            'brand' => 'Sitrak',
            'model' => 'C7H',
            'plate_number' => 'E555EE77',
            'tonnage' => 18,
            'ownership_type' => 'owned',
        ]);

        $expense = VehicleExpense::create([
            'fleet_vehicle_id' => $vehicle->id,
            'expense_date' => '2026-05-02',
            'category' => 'Прочее',
            'amount' => 500,
        ]);

        $this->postJson('/api/fleet/destroy', ['id' => $vehicle->id])->assertOk();

        $this->assertDatabaseMissing('fleet_vehicles', ['id' => $vehicle->id]);
        $this->assertDatabaseMissing('vehicle_expenses', ['id' => $expense->id]);
    }
}
