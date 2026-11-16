<?php

namespace App\Services\Campaigns;

use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Support\Channel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Validated audience filters, stored as JSON on a campaign. */
class CampaignFilters
{
    public const DEFAULTS = [
        'include_buyers' => true,
        'include_leads' => true,
        'cities' => [],
        'channels' => [],
        'product_ids' => [],
        'category_ids' => [],
        'interest' => null,
        'bought_after' => null,
        'bought_before' => null,
        'min_orders' => null,
        'customer_types' => [],
    ];

    public static function rules(string $prefix = ''): array
    {
        $p = $prefix === '' ? '' : $prefix . '.';
        $channels = [...array_column(LeadSource::cases(), 'value'), Channel::DIRECT];

        return [
            $p . 'include_buyers' => ['sometimes', 'boolean'],
            $p . 'include_leads' => ['sometimes', 'boolean'],
            $p . 'cities' => ['sometimes', 'array', 'max:50'],
            $p . 'cities.*' => ['string', 'max:100'],
            $p . 'channels' => ['sometimes', 'array'],
            $p . 'channels.*' => [Rule::in($channels)],
            $p . 'product_ids' => ['sometimes', 'array', 'max:100'],
            $p . 'product_ids.*' => ['integer'],
            $p . 'category_ids' => ['sometimes', 'array', 'max:100'],
            $p . 'category_ids.*' => ['integer'],
            $p . 'interest' => ['sometimes', 'nullable', 'string', 'max:100'],
            $p . 'bought_after' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            $p . 'bought_before' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            $p . 'min_orders' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            $p . 'customer_types' => ['sometimes', 'array'],
            $p . 'customer_types.*' => [Rule::in(array_column(CustomerType::cases(), 'value'))],
        ];
    }

    /** Defaults merged with validated input; unknown keys dropped. */
    public static function normalize(?array $input): array
    {
        $input = array_intersect_key($input ?? [], self::DEFAULTS);
        Validator::make($input, self::rules())->validate();
        $out = $input + self::DEFAULTS;

        foreach (['include_buyers', 'include_leads'] as $k) {
            $out[$k] = (bool) $out[$k];
        }
        foreach (['product_ids', 'category_ids'] as $k) {
            $out[$k] = array_values(array_unique(array_map('intval', $out[$k])));
        }
        $out['cities'] = array_values(array_filter(array_map(fn ($c) => trim((string) $c), $out['cities'])));
        $out['interest'] = filled($out['interest']) ? trim($out['interest']) : null;
        $out['min_orders'] = $out['min_orders'] ? (int) $out['min_orders'] : null;

        return $out;
    }

    /** True when any filter needs purchase history, so people who never bought cannot match. */
    public static function needsPurchase(array $f): bool
    {
        return $f['product_ids'] || $f['category_ids'] || $f['bought_after'] || $f['bought_before'] || $f['min_orders'];
    }
}
