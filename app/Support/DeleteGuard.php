<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Refuses admin deletes that would destroy history (orders, quotes, payments) or silently unlink
 * catalog data. Records that are in use are deactivated instead of deleted.
 */
class DeleteGuard
{
    public static function product(Product $product): void
    {
        $variationIds = Variation::where('product_id', $product->id)->pluck('id')->all();

        $used = DB::table('items')->where('product_id', $product->id)->exists()
            || ($variationIds && DB::table('items')->whereIn('variation_id', $variationIds)->exists())
            || DB::table('quote_lines')->where('product_id', $product->id)->exists()
            || ($variationIds && DB::table('quote_lines')->whereIn('variation_id', $variationIds)->exists());

        if ($used) {
            self::refuse('product', 'این محصول در سفارش یا پیش‌فاکتور استفاده شده و قابل حذف نیست؛ به‌جای حذف، غیرفعالش کنید.');
        }
    }

    public static function variation(Variation $variation): void
    {
        if (DB::table('items')->where('variation_id', $variation->id)->exists()
            || DB::table('quote_lines')->where('variation_id', $variation->id)->exists()) {
            self::refuse('variation', 'این تنوع در سفارش یا پیش‌فاکتور استفاده شده و قابل حذف نیست؛ به‌جای حذف، غیرفعالش کنید.');
        }
    }

    public static function color(Color $color): void
    {
        $count = DB::table('variations')->where('color_id', $color->id)->pluck('id')
            ->merge(Schema::hasTable('color_variation') ? DB::table('color_variation')->where('color_id', $color->id)->pluck('variation_id') : [])
            ->unique()->count();

        if ($count > 0) {
            self::refuse('color', "این رنگ در {$count} تنوع محصول استفاده شده و قابل حذف نیست.");
        }
    }

    public static function material(Material $material): void
    {
        $variations = DB::table('variations')->where('material_id', $material->id)->count();
        $products = DB::table('material_product')->where('material_id', $material->id)->count();

        if ($variations + $products > 0) {
            self::refuse('material', "این متریال در {$variations} تنوع و {$products} محصول استفاده شده و قابل حذف نیست.");
        }
    }

    public static function category(Category $category): void
    {
        $products = DB::table('category_product')->where('category_id', $category->id)->count();
        $posts = Schema::hasTable('category_post') ? DB::table('category_post')->where('category_id', $category->id)->count() : 0;
        $children = Category::where('parent_id', $category->id)->count();

        if ($products + $posts + $children > 0) {
            self::refuse('category', "این دسته‌بندی {$products} محصول، {$posts} پست و {$children} زیردسته دارد و قابل حذف نیست.");
        }
    }

    public static function customer(User $user): void
    {
        if ($user->isStaff()) {
            self::refuse('user', 'این کاربر از کارکنان است؛ دسترسی او را از صفحه کارکنان بردارید.');
        }

        $has = fn (string $table) => Schema::hasTable($table) && DB::table($table)->where('user_id', $user->id)->exists();
        if ($has('orders') || $has('quotes') || $has('payments') || $has('leads')) {
            self::refuse('user', 'این مشتری سفارش، پیش‌فاکتور، پرداخت یا سرنخ دارد و قابل حذف نیست.');
        }
    }

    private static function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
