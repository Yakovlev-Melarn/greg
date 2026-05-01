<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\FleetVehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class DriversApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('CRUD водителя и привязка к машине работают')]
    public function test_driver_crud_and_vehicle_assignment(): void
    {
        $vehicle = FleetVehicle::create([
            'brand' => 'MAN',
            'model' => 'TGS',
            'plate_number' => 'X111XX77',
            'tonnage' => 18,
            'ownership_type' => 'owned',
        ]);

        $create = $this->postJson('/api/drivers/store', [
            'full_name' => 'Иванов Иван',
            'phone' => '+79990001122',
            'fleet_vehicle_id' => $vehicle->id,
            'notes' => 'Стажёр',
        ]);
        $create->assertCreated()->assertJsonPath('full_name', 'Иванов Иван');
        $driverId = (int) $create->json('id');

        $list = $this->postJson('/api/drivers/list');
        $list->assertOk()->assertJsonFragment([
            'id' => $driverId,
            'full_name' => 'Иванов Иван',
            'vehicle_label' => 'MAN TGS (X111XX77)',
        ]);

        $update = $this->postJson('/api/drivers/update', [
            'id' => $driverId,
            'full_name' => 'Иванов И.И.',
            'phone' => '+79990001133',
            'fleet_vehicle_id' => null,
            'notes' => null,
        ]);
        $update->assertOk()->assertJsonPath('full_name', 'Иванов И.И.');
        $update->assertJsonPath('fleet_vehicle_id', null);

        $delete = $this->postJson('/api/drivers/destroy', ['id' => $driverId]);
        $delete->assertOk()->assertJsonPath('success', true);
    }

    #[TestDox('При закреплении машины за водителем она снимается с другого водителя')]
    public function test_vehicle_reassignment_clears_previous_driver(): void
    {
        $vehicle = FleetVehicle::create([
            'brand' => 'Volvo',
            'model' => 'FH',
            'plate_number' => 'Y222YY77',
            'tonnage' => 20,
            'ownership_type' => 'owned',
        ]);

        $this->postJson('/api/drivers/store', [
            'full_name' => 'Первый',
            'fleet_vehicle_id' => $vehicle->id,
        ])->assertCreated();

        $second = $this->postJson('/api/drivers/store', [
            'full_name' => 'Второй',
            'fleet_vehicle_id' => $vehicle->id,
        ]);
        $second->assertCreated();

        $this->assertDatabaseHas('drivers', [
            'full_name' => 'Второй',
            'fleet_vehicle_id' => $vehicle->id,
        ]);
        $this->assertDatabaseHas('drivers', [
            'full_name' => 'Первый',
            'fleet_vehicle_id' => null,
        ]);
    }
}
