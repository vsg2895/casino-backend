<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password reset tokens for forum members.
 *
 * NOT the framework's `password_reset_tokens`, whose primary key is the email
 * address alone. Forum accounts are per-site: the same address can hold an
 * account on two domains, and a reset issued for one must not be redeemable on
 * the other. The composite primary key is what enforces that.
 *
 * The token is stored hashed by the framework's broker, and rows are pruned by
 * expiry rather than kept — a reset token is a bearer credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('forum_password_reset_tokens')) {
            return;
        }

        Schema::create('forum_password_reset_tokens', function (Blueprint $table): void {
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->primary(['site_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_password_reset_tokens');
    }
};
