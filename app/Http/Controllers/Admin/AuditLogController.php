<?php

namespace App\Http\Controllers\Admin;

use App\Models\Category;
use App\Models\Color;
use App\Models\Deal;
use App\Models\Discount;
use App\Models\Material;
use App\Models\Order;
use App\Models\Post;
use App\Models\Product;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    protected array $subjectTypeWhitelist = [
        'product' => Product::class,
        'variation' => Variation::class,
        'category' => Category::class,
        'color' => Color::class,
        'material' => Material::class,
        'deal' => Deal::class,
        'discount' => Discount::class,
        'post' => Post::class,
        'order' => Order::class,
        'work_order' => \App\Models\WorkOrder::class,
        'stock_item' => \App\Models\StockItem::class,
        'user' => User::class,
    ];

    public function index(Request $request)
    {
        $request->validate([
            'causer_id' => 'sometimes|integer|min:1',
            'subject_type' => 'sometimes|string|in:' . implode(',', array_keys($this->subjectTypeWhitelist)),
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => 'sometimes|date_format:Y-m-d',
        ]);

        $query = Activity::with('causer')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->input('causer_id'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $this->subjectTypeWhitelist[$request->input('subject_type')]);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $activities = $query->paginate(50);

        return response()->json([
            'data' => $activities->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'log_name' => $activity->log_name,
                'event' => $activity->event,
                'description' => $activity->description,
                'created_at' => $activity->created_at?->toDateTimeString(),
                'causer' => $activity->causer ? [
                    'id' => $activity->causer->id,
                    'name' => $activity->causer->fullName,
                ] : null,
                'subject_type' => $this->shortSubjectType($activity->subject_type),
                'subject_id' => $activity->subject_id,
                'changes' => $this->sanitizeChanges($activity),
            ]),
            'current_page' => $activities->currentPage(),
            'last_page' => $activities->lastPage(),
            'per_page' => $activities->perPage(),
            'total' => $activities->total(),
        ]);
    }

    protected function sanitizeChanges(Activity $activity): array
    {
        $changes = $activity->properties->only(['old', 'attributes'])->all();

        // Never return actual password values; keep only the boolean flag.
        foreach (['old', 'attributes'] as $key) {
            if (isset($changes[$key]) && is_array($changes[$key]) && array_key_exists('password', $changes[$key])) {
                $changes[$key]['password'] = '[redacted]';
            }
        }

        return $changes;
    }

    protected function shortSubjectType(?string $class): ?string
    {
        if ($class === null) {
            return null;
        }

        $map = array_flip($this->subjectTypeWhitelist);

        return $map[$class] ?? class_basename($class);
    }
}
