<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Enums\OrderStatus;
use App\Services\Dashboard\DashboardReport;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\Category;
use App\Models\Variation;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function report(Request $request, DashboardReport $report)
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'assigned_to' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in([...array_column(LeadSource::cases(), 'value'), 'website', 'direct', 'none'])],
            'customer_type' => ['nullable', Rule::in(array_column(CustomerType::cases(), 'value'))],
        ]);

        $today = Carbon::now(DashboardReport::TZ)->startOfDay();
        $to = $data['to'] ?? $today->format('Y-m-d');
        $from = $data['from'] ?? Carbon::parse($to)->subDays(29)->format('Y-m-d');

        if ($from > $to) {
            throw ValidationException::withMessages(['from' => ['تاریخ شروع باید قبل از تاریخ پایان باشد.']]);
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 400) {
            throw ValidationException::withMessages(['to' => ['بازه نباید بیش از ۴۰۰ روز باشد.']]);
        }

        $user = $request->user();

        return response()->json($report->build([
            'from' => $from,
            'to' => $to,
            'assigned_to' => isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            'source' => $data['source'] ?? null,
            'customer_type' => $data['customer_type'] ?? null,
        ], (bool) $user?->can('prices.view'), (bool) $user?->can('leads.manage')));
    }

    public function statistics(Request $request)
    {
        // Get counts
        $totalProducts = Product::count();
        $totalCategories = Category::count();
        $totalOrders = Order::count();
        $totalUsers = User::count();

        // Get order statistics
        $groups = DashboardReport::groups();
        $values = fn (array $cases) => array_map(fn ($c) => $c->value, $cases);
        $pendingOrders = Order::whereIn('status', $values($groups['waiting']))->count();
        $processingOrders = Order::whereIn('status', $values([...$groups['paid'], ...$groups['production']]))->count();
        $completedOrders = Order::where('status', OrderStatus::DELIVERED->value)->count();

        // Revenue = successful payments, by paid time (updated_at), Tehran days
        $tz = DashboardReport::TZ;
        $paid = fn () => \App\Support\Revenue::counted(DB::table('payments')->where('status', 'paid'));
        $range = fn ($q, Carbon $a, Carbon $b) => $q->whereBetween('updated_at', [$a->copy()->utc(), $b->copy()->utc()]);
        $nowT = Carbon::now($tz);

        $totalRevenue = (int) $paid()->sum('amount');
        $todayRevenue = (int) $range($paid(), $nowT->copy()->startOfDay(), $nowT->copy()->endOfDay())->sum('amount');
        $thisMonthRevenue = (int) $range($paid(), $nowT->copy()->startOfMonth(), $nowT->copy()->endOfMonth())->sum('amount');

        // Get recent orders (last 10)
        $recentOrders = Order::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($order) use ($request) {
                $data = [
                    'id' => $order->id,
                    'uuid' => $order->uuid,
                    'user_name' => $order->user ? $order->user->name : 'مهمان',
                    'status' => $order->status,
                    'created_at' => $order->created_at->format('Y-m-d H:i'),
                ];

                if ($request->user()?->can('prices.view')) {
                    $data['total_price'] = $order->total;
                }

                return $data;
            });

        // Get low stock products (stock < 5)
        $lowStockProducts = Variation::with('product')
            ->where('stock', '<', 5)
            ->where('stock', '>', 0)
            ->orderBy('stock', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($variation) {
                return [
                    'product_id' => $variation->product_id,
                    'product_title' => $variation->product->title,
                    'color' => $variation->color,
                    'stock' => $variation->stock,
                ];
            });

        // Get out of stock products
        $outOfStockCount = Variation::where('stock', 0)->count();

        // Get orders by status
        $ordersByStatus = Order::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        // Get monthly revenue for last 6 months
        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $nowT->copy()->startOfMonth()->subMonths($i);
            $revenue = (int) $range($paid(), $month->copy()->startOfMonth(), $month->copy()->endOfMonth())->sum('amount');

            $monthlyRevenue[] = [
                'month' => $month->format('M Y'),
                'revenue' => $revenue,
            ];
        }

        $statistics = [
            'products' => $totalProducts,
            'categories' => $totalCategories,
            'orders' => $totalOrders,
            'users' => $totalUsers,
            'pending_orders' => $pendingOrders,
            'processing_orders' => $processingOrders,
            'completed_orders' => $completedOrders,
            'out_of_stock' => $outOfStockCount,
        ];

        $response = [
            'statistics' => $statistics,
            'recent_orders' => $recentOrders,
            'low_stock_products' => $lowStockProducts,
            'orders_by_status' => $ordersByStatus,
        ];

        if ($request->user()?->can('finance.view')) {
            $response['revenue'] = [
                'total' => $totalRevenue,
                'today' => $todayRevenue,
                'this_month' => $thisMonthRevenue,
                'monthly' => $monthlyRevenue,
            ];
        }

        return response()->json($response);
    }
}
