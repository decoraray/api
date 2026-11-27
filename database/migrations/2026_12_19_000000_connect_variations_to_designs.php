<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A variation points to the design it is built from, and a storefront material stands for stock sheets
 * (change connect-variations-to-designs).
 *
 * The old reverse link (product_designs.variation_id, at most one variation per design) moves to variations.design_id
 * and is dropped. Written with the query builder only, so later model changes cannot break it. Steps are guarded, so
 * a half-run migration can be run again.
 */
return new class extends Migration
{
    // SQLite rebuilds tables to change foreign keys and can only switch the checks off outside a transaction.
    public $withinTransaction = false;

    /** Material name (as stored) → body sheet code; a name ending in * matches by prefix. */
    private const SHEETS = [
        'ام‌دی‌اف' => 'MDF16-COL',
        'ملامینه' => 'MDF16-COL',
        'هایگلاس' => 'HIGLOSS16',
        'چوب طبیعی*' => 'MDF16-VENEER',
        'بدنه ملامینه درها ام دی اف' => 'MDF16-COL',
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('variations', 'design_id')) {
            Schema::table('variations', function (Blueprint $table) {
                $table->foreignId('design_id')->nullable()->constrained('product_designs')->nullOnDelete();
            });
        }
        if (!Schema::hasColumn('materials', 'body_sheet_item_id')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->foreignId('body_sheet_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
                $table->foreignId('front_sheet_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('work_orders', 'material_name')) {
            Schema::table('work_orders', function (Blueprint $table) {
                // The storefront material whose sheets the work order's parts were cut from, as it was named then.
                $table->string('material_name', 255)->nullable()->after('design_name');
            });
        }

        if (Schema::hasColumn('product_designs', 'variation_id')) {
            foreach (DB::table('product_designs')->whereNotNull('variation_id')->get(['id', 'variation_id']) as $design) {
                DB::table('variations')->where('id', $design->variation_id)->whereNull('design_id')->update(['design_id' => $design->id]);
            }
        }

        $this->seedSheets();

        if (Schema::hasColumn('product_designs', 'variation_id')) {
            Schema::table('product_designs', function (Blueprint $table) {
                $table->dropForeign(['variation_id']);
                $table->dropUnique(['variation_id']);
            });
            Schema::table('product_designs', function (Blueprint $table) {
                $table->dropColumn('variation_id');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('product_designs', 'variation_id')) {
            Schema::table('product_designs', function (Blueprint $table) {
                $table->foreignId('variation_id')->nullable()->after('product_id')->unique()->constrained()->nullOnDelete();
            });
        }

        // A design shared by several variations can only remember one of them.
        if (Schema::hasColumn('variations', 'design_id')) {
            $links = DB::table('variations')->whereNotNull('design_id')->groupBy('design_id')
                ->selectRaw('design_id, min(id) as variation_id')->get();
            foreach ($links as $link) {
                DB::table('product_designs')->where('id', $link->design_id)->update(['variation_id' => $link->variation_id]);
            }
            Schema::table('variations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('design_id');
            });
        }

        if (Schema::hasColumn('work_orders', 'material_name')) {
            Schema::table('work_orders', function (Blueprint $table) {
                $table->dropColumn('material_name');
            });
        }

        if (Schema::hasColumn('materials', 'body_sheet_item_id')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->dropConstrainedForeignId('body_sheet_item_id');
            });
            Schema::table('materials', function (Blueprint $table) {
                $table->dropConstrainedForeignId('front_sheet_item_id');
            });
        }
    }

    private function seedSheets(): void
    {
        $sheets = DB::table('stock_items')->where('kind', 'mdf_sheet')->whereIn('code', array_values(self::SHEETS))->pluck('id', 'code');
        // Compared without zero-width characters, which are typed inconsistently in material names.
        $plain = fn (string $name) => trim(preg_replace('/\s+/u', ' ', str_replace(["\u{200C}", "\u{200F}", "\u{200E}"], '', $name)));

        foreach (DB::table('materials')->whereNull('body_sheet_item_id')->get(['id', 'name']) as $material) {
            $name = $plain((string) $material->name);
            foreach (self::SHEETS as $match => $code) {
                $prefix = str_ends_with($match, '*');
                $wanted = $plain(rtrim($match, '*'));
                if (($prefix ? str_starts_with($name, $wanted) : $name === $wanted) && $sheets->has($code)) {
                    DB::table('materials')->where('id', $material->id)->update(['body_sheet_item_id' => $sheets[$code]]);
                    break;
                }
            }
        }
    }
};
