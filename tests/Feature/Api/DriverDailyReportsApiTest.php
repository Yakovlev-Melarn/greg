<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\DriverDailyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class DriverDailyReportsApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('Создание отчёта: ночная погрузка без суммы получает значение по умолчанию 3000')]
    public function test_store_sets_default_night_amount_when_flag_true(): void
    {
        $driver = Driver::create(['full_name' => 'Тестовый']);

        $response = $this->postJson('/api/driver-daily-reports/store', [
            'driver_id' => $driver->id,
            'report_date' => '2026-05-15',
            'work_hours' => 8,
            'extra_work_hours' => 1,
            'night_loading' => true,
            'manual_floor_lift' => false,
            'route_sheet_total' => 12000,
        ]);

        $response->assertCreated();
        $this->assertEquals(3000, $response->json('night_loading_amount'));

        $this->assertDatabaseHas('driver_daily_reports', [
            'driver_id' => $driver->id,
            'night_loading' => 1,
            'night_loading_amount' => 3000,
            'manual_floor_lift' => 0,
            'manual_floor_lift_amount' => null,
            'route_sheet_total' => 12000,
        ]);
        $this->assertTrue(
            DriverDailyReport::query()
                ->where('driver_id', $driver->id)
                ->whereDate('report_date', '2026-05-15')
                ->exists()
        );
    }

    #[TestDox('Список отчётов за календарную неделю (пн–вс) и фильтр по водителю')]
    public function test_list_by_week_and_driver_filter(): void
    {
        $d1 = Driver::create(['full_name' => 'А']);
        $d2 = Driver::create(['full_name' => 'Б']);

        DriverDailyReport::create([
            'driver_id' => $d1->id,
            'report_date' => '2026-05-01',
            'work_hours' => 10,
            'night_loading' => false,
            'manual_floor_lift' => false,
        ]);
        DriverDailyReport::create([
            'driver_id' => $d2->id,
            'report_date' => '2026-05-02',
            'work_hours' => 9,
            'night_loading' => false,
            'manual_floor_lift' => false,
        ]);
        DriverDailyReport::create([
            'driver_id' => $d2->id,
            'report_date' => '2026-06-01',
            'work_hours' => 8,
            'night_loading' => false,
            'manual_floor_lift' => false,
        ]);

        $week = $this->postJson('/api/driver-daily-reports/list', [
            'week_monday' => '2026-04-27',
        ]);
        $week->assertOk();
        $this->assertCount(2, $week->json());

        $filter = $this->postJson('/api/driver-daily-reports/list', [
            'week_monday' => '2026-04-27',
            'driver_id' => $d2->id,
        ]);
        $filter->assertOk();
        $this->assertCount(1, $filter->json());
        $this->assertSame('Б', $filter->json('0.driver_name'));
    }

    #[TestDox('Обновление и удаление отчёта')]
    public function test_update_and_destroy(): void
    {
        $driver = Driver::create(['full_name' => 'Водитель']);

        $store = $this->postJson('/api/driver-daily-reports/store', [
            'driver_id' => $driver->id,
            'report_date' => '2026-05-10',
            'work_hours' => 8,
            'night_loading' => false,
            'manual_floor_lift' => false,
        ]);
        $store->assertCreated();
        $id = (int) $store->json('id');

        $update = $this->postJson('/api/driver-daily-reports/update', [
            'id' => $id,
            'driver_id' => $driver->id,
            'report_date' => '2026-05-10',
            'work_hours' => 9.5,
            'extra_work_hours' => 2,
            'night_loading' => true,
            'night_loading_amount' => 4500,
            'manual_floor_lift' => true,
            'manual_floor_lift_amount' => 500,
            'route_sheet_total' => 20000,
        ]);
        $update->assertOk()
            ->assertJsonPath('work_hours', 9.5)
            ->assertJsonPath('night_loading_amount', 4500)
            ->assertJsonPath('manual_floor_lift_amount', 500);

        $destroy = $this->postJson('/api/driver-daily-reports/destroy', ['id' => $id]);
        $destroy->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseMissing('driver_daily_reports', ['id' => $id]);
    }

    #[TestDox('Нельзя создать второй отчёт на ту же дату для того же водителя')]
    public function test_duplicate_driver_date_returns_validation_error(): void
    {
        $driver = Driver::create(['full_name' => 'Дублёр']);

        $this->postJson('/api/driver-daily-reports/store', [
            'driver_id' => $driver->id,
            'report_date' => '2026-05-20',
            'night_loading' => false,
            'manual_floor_lift' => false,
        ])->assertCreated();

        $dup = $this->postJson('/api/driver-daily-reports/store', [
            'driver_id' => $driver->id,
            'report_date' => '2026-05-20',
            'night_loading' => false,
            'manual_floor_lift' => false,
        ]);

        $dup->assertStatus(422);
    }

    #[TestDox('Список: week_monday должен быть понедельником')]
    public function test_list_rejects_non_monday_week_start(): void
    {
        $this->postJson('/api/driver-daily-reports/list', [
            'week_monday' => '2026-04-28',
        ])->assertStatus(422);
    }
}
