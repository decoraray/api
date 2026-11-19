<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Item;
use App\Models\ProductDesign;
use App\Services\Production\Drafts\DraftDesignService;
use App\Services\Production\Drafts\DraftEditedException;
use App\Services\Production\Drafts\DraftException;
use App\Services\Production\Drafts\DraftSettings;
use App\Services\Production\Drafts\TemplateRegistry;
use App\Support\Production\Present;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
