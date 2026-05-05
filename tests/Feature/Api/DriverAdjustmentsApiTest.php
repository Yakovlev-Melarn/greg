<?php

namespace Tests\Feature\Api;

use App\Models\Driver;
use App\Models\DriverAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class DriverAdjustmentsApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('Создание надбавки требует комментарий и не принимает части')]
    public function test_store_bonus_requires_comment_and_disallows_parts(): void
    {
        $driver = Driver::create(['full_name' => 'Иван']);

        $bad = $this->postJson('/api/driver-adjustments/store', [
            'driver_id' => $driver->id,
            'adjustment_type' => 'bonus',
            'event_date' => '2026-05-01',
            'total_amount' => 1000,
            'comment' => '',
            'parts' => [['amount' => 10, 'due_date' => '2026-05-02']],
        ]);
        $bad->assertStatus(422);
        $bad->assertJsonValidationErrors(['comment']);

        $ok = $this->postJson('/api/driver-adjustments/store', [
            'driver_id' => $driver->id,
            'adjustment_type' => 'bonus',
            'event_date' => '2026-05-01',
            'total_amount' => 1000,
            'comment' => 'Премия за переработку',
        ]);
        $ok->assertCreated()
            ->assertJsonPath('adjustment_type', 'bonus')
            ->assertJsonPath('status', 'closed');
    }

    #[TestDox('Создание штрафа с частями и проверкой суммы частей')]
    public function test_store_penalty_requires_parts_with_matching_sum(): void
    {
        $driver = Driver::create(['full_name' => 'Петр']);

        $invalid = $this->postJson('/api/driver-adjustments/store', [
            'driver_id' => $driver->id,
            'adjustment_type' => 'penalty',
            'event_date' => '2026-05-03',
            'total_amount' => 1000,
            'comment' => 'Штраф за опоздание',
            'parts' => [
                ['amount' => 500, 'due_date' => '2026-05-10'],
                ['amount' => 400, 'due_date' => '2026-05-20'],
            ],
        ]);
        $invalid->assertStatus(422)->assertJsonValidationErrors(['parts']);

        $valid = $this->postJson('/api/driver-adjustments/store', [
            'driver_id' => $driver->id,
            'adjustment_type' => 'penalty',
            'event_date' => '2026-05-03',
            'total_amount' => 1000,
            'comment' => 'Штраф за опоздание',
            'parts' => [
                ['amount' => 500, 'due_date' => '2026-05-10', 'comment' => '1 часть', 'is_applied' => false],
                ['amount' => 500, 'due_date' => '2026-05-20', 'comment' => '2 часть', 'is_applied' => true],
            ],
        ]);

        $valid->assertCreated()
            ->assertJsonPath('adjustment_type', 'penalty')
            ->assertJsonPath('status', 'open');
        $this->assertCount(2, $valid->json('parts'));
    }

    #[TestDox('Вложения сохраняются и видны в деталях')]
    public function test_attachments_are_saved_and_returned_in_show(): void
    {
        Storage::fake('public');
        $driver = Driver::create(['full_name' => 'Сергей']);

        $store = $this->post('/api/driver-adjustments/store', [
            'driver_id' => $driver->id,
            'adjustment_type' => 'bonus',
            'event_date' => '2026-05-05',
            'total_amount' => 700,
            'comment' => 'Надбавка с фото',
            'attachments' => [
                UploadedFile::fake()->create('a.jpg', 50, 'image/jpeg'),
                UploadedFile::fake()->create('b.jpg', 50, 'image/jpeg'),
            ],
        ]);
        $store->assertCreated();

        $id = (int) $store->json('id');
        $show = $this->postJson('/api/driver-adjustments/show', ['id' => $id]);
        $show->assertOk();
        $this->assertCount(2, $show->json('attachments'));
        $this->assertEquals(2, $show->json('attachments_count'));
    }

    #[TestDox('Список поддерживает курсорную пагинацию и summary')]
    public function test_list_cursor_pagination_and_summary(): void
    {
        $driver = Driver::create(['full_name' => 'Алексей']);

        foreach (range(1, 35) as $i) {
            DriverAdjustment::create([
                'driver_id' => $driver->id,
                'adjustment_type' => $i % 2 === 0 ? 'bonus' : 'penalty',
                'event_date' => '2026-05-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'total_amount' => 100 + $i,
                'comment' => 'Запись '.$i,
                'status' => $i % 2 === 0 ? 'closed' : 'open',
            ]);
        }

        $first = $this->postJson('/api/driver-adjustments/list', [
            'limit' => 20,
        ]);
        $first->assertOk();
        $this->assertCount(20, $first->json('items'));
        $this->assertTrue($first->json('has_more'));
        $this->assertNotEmpty($first->json('next_cursor'));

        $second = $this->postJson('/api/driver-adjustments/list', [
            'limit' => 20,
            'cursor' => $first->json('next_cursor'),
        ]);
        $second->assertOk();
        $this->assertNotEmpty($second->json('items'));

        $summary = $this->postJson('/api/driver-adjustments/summary', [
            'driver_id' => $driver->id,
        ]);
        $summary->assertOk();
        $this->assertEquals(35, $summary->json('total_count'));
    }
}
