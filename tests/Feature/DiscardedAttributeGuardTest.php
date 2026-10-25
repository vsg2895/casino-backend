<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Pins the discarded-attribute guard in AppServiceProvider.
 *
 * The guard must only ADD a log line. A dropped key stays dropped, and a totally
 * guarded model still throws: once a handler is registered Eloquent calls it
 * instead of throwing, so losing the rethrow would silently change that path.
 */
class DiscardedAttributeGuardTest extends TestCase
{
    public function test_a_non_fillable_key_is_still_dropped_and_now_logged(): void
    {
        Log::spy();

        $model = new class extends Model
        {
            protected $fillable = ['name'];
        };

        $model->fill(['name' => 'kept', 'secret' => 'dropped']);

        $this->assertSame(['name' => 'kept'], $model->getAttributes());
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static fn (string $message): bool => str_contains($message, 'Discarded non-fillable [secret]'));
    }

    public function test_a_totally_guarded_model_still_throws(): void
    {
        $model = new class extends Model {};

        $this->expectException(MassAssignmentException::class);

        $model->fill(['name' => 'anything']);
    }

    public function test_a_fully_fillable_payload_logs_nothing(): void
    {
        Log::spy();

        $model = new class extends Model
        {
            protected $fillable = ['name'];
        };

        $model->fill(['name' => 'kept']);

        Log::shouldNotHaveReceived('warning');
    }
}
