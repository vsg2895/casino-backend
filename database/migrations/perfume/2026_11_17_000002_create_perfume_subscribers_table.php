<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store's own mailing list — deliberately NOT the platform's
 * `newsletters` table: that one is per casino site and feeds the verify /
 * promotion / warmup pipeline, none of which this shop uses.
 *
 * `token` is the unsubscribe credential carried in every email, so it is
 * hidden on the model and never returned by an admin endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->boolean('active')->default(true);
            $table->string('token', 64)->unique();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_subscribers');
    }
};
