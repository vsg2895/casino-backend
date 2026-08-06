<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses used for email warmup campaigns.
 *
 * Deliberately NOT site-scoped, unlike `newsletters`: warmup is about building
 * the reputation of the SENDING mailbox (the .env SMTP account), which is shared
 * by every site, so one global list is the correct model. Adding a site_id later
 * would be additive if that ever changes.
 *
 * `email` is unique on its own — that is what makes a re-import idempotent and
 * lets the importer use insertOrIgnore instead of a per-row existence check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warmup_emails', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_emails');
    }
};
