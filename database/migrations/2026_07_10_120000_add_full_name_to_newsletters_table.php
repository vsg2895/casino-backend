<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional subscriber name, captured (when provided) by the public subscribe
 * form. Nullable — most subscriptions supply only an email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->string('full_name')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropColumn('full_name');
        });
    }
};
