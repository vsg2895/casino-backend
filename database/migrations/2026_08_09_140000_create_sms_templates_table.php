<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable SMS message texts, editable from the admin panel.
 *
 * Exists so the text a bulk run sends can be changed without retyping it and
 * without a deploy: an admin edits a template here, and the next send picks up
 * the new wording.
 *
 * Deliberately NOT site-scoped, matching `newsletters_based_on_phone` itself —
 * an SMS carries no per-site branding, so a template belongs to the operator
 * rather than to a website. See the create_newsletters_based_on_phone migration
 * for the full reasoning.
 *
 * A template is a STARTING POINT, not the payload. The send stores and transmits
 * the body as it stood in the compose box at that moment (see
 * `phone_sms_histories.body`), so editing a template later never rewrites the
 * record of what was already sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table): void {
            $table->id();

            // Unique so the send dialog's dropdown can never show two entries an
            // admin cannot tell apart.
            $table->string('name', 120)->unique();

            // Text, not string: Twilio accepts up to 1600 characters for a
            // concatenated message, which exceeds a sensible varchar.
            $table->text('body');

            $table->string('status', 10)->default('active'); // active|inactive
            $table->timestamps();

            // The dropdown only ever asks for the active ones.
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
