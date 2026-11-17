<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop sells from the United States, in US dollars.
 *
 * TWO changes, both of which the earlier Armenian framing forced:
 *
 * 1. Prices become `decimal(10,2)`. They were whole integers, which suits a
 *    currency with no minor unit (the dram) and is useless for one where
 *    $129.99 is the normal shape of a price tag.
 *
 * 2. The two delivery columns lose the city in their names. `yerevan` and
 *    `regions` were not just wrong for a US shop — they are typed by hand as
 *    {tokens} in admin-editable copy, so the name is visible to whoever
 *    writes that copy. `local` and `national` say what they mean anywhere.
 *
 * The columns are plain `decimal`, not unsigned: MySQL deprecated UNSIGNED
 * on decimals and this Laravel no longer offers the helper. A price below
 * zero is kept out by validation (`min:0`), where it belongs.
 *
 * Existing figures are NOT converted: there is nothing to convert — no price
 * had been entered. A shop that had already typed dram amounts would need to
 * re-enter them, which is why this runs before anyone is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Renames go in their own statement: some drivers will not rename and
        // retype a column in one pass. Guarded, so a re-run after a partial
        // failure picks up where it stopped instead of erroring on a column
        // that has already been renamed.
        foreach ([
            'delivery_yerevan_time' => 'delivery_local_time',
            'delivery_regions_time' => 'delivery_national_time',
        ] as $from => $to) {
            if (Schema::hasColumn('perfume_settings', $from) && ! Schema::hasColumn('perfume_settings', $to)) {
                Schema::table('perfume_settings', function (Blueprint $table) use ($from, $to): void {
                    $table->renameColumn($from, $to);
                });
            }
        }

        Schema::table('perfume_settings', function (Blueprint $table): void {
            $table->decimal('delivery_free_from', 10, 2)->nullable()->change();
        });

        Schema::table('perfume_product_variants', function (Blueprint $table): void {
            $table->decimal('price', 10, 2)->nullable()->change();
            $table->decimal('old_price', 10, 2)->nullable()->change();
        });

        Schema::table('perfume_orders', function (Blueprint $table): void {
            $table->decimal('total', 10, 2)->nullable()->change();
        });

        Schema::table('perfume_order_items', function (Blueprint $table): void {
            $table->decimal('price', 10, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('perfume_order_items', function (Blueprint $table): void {
            $table->unsignedInteger('price')->nullable()->change();
        });

        Schema::table('perfume_orders', function (Blueprint $table): void {
            $table->unsignedInteger('total')->nullable()->change();
        });

        Schema::table('perfume_product_variants', function (Blueprint $table): void {
            $table->unsignedInteger('price')->nullable()->change();
            $table->unsignedInteger('old_price')->nullable()->change();
        });

        Schema::table('perfume_settings', function (Blueprint $table): void {
            $table->unsignedInteger('delivery_free_from')->nullable()->change();
        });

        foreach ([
            'delivery_local_time'    => 'delivery_yerevan_time',
            'delivery_national_time' => 'delivery_regions_time',
        ] as $from => $to) {
            if (Schema::hasColumn('perfume_settings', $from) && ! Schema::hasColumn('perfume_settings', $to)) {
                Schema::table('perfume_settings', function (Blueprint $table) use ($from, $to): void {
                    $table->renameColumn($from, $to);
                });
            }
        }
    }
};
