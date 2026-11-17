<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop does not run email campaigns.
 *
 * It sends exactly one message — the confirmation a visitor gets when they
 * use the subscribe box on the storefront — and that is written in code, not
 * composed in the admin. So the template library and the imported address
 * lists go: no templates to edit, no receivers to import, nothing to send by
 * hand.
 *
 * `perfume_subscribers` STAYS. That is the subscription itself, not a
 * campaign tool: it is who asked to hear from the shop, and it is what the
 * confirmation is sent to.
 *
 * Nothing here touches the platform's own mail tables — warmup, custom
 * receivers, promotion templates and schedules are untouched and keep
 * working exactly as they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('perfume_receivers');
        Schema::dropIfExists('perfume_email_templates');
    }

    /**
     * Recreates the two tables as they stood, so the migration is reversible.
     * Their rows are gone either way — this brings back the shape, not the
     * content.
     */
    public function down(): void
    {
        Schema::create('perfume_email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('kind', 16)->default('campaign');
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('subject');
            $table->string('preheader')->nullable();
            $table->string('heading')->nullable();
            $table->string('hero_image_url')->nullable();
            $table->string('hero_url')->nullable();
            $table->longText('body')->nullable();
            $table->string('button_label')->nullable();
            $table->string('button_url')->nullable();
            $table->text('secondary_text')->nullable();
            $table->text('disclaimer_text')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);

            foreach ([
                'background_color', 'body_background_color', 'heading_color', 'text_color',
                'secondary_text_color', 'muted_text_color', 'button_color', 'button_text_color',
                'accent_color',
            ] as $color) {
                $table->string($color, 7)->nullable();
            }

            $table->timestamps();
            $table->index(['kind', 'active']);
        });

        Schema::create('perfume_receivers', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->boolean('active')->default(true);
            $table->string('token', 64)->unique();
            $table->string('source')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index('active');
            $table->index('source');
        });
    }
};
