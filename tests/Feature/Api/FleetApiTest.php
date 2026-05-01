<?php

namespace Tests\Feature\Api;

use App\Models\ExpenseCategory;
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

        $gsm = $this->postJson('/api/fleet/expenseCategoryStore', [
            'name' => 'ГСМ',
        ]);
        $gsm->assertCreated();
        $gsmId = (int) $gsm->json('id');

        $repair = $this->postJson('/api/fleet/expenseCategoryStore', [
            'name' => 'Ремонт',
        ]);
        $repair->assertCreated();
        $repairId = (int) $repair->json('id');

        $createExpense = $this->postJson('/api/fleet/expenseStore', [
            'fleet_vehicle_id' => $vehicleId,
            'expense_date' => '2026-04-30',
            'expense_category_id' => $gsmId,
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
            'expense_category_id' => $repairId,
            'amount' => 1000,
            'comment' => 'Расходники',
        ]);
        $updateExpense->assertOk()->assertJsonPath('category', 'Ремонт');

        $deleteExpense = $this->postJson('/api/fleet/expenseDestroy', ['id' => $expenseId]);
        $deleteExpense->assertOk()->assertJsonPath('success', true);

        $deleteVehicle = $this->postJson('/api/fleet/destroy', ['id' => $vehicleId]);
        $deleteVehicle->assertOk()->assertJsonPath('success', true);
    }

    #[TestDox('Переименование статьи расходов обновляет денормализованное поле category у расходов')]
    public function test_expense_category_rename_updates_linked_expenses(): void
    {
        $vehicle = FleetVehicle::create([
            'brand' => 'Sitrak',
            'model' => 'C7H',
            'plate_number' => 'F666FF77',
            'tonnage' => 18,
            'ownership_type' => 'owned',
        ]);

        $category = ExpenseCategory::create(['name' => 'Старое имя']);
        $expense = VehicleExpense::create([
            'fleet_vehicle_id' => $vehicle->id,
            'expense_category_id' => $category->id,
            'expense_date' => '2026-05-03',
            'category' => $category->name,
            'amount' => 100,
        ]);

        $this->postJson('/api/fleet/expenseCategoryUpdate', [
            'id' => $category->id,
            'name' => 'Новое имя',
        ])->assertOk();

        $this->assertDatabaseHas('vehicle_expenses', [
            'id' => $expense->id,
            'expense_category_id' => $category->id,
            'category' => 'Новое имя',
        ]);
    }

    #[TestDox('Удаление статьи с расходами требует переноса в другую статью')]
    public function test_expense_category_delete_reassigns_expenses(): void
    {
        $vehicle = FleetVehicle::create([
            'brand' => 'Sitrak',
            'model' => 'C7H',
            'plate_number' => 'G777GG77',
            'tonnage' => 18,
            'ownership_type' => 'owned',
        ]);

        $from = ExpenseCategory::create(['name' => 'Удаляемая']);
        $to = ExpenseCategory::create(['name' => 'Целевая']);

        $expense = VehicleExpense::create([
            'fleet_vehicle_id' => $vehicle->id,
            'expense_category_id' => $from->id,
            'expense_date' => '2026-05-04',
            'category' => $from->name,
            'amount' => 250,
        ]);

        $this->postJson('/api/fleet/expenseCategoryDestroy', [
            'id' => $from->id,
            'replacement_id' => $to->id,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseMissing('expense_categories', ['id' => $from->id]);
        $this->assertDatabaseHas('vehicle_expenses', [
            'id' => $expense->id,
            'expense_category_id' => $to->id,
            'category' => 'Целевая',
        ]);
    }

    #[TestDox('Статистика расходов за период агрегирует суммы по статьям и по дням')]
    public function test_expense_stats_for_custom_period(): void
    {
        $vehicle = FleetVehicle::create([
            'brand' => 'Sitrak',
            'model' => 'C7H',
            'plate_number' => 'H888HH77',
            'tonnage' => 18,
            'ownership_type' => 'owned',
        ]);
        $vehicleWithoutExpenses = FleetVehicle::create([
            'brand' => 'MAN',
            'model' => 'TGX',
            'plate_number' => 'K999KK77',
            'tonnage' => 20,
            'ownership_type' => 'owned',
        ]);

        $catGsm = ExpenseCategory::create(['name' => 'ГСМ']);
        $catRepair = ExpenseCategory::create(['name' => 'Ремонт']);

        VehicleExpense::create([
            'fleet_vehicle_id' => $vehicle->id,
            'expense_category_id' => $catGsm->id,
            'expense_date' => '2026-05-01',
            'category' => $catGsm->name,
            'amount' => 100,
        ]);
        VehicleExpense::create([
            'fleet_vehicle_id' => $vehicle->id,
            'expense_category_id' => $catRepair->id,
            'expense_date' => '2026-05-01',
            'category' => $catRepair->name,
            'amount' => 200,
        ]);
        VehicleExpense::create([
            'fleet_vehicle_id' => $vehicle->id,
            'expense_category_id' => $catGsm->id,
            'expense_date' => '2026-05-02',
            'category' => $catGsm->name,
            'amount' => 50,
        ]);

        $response = $this->postJson('/api/fleet/expenseStats', [
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-02',
        ]);

        $response->assertOk();
        $this->assertEquals(350.0, (float) $response->json('total_amount'));

        $byCategory = $response->json('by_category');
        $this->assertSame('Ремонт', $byCategory[0]['category_name']);
        $this->assertEquals(200.0, (float) $byCategory[0]['total_amount']);
        $this->assertSame('ГСМ', $byCategory[1]['category_name']);
        $this->assertEquals(150.0, (float) $byCategory[1]['total_amount']);

        $line = $response->json('line');
        $this->assertCount(2, $line);
        $this->assertSame('2026-05-01', $line[0]['date']);
        $this->assertEquals(300.0, (float) $line[0]['total_amount']);
        $this->assertSame('2026-05-02', $line[1]['date']);
        $this->assertEquals(50.0, (float) $line[1]['total_amount']);

        $vehicles = $response->json('vehicles');
        $this->assertCount(1, $vehicles);
        $mainVehicle = collect($vehicles)->firstWhere('vehicle_id', $vehicle->id);
        $this->assertNotNull($mainVehicle);
        $this->assertEquals(350.0, (float) $mainVehicle['total_amount']);

        $filtered = $this->postJson('/api/fleet/expenseStats', [
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-02',
            'vehicle_id' => $vehicleWithoutExpenses->id,
        ]);
        $filtered->assertOk();
        $this->assertEquals(0.0, (float) $filtered->json('total_amount'));
        $this->assertSame($vehicleWithoutExpenses->id, (int) $filtered->json('vehicle_id'));
    }

    #[TestDox('Статистика расходов отклоняет период если дата начала позже даты окончания')]
    public function test_expense_stats_rejects_inverted_date_range(): void
    {
        $response = $this->postJson('/api/fleet/expenseStats', [
            'date_from' => '2026-05-10',
            'date_to' => '2026-05-05',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['date_from']);
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

        $category = ExpenseCategory::create(['name' => 'Прочее']);
        $expense = VehicleExpense::create([
            'fleet_vehicle_id' => $vehicle->id,
            'expense_category_id' => $category->id,
            'expense_date' => '2026-05-02',
            'category' => $category->name,
            'amount' => 500,
        ]);

        $this->postJson('/api/fleet/destroy', ['id' => $vehicle->id])->assertOk();

        $this->assertDatabaseMissing('fleet_vehicles', ['id' => $vehicle->id]);
        $this->assertDatabaseMissing('vehicle_expenses', ['id' => $expense->id]);
    }
}
