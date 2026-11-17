<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstagramReplyRule;
use Illuminate\Http\Request;

/** Comment auto-reply rules: read with leads.manage or settings.manage, write with settings.manage. */
class InstagramRuleController extends Controller
{
    public function index()
    {
        $rules = InstagramReplyRule::with('trackedLink')->orderBy('position')->orderBy('id')->get();

        return response()->json(['data' => $rules->map(fn ($r) => $this->row($r))->values()]);
    }

    public function store(Request $request)
    {
        $rule = InstagramReplyRule::create($this->validated($request) + ['enabled' => true]);

        return response()->json(['data' => $this->row($rule->load('trackedLink'))], 201);
    }

    public function update(Request $request, InstagramReplyRule $rule)
    {
        $rule->update($this->validated($request, $rule));

        return response()->json(['data' => $this->row($rule->fresh('trackedLink'))]);
    }

    public function destroy(InstagramReplyRule $rule)
    {
        $rule->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?InstagramReplyRule $rule = null): array
    {
        $partial = $rule !== null;
        $req = $partial ? 'sometimes' : 'required';
        if ($request->has('keywords') && is_array($request->input('keywords'))) {
            $request->merge(['keywords' => array_values(array_filter(array_map(
                fn ($k) => is_string($k) ? trim($k) : $k, $request->input('keywords')), fn ($k) => $k !== '' && $k !== null))]);
        }

        $data = $request->validate([
            'keywords' => [$req, 'array', 'min:1', 'max:20'],
            'keywords.*' => ['string', 'max:50'],
            'media_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'private_text' => [$req, 'string', 'max:900'],
            'public_text' => ['sometimes', 'nullable', 'string', 'max:300'],
            'tracked_link_id' => ['sometimes', 'nullable', 'integer', 'exists:tracked_links,id'],
            'enabled' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ], [
            'keywords.required' => 'حداقل یک کلمه کلیدی وارد کنید.',
            'keywords.min' => 'حداقل یک کلمه کلیدی وارد کنید.',
            'keywords.max' => 'حداکثر ۲۰ کلمه کلیدی مجاز است.',
            'keywords.*.max' => 'هر کلمه کلیدی حداکثر ۵۰ نویسه است.',
            'private_text.required' => 'متن پاسخ دایرکت را وارد کنید.',
            'private_text.max' => 'متن پاسخ دایرکت حداکثر ۹۰۰ نویسه است.',
            'public_text.max' => 'متن پاسخ زیر کامنت حداکثر ۳۰۰ نویسه است.',
            'tracked_link_id.exists' => 'لینک انتخاب‌شده وجود ندارد.',
        ]);
        foreach (['media_id', 'public_text'] as $k) {
            if (array_key_exists($k, $data) && is_string($data[$k])) {
                $data[$k] = trim($data[$k]) ?: null;
            }
        }

        return $data;
    }

    private function row(InstagramReplyRule $r): array
    {
        return [
            'id' => $r->id,
            'keywords' => $r->keywords,
            'media_id' => $r->media_id,
            'private_text' => $r->private_text,
            'public_text' => $r->public_text,
            'tracked_link' => $r->trackedLink ? [
                'id' => $r->trackedLink->id, 'code' => $r->trackedLink->code, 'url' => $r->trackedLink->url(), 'active' => $r->trackedLink->active,
            ] : null,
            'enabled' => $r->enabled,
            'position' => $r->position,
            'updated_at' => $r->updated_at,
        ];
    }
}
