<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CostAdder;
use App\Models\Item;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Services\Production\Drafts\DraftBulkActions;
use App\Services\Production\Drafts\DraftDesignService;
use App\Services\Production\Drafts\DraftEditedException;
use App\Services\Production\Drafts\DraftException;
use App\Services\Production\Drafts\DraftSettings;
use App\Services\Production\Drafts\TemplateRegistry;
use App\Support\Production\Present;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Draft designs («پیش‌نویس خودکار»): the review queue, the templates, regenerating one, and the draft settings. */
class DesignDraftController extends Controller
{
    public function __construct(
        private DraftDesignService $drafts,
        private TemplateRegistry $templates,
        private DraftSettings $settings,
    ) {
    }

    /** Unapproved generated designs across products; the most ordered products first. No cost. */
    public function review(Request $request)
    {
        $filters = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'template' => ['nullable', 'string', Rule::in($this->templates->keys())],
            'guessed' => ['nullable', 'boolean'],
            'edited' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'ids_only' => ['nullable', 'boolean'],
        ]);

        $query = ProductDesign::query()
            ->whereNotNull('draft_template')->whereNull('approved_at')
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->whereHas('product.categories', fn ($c) => $c->where('categories.id', $id)))
            ->when($filters['template'] ?? null, fn ($q, $key) => $q->where('draft_template', $key))
            ->when(array_key_exists('guessed', $filters) && $filters['guessed'] !== null, fn ($q) => $request->boolean('guessed')
                ? $q->where('draft_params->guessed', true)
                : $q->where(fn ($w) => $w->whereNull('draft_params->guessed')->orWhere('draft_params->guessed', false)))
            // The model code (DAKL01 …) is part of the product title.
            ->when(trim((string) ($filters['search'] ?? '')), fn ($q, $search) => $q->whereHas('product', fn ($p) => $p
                ->where('title', 'like', '%' . $search . '%')));

        // "Edited by hand" compares content with the stored fingerprint, so it is filtered here, not in SQL.
        if (array_key_exists('edited', $filters) && $filters['edited'] !== null) {
            $wanted = $request->boolean('edited');
            $ids = (clone $query)->get()->filter(fn ($d) => $this->drafts->editedByHand($d) === $wanted)->pluck('id');
            $query->whereIn('id', $ids);
        }

        // Every matching id (up to the bulk limit), for selecting all of them at once.
        if ($request->boolean('ids_only')) {
            $total = (clone $query)->count();
            $ids = $query->orderBy('id')->limit(DraftBulkActions::MAX)->pluck('id')->all();

            return response()->json(['ids' => $ids, 'total' => $total]);
        }

        $page = $query
            ->with(['product:id,title,dimensions', 'approver:id,name'])
            ->withCount(['parts', 'bomLines'])
            ->orderByDesc(Item::selectRaw('count(*)')->whereColumn('items.product_id', 'product_designs.product_id'))
            ->orderBy('product_id')->orderBy('id')
            ->paginate($filters['per_page'] ?? 25)
            ->through(fn (ProductDesign $d) => Present::design($d) + [
                'product' => ['id' => $d->product->id, 'title' => $d->product->title, 'dimensions' => $d->product->dimensions],
            ]);

        // Categories that have drafts waiting, for the filter.
        $categories = Category::whereHas('products.designs', fn ($q) => $q->whereNotNull('draft_template')->whereNull('approved_at'))
            ->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all();

        return response()->json($page->toArray() + ['categories' => $categories]);
    }

    /** One action on many drafts. approve needs designs.approve; the others bom.manage. */
    public function bulk(Request $request, DraftBulkActions $actions)
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(DraftBulkActions::ACTIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:' . DraftBulkActions::MAX],
            'ids.*' => ['integer', 'distinct'],
            'from_stock_item_id' => ['required_if:action,swap', 'nullable', 'integer', Rule::exists('stock_items', 'id')],
            'to_stock_item_id' => ['required_if:action,swap', 'nullable', 'integer', 'different:from_stock_item_id', Rule::exists('stock_items', 'id')->where('active', true)],
            'cost_adder_id' => ['required_if:action,add_adder', 'nullable', 'integer', Rule::exists('cost_adders', 'id')],
            'quantity' => ['required_if:action,add_adder', 'nullable', 'numeric', 'gt:0', 'max:999999', 'regex:/^\d+(\.\d{1,3})?$/'],
        ], [
            'ids.max' => 'در هر بار حداکثر ' . DraftBulkActions::MAX . ' طرح را می‌توان انتخاب کرد.',
            'to_stock_item_id.different' => 'قلم جایگزین باید با قلم فعلی فرق داشته باشد.',
            'to_stock_item_id.exists' => 'قلم جایگزین باید فعال باشد.',
            'quantity.gt' => 'مقدار باید بیشتر از صفر باشد.',
            'quantity.regex' => 'مقدار حداکثر سه رقم اعشار دارد.',
        ]);

        $permission = $data['action'] === 'approve' ? 'designs.approve' : 'bom.manage';
        abort_unless($request->user()->can($permission), 403);

        $args = [];
        if ($data['action'] === 'swap') {
            $args['from'] = StockItem::findOrFail($data['from_stock_item_id']);
            $args['to'] = StockItem::findOrFail($data['to_stock_item_id']);
            if ($args['from']->kind !== $args['to']->kind) {
                throw ValidationException::withMessages(['to_stock_item_id' => ['قلم جایگزین باید از همان نوع باشد.']]);
            }
        }
        if ($data['action'] === 'add_adder') {
            $args['adder'] = CostAdder::findOrFail($data['cost_adder_id']);
            if (!$args['adder']->active) {
                throw ValidationException::withMessages(['cost_adder_id' => ['این افزودنی غیرفعال است.']]);
            }
            $args['quantity'] = (float) $data['quantity'];
        }

        return response()->json(['data' => $actions->run($data['action'], $data['ids'], $args, $request->user())]);
    }

    public function templates()
    {
        return response()->json(['data' => $this->templates->present()]);
    }

    /** Rebuilds a generated design from parameters. 409 when hand edits would be lost and `confirm` is not set. */
    public function regenerate(Request $request, ProductDesign $design)
    {
        $request->validate([
            'template' => ['nullable', 'string', Rule::in($this->templates->keys())],
            'params' => ['present', 'array'],
            'confirm' => ['sometimes', 'boolean'],
        ]);
        $template = $this->templates->get($request->input('template') ?? $design->draft_template);
        $rules = [];
        foreach ($template?->params() ?? [] as $p) {
            $rules['params.' . $p['key']] = ['sometimes', 'integer', 'between:' . $p['min'] . ',' . $p['max']];
        }
        $params = $request->validate($rules, ['params.*.between' => 'مقدار پارامتر خارج از محدوده است.'])['params'] ?? [];

        try {
            $design = $this->drafts->regenerate($design, $request->input('template'), $params + ($design->draft_params ?? []), $request->boolean('confirm'), $request->user());
        } catch (DraftEditedException $e) {
            return response()->json(['message' => $e->getMessage(), 'edited' => true], 409);
        } catch (DraftException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['params' => [$e->getMessage()]]], 422);
        }

        $design->loadCount(['parts', 'bomLines']);

        return response()->json(['data' => Present::design($design)]);
    }

    public function settings()
    {
        return response()->json(['data' => $this->settings->present()]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate($this->settings->rules(), $this->settings->messages());
        $this->settings->save($data);

        activity()->causedBy($request->user())->withProperties(['keys' => array_keys($data)])->log('design-draft-settings-updated');

        return response()->json(['data' => $this->settings->present()]);
    }
}
