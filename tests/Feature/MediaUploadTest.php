<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    public function test_upload_requires_authentication(): void
    {
        $this->postJson('/api/v1/admin/uploads', [])->assertUnauthorized();
    }

    public function test_admin_can_upload_an_image_and_it_is_stored(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/uploads', [
            'file' => UploadedFile::fake()->image('logo.png', 50, 40),
            'type' => 'banner',
        ])->assertCreated()->assertJsonStructure(['path', 'url']);

        $path = $response->json('path');
        $this->assertStringStartsWith('uploads/banner/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_upload_rejects_non_image_files(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/uploads', [
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }
}
