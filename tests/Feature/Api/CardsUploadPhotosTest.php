<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class CardsUploadPhotosTest extends TestCase
{
    use RefreshDatabase;

    #[TestDox('uploadPhotos API возвращает ошибку если карточка не найдена')]
    public function test_upload_photos_returns_error_when_card_missing(): void
    {
        $response = $this->postJson('/api/cards/uploadPhotos', [
            'card_id' => 999_999,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Карточка не найдена');
    }
}
