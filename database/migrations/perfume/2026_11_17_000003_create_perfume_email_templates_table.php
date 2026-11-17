<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emails the shop writes in the admin and sends to its subscribers.
 *
 * `kind` decides WHEN a template is used: `welcome` goes out automatically
 * the moment someone subscribes (the active one, if any), `campaign` is sent
 * by hand from the admin. The body is HTML from the rich-text editor and may
 * carry {name} {email} {site_name} {site_url} {unsubscribe_url} placeholders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('kind', 16)->default('campaign');
            $table->string('subject');
            $table->string('heading')->nullable();
            $table->longText('body')->nullable();
            $table->string('button_label')->nullable();
            $table->string('button_url')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamps();

            $table->index(['kind', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_email_templates');
    }
};
