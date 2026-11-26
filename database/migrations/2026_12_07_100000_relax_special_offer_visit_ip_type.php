<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `type` becomes a plain string instead of an enum.
 *
 * WHY, after shipping it as an enum two days ago: a new provenance had to be
 * added (`site_reported`, see VisitorIpResolver) and an enum makes that a
 * schema migration every time — one that also has to be written twice, because
 * MySQL ALTERs an enum and SQLite, which the test suite runs on, rebuilds the
 * table. The set of values is already stated and enforced in PHP
 * (SpecialOfferVisitIp::SERVER_TYPES / CLIENT_TYPES) and in the admin's
 * TypeScript union, so the database column was the third copy and the only one
 * that cost a deploy to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_offer_visit_ips', function (Blueprint $table): void {
            $table->string('type', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('special_offer_visit_ips', function (Blueprint $table): void {
            $table->enum('type', [
                'remote_addr', 'cf_connecting_ip', 'true_client_ip', 'x_real_ip',
                'x_forwarded_for', 'x_client_ip', 'forwarded', 'client_ipv4', 'client_ipv6',
            ])->change();
        });
    }
};
