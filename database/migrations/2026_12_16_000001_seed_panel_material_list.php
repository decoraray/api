<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The workshop's list of boards a part is cut from. The two first rows are renamed in place so the parts, drafts
 * and draft settings that point at them keep their ids: «بدنه ۱۶ میل» was mapped to coloured MDF (MDF16-COL) and
 * «پشت‌بند ۳ میل» to HDF3. Thickness, sheet size and waste stay editable in «ورق‌ها».
 */
return new class extends Migration
{
    private const RENAMES = [
        'بدنه ۱۶ میل' => 'ام دی اف رنگی',
        'پشت‌بند ۳ میل' => 'سه میل سفید',
    ];

    private const LIST = [
        ['name' => 'نئوپان ۱۶ میل', 'thickness_mm' => 16, 'waste_percent' => 20],
        ['name' => 'ام دی اف دو رو خام', 'thickness_mm' => 16, 'waste_percent' => 20],
        ['name' => 'ام دی اف یک رو خام', 'thickness_mm' => 16, 'waste_percent' => 20],
        ['name' => 'ام دی اف رنگی', 'thickness_mm' => 16, 'waste_percent' => 20],
        ['name' => 'هایگلاس', 'thickness_mm' => 16, 'waste_percent' => 20],
        ['name' => 'روکش چوب طبیعی', 'thickness_mm' => 16, 'waste_percent' => 20],
        ['name' => 'سه میل سفید', 'thickness_mm' => 3, 'waste_percent' => 10],
        ['name' => 'سه میل رنگی', 'thickness_mm' => 3, 'waste_percent' => 10],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::RENAMES as $from => $to) {
            if (!DB::table('panel_materials')->where('name', $to)->exists()) {
                DB::table('panel_materials')->where('name', $from)->update(['name' => $to, 'updated_at' => $now]);
            }
        }

        foreach (self::LIST as $row) {
            if (!DB::table('panel_materials')->where('name', $row['name'])->exists()) {
                DB::table('panel_materials')->insert($row + [
                    'sheet_length_mm' => 3660, 'sheet_width_mm' => 1830, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Rows added here may already be used by parts (restrictOnDelete), so only unused ones are removed.
        $added = array_diff(array_column(self::LIST, 'name'), self::RENAMES);
        DB::table('panel_materials')->whereIn('name', $added)
            ->whereNotExists(fn ($q) => $q->from('product_parts')->whereColumn('product_parts.panel_material_id', 'panel_materials.id'))
            ->delete();

        foreach (self::RENAMES as $from => $to) {
            DB::table('panel_materials')->where('name', $to)->update(['name' => $from]);
        }
    }
};
