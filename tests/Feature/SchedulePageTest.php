<?php

namespace Tests\Feature;

use App\Http\Middleware\SessionMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_page_is_available(): void
    {
        $this->withoutMiddleware(SessionMiddleware::class);
        $response = $this->get('/schedule');

        $response->assertOk();
        $response->assertSee('График');
        $response->assertSee('scheduleDaysGrid');
        $response->assertSee('scheduleMonthlyPieChart');
        $response->assertSee('/assets/js/Schedule/index.js');
    }
}
