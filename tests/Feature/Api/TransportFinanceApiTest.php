<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\DriverAdjustment;
use App\Models\DriverAdjustmentPart;
use App\Models\DriverDailyReport;
use App\Models\DriverPayout;
use App\Models\ExpenseCategory;
use App\Models\FleetVehicle;
use App\Models\Logistician;
use App\Models\LogisticianPayout;
use App\Models\VehicleExpense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class TransportFinanceApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('weeklySummary отклоняет не-понедельник')]
    public function test_weekly_summary_rejects_non_monday(): void
    {
        $response = $this->postJson('/api/transport-finance/weeklySummary', [
            'week_monday' => '2026-05-05',
        ]);

        $response->assertUnprocessable();
    }

    #[TestDox('weeklySummary: маршруты, ФОТ водителя, логист, расходы авто, чистая прибыль')]
    public function test_weekly_summary_aggregates(): void
    {
        Logistician::query()->delete();
        Logistician::create([
            'full_name' => 'Логист тест',
            'telegram' => null,
            'payout_start_date' => '2025-03-15',
            'payout_percent' => 5,
            'is_active' => true,
        ]);

        $vehicle = FleetVehicle::create([
            'brand' => 'GAZ',
            'model' => 'Next',
            'plate_number' => 'R111RR77',
            'tonnage' => 2,
            'ownership_type' => 'rented',
            'rent_per_day' => 1000,
        ]);

        $driver = Driver::create(['full_name' => 'Иванов', 'fleet_vehicle_id' => $vehicle->id]);

        DriverDailyReport::create([
            'driver_id' => $driver->id,
            'fleet_vehicle_id' => $vehicle->id,
            'report_date' => '2026-05-04',
            'work_hours' => 10,
            'night_loading' => false,
            'manual_floor_lift' => false,
            'route_sheet_total' => 10000,
        ]);

        $penalty = DriverAdjustment::create([
            'driver_id' => $driver->id,
            'adjustment_type' => 'penalty',
            'event_date' => '2026-05-01',
            'total_amount' => 1000,
            'comment' => 'Штраф тест',
            'status' => 'open',
        ]);
        DriverAdjustmentPart::create([
            'driver_adjustment_id' => $penalty->id,
            'part_no' => 1,
            'amount' => 1000,
            'due_date' => '2026-05-06',
            'is_applied' => false,
            'comment' => null,
        ]);

        DriverAdjustment::create([
            'driver_id' => $driver->id,
            'adjustment_type' => 'bonus',
            'event_date' => '2026-05-07',
            'total_amount' => 500,
            'comment' => 'Бонус тест',
            'status' => 'closed',
        ]);

        $cat = ExpenseCategory::create(['name' => 'Топливо']);
        VehicleExpense::create([
            'fleet_vehicle_id' => $vehicle->id,
            'expense_category_id' => $cat->id,
            'expense_date' => '2026-05-05',
            'category' => $cat->name,
            'amount' => 200,
            'comment' => null,
        ]);

        $response = $this->postJson('/api/transport-finance/weeklySummary', [
            'week_monday' => '2026-05-04',
        ]);

        $response->assertOk();
        $response->assertJsonPath('route_sheets_total', 10000);
        // 45% * 10000 - 1000 штраф + 500 бонус = 4000
        $response->assertJsonPath('drivers_payroll', 4000);
        $response->assertJsonPath('logistician_payroll.amount', 500);
        $response->assertJsonPath('after_payroll', 5500);
        // manual 200 + rent 1000 * 1 день использования
        $response->assertJsonPath('vehicle_expenses_total', 1200);
        $response->assertJsonPath('net_profit', 4300);
    }

    #[TestDox('payDriver создаёт выплату и помечает части штрафа применёнными; unpayDriver откатывает')]
    public function test_pay_and_unpay_driver(): void
    {
        $driver = Driver::create(['full_name' => 'Петров']);

        DriverDailyReport::create([
            'driver_id' => $driver->id,
            'fleet_vehicle_id' => null,
            'report_date' => '2026-05-04',
            'work_hours' => 8,
            'night_loading' => false,
            'manual_floor_lift' => false,
            'route_sheet_total' => 10000,
        ]);

        $penalty = DriverAdjustment::create([
            'driver_id' => $driver->id,
            'adjustment_type' => 'penalty',
            'event_date' => '2026-05-01',
            'total_amount' => 500,
            'comment' => 'Часть в неделе',
            'status' => 'open',
        ]);
        $part = DriverAdjustmentPart::create([
            'driver_adjustment_id' => $penalty->id,
            'part_no' => 1,
            'amount' => 500,
            'due_date' => '2026-05-05',
            'is_applied' => false,
            'comment' => null,
        ]);

        $weekMonday = '2026-05-04';
        $this->assertFalse((bool) $part->fresh()->is_applied);

        $pay = $this->postJson('/api/transport-finance/payDriver', [
            'driver_id' => $driver->id,
            'week_monday' => $weekMonday,
        ]);
        $pay->assertCreated();
        $this->assertTrue(
            DriverPayout::query()
                ->where('driver_id', $driver->id)
                ->whereDate('week_monday', $weekMonday)
                ->exists()
        );
        $this->assertTrue((bool) $part->fresh()->is_applied);
        $this->assertSame('closed', $penalty->fresh()->status);

        $unpay = $this->postJson('/api/transport-finance/unpayDriver', [
            'driver_id' => $driver->id,
            'week_monday' => $weekMonday,
        ]);
        $unpay->assertOk();
        $this->assertFalse(
            DriverPayout::query()
                ->where('driver_id', $driver->id)
                ->whereDate('week_monday', $weekMonday)
                ->exists()
        );
        $this->assertFalse((bool) $part->fresh()->is_applied);
        $this->assertSame('open', $penalty->fresh()->status);
    }

    #[TestDox('payDriver запрещён для будущей недели')]
    public function test_pay_driver_rejects_future_week(): void
    {
        $driver = Driver::create(['full_name' => 'Будущий']);

        $futureMonday = Carbon::now()->addWeeks(5)->startOfWeek(Carbon::MONDAY)->toDateString();

        $response = $this->postJson('/api/transport-finance/payDriver', [
            'driver_id' => $driver->id,
            'week_monday' => $futureMonday,
        ]);

        $response->assertUnprocessable();
    }

    #[TestDox('payLogistician и unpayLogistician')]
    public function test_pay_and_unpay_logistician(): void
    {
        Logistician::query()->delete();
        $log = Logistician::create([
            'full_name' => 'Логист 2',
            'payout_start_date' => '2025-01-01',
            'payout_percent' => 5,
            'is_active' => true,
        ]);

        $monday = Carbon::now()->subWeeks(5)->startOfWeek(Carbon::MONDAY);
        $weekMonday = $monday->toDateString();
        $reportDate = $monday->copy()->addDays(2)->toDateString();

        $driver = Driver::create(['full_name' => 'Водитель']);
        DriverDailyReport::create([
            'driver_id' => $driver->id,
            'report_date' => $reportDate,
            'work_hours' => 8,
            'night_loading' => false,
            'manual_floor_lift' => false,
            'route_sheet_total' => 20000,
        ]);

        $pay = $this->postJson('/api/transport-finance/payLogistician', [
            'week_monday' => $weekMonday,
        ]);
        $pay->assertCreated();
        $this->assertTrue(
            LogisticianPayout::query()
                ->where('logistician_id', $log->id)
                ->whereDate('week_monday', $weekMonday)
                ->exists()
        );

        $unpay = $this->postJson('/api/transport-finance/unpayLogistician', [
            'week_monday' => $weekMonday,
        ]);
        $unpay->assertOk();
        $this->assertFalse(
            LogisticianPayout::query()
                ->where('logistician_id', $log->id)
                ->whereDate('week_monday', $weekMonday)
                ->exists()
        );
    }

    #[TestDox('driverDayBreakdown возвращает 7 дней')]
    public function test_driver_day_breakdown(): void
    {
        $driver = Driver::create(['full_name' => 'Дни']);

        DriverDailyReport::create([
            'driver_id' => $driver->id,
            'report_date' => '2026-05-06',
            'work_hours' => 8,
            'night_loading' => false,
            'manual_floor_lift' => false,
            'route_sheet_total' => 1000,
        ]);

        $response = $this->postJson('/api/transport-finance/driverDayBreakdown', [
            'driver_id' => $driver->id,
            'week_monday' => '2026-05-04',
        ]);

        $response->assertOk();
        $this->assertCount(7, $response->json('days'));
        $wed = collect($response->json('days'))->firstWhere('date', '2026-05-06');
        $this->assertNotNull($wed);
        $this->assertEquals(1000, $wed['route_total']);
        $this->assertEquals(450, $wed['accrual']);
    }

    #[TestDox('невыплаченные суммы водителя переносятся в следующую неделю')]
    public function test_driver_carry_over_between_weeks(): void
    {
        $weekOld = Carbon::now()->subWeeks(7)->startOfWeek(Carbon::MONDAY);
        $weekNext = $weekOld->copy()->addWeek();
        $weekAfter = $weekNext->copy()->addWeek();
        $driver = Driver::create(['full_name' => 'Перенос Водитель']);
        DriverDailyReport::create([
            'driver_id' => $driver->id,
            'report_date' => $weekOld->toDateString(),
            'work_hours' => 8,
            'night_loading' => false,
            'manual_floor_lift' => false,
            'route_sheet_total' => 10000,
        ]);

        $response = $this->postJson('/api/transport-finance/weeklySummary', [
            'week_monday' => $weekNext->toDateString(),
        ]);
        $response->assertOk();
        $driverRow = collect($response->json('drivers'))->firstWhere('id', $driver->id);
        $this->assertNotNull($driverRow);
        $this->assertEquals(4500, $driverRow['carry_over']);
        $this->assertEquals(4500, $driverRow['payable']);

        $this->postJson('/api/transport-finance/payDriver', [
            'driver_id' => $driver->id,
            'week_monday' => $weekNext->toDateString(),
        ])->assertCreated();

        $after = $this->postJson('/api/transport-finance/weeklySummary', [
            'week_monday' => $weekAfter->toDateString(),
        ]);
        $after->assertOk();
        $driverAfter = collect($after->json('drivers'))->firstWhere('id', $driver->id);
        $this->assertNull($driverAfter);
    }

    #[TestDox('невыплаченные суммы логиста переносятся в следующую неделю')]
    public function test_logistician_carry_over_between_weeks(): void
    {
        $weekOld = Carbon::now()->subWeeks(7)->startOfWeek(Carbon::MONDAY);
        $weekNext = $weekOld->copy()->addWeek();
        Logistician::query()->delete();
        Logistician::create([
            'full_name' => 'Перенос Логист',
            'payout_start_date' => '2025-01-01',
            'payout_percent' => 5,
            'is_active' => true,
        ]);
        $driver = Driver::create(['full_name' => 'Водитель']);
        DriverDailyReport::create([
            'driver_id' => $driver->id,
            'report_date' => $weekOld->toDateString(),
            'work_hours' => 8,
            'night_loading' => false,
            'manual_floor_lift' => false,
            'route_sheet_total' => 10000,
        ]);

        $summary = $this->postJson('/api/transport-finance/weeklySummary', [
            'week_monday' => $weekNext->toDateString(),
        ]);
        $summary->assertOk();
        $this->assertEquals(500, $summary->json('logistician_payroll.carry_over'));
        $this->assertEquals(500, $summary->json('logistician_payroll.amount'));
    }
}
