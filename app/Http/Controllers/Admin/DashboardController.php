<?php

namespace App\Http\Controllers\Admin;

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
    public function statistics()
    {
        // Get counts
        $totalProducts = Product::count();
        $totalCategories = Category::count();
        $totalOrders = Order::count();
        $totalUsers = User::count();
        
        // Get order statistics
        $pendingOrders = Order::where('status', 'pending')->count();
        $processingOrders = Order::where('status', 'processing')->count();
        $completedOrders = Order::where('status', 'completed')->count();
        
        // Get revenue statistics
        $totalRevenue = Order::where('status', '!=', 'cancelled')
            ->sum('total_price');
        
        $todayRevenue = Order::where('status', '!=', 'cancelled')
            ->whereDate('created_at', today())
            ->sum('total_price');
        
        $thisMonthRevenue = Order::where('status', '!=', 'cancelled')
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('total_price');
        
        // Get recent orders (last 10)
        $recentOrders = Order::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'uuid' => $order->uuid,
                    'user_name' => $order->user ? $order->user->name : 'مهمان',
                    'total_price' => $order->total_price,
                    'status' => $order->status,
                    'created_at' => $order->created_at->format('Y-m-d H:i'),
                ];
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
            $month = now()->subMonths($i);
            $revenue = Order::where('status', '!=', 'cancelled')
                ->whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->sum('total_price');
            
            $monthlyRevenue[] = [
                'month' => $month->format('M Y'),
                'revenue' => $revenue,
            ];
        }
        
        return response()->json([
            'statistics' => [
                'products' => $totalProducts,
                'categories' => $totalCategories,
                'orders' => $totalOrders,
                'users' => $totalUsers,
                'pending_orders' => $pendingOrders,
                'processing_orders' => $processingOrders,
                'completed_orders' => $completedOrders,
                'out_of_stock' => $outOfStockCount,
            ],
            'revenue' => [
                'total' => $totalRevenue,
                'today' => $todayRevenue,
                'this_month' => $thisMonthRevenue,
                'monthly' => $monthlyRevenue,
            ],
            'recent_orders' => $recentOrders,
            'low_stock_products' => $lowStockProducts,
            'orders_by_status' => $ordersByStatus,
        ]);
    }
}

