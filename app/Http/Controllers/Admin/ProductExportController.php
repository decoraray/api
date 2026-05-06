<?php

namespace App\Http\Controllers\Admin;

use App\Models\Product;
use App\Http\Controllers\Controller;

class ProductExportController extends Controller
{
    public function export()
    {
        $products = Product::with([
            'variations.colors',
            'variations.color',
            'variations.material',
            'categories',
        ])->orderBy('id')->get();

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="products-' . now()->format('Y-m-d') . '.csv"',
            'Cache-Control'       => 'no-store, no-cache',
        ];

        $callback = function () use ($products) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel opens Persian text correctly
            fputs($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'شناسه محصول',
                'نام محصول',
                'کد محصول',
                'اسلاگ',
                'وضعیت',
                'دسته‌بندی‌ها',
                'شناسه تنوع',
                'سایز',
                'متریال',
                'رنگ‌ها',
                'کد رنگ (HEX)',
                'قیمت (تومان)',
                'موجودی',
                'وزن (گرم)',
            ]);

            foreach ($products as $product) {
                $categories = $product->categories->pluck('name')->implode(' | ');
                $status     = $product->active ? 'فعال' : 'غیرفعال';

                if ($product->variations->isEmpty()) {
                    fputcsv($handle, [
                        $product->id,
                        $product->title,
                        $product->code,
                        $product->slug,
                        $status,
                        $categories,
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                    ]);
                    continue;
                }

                foreach ($product->variations as $variation) {
                    // Prefer the many-to-many colors; fall back to legacy single color
                    $colors = $variation->colors->isNotEmpty()
                        ? $variation->colors
                        : ($variation->color ? collect([$variation->color]) : collect());

                    $colorNames = $colors->pluck('name')->implode(' | ');
                    $colorHexes = $colors->pluck('hex')->implode(' | ');
                    $material   = $variation->material?->name ?? '';

                    fputcsv($handle, [
                        $product->id,
                        $product->title,
                        $product->code,
                        $product->slug,
                        $status,
                        $categories,
                        $variation->id,
                        $variation->size,
                        $material,
                        $colorNames,
                        $colorHexes,
                        $variation->price,
                        $variation->stock,
                        $variation->weight,
                    ]);
                }
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
