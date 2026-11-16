<?php

namespace App\Services\Campaigns;

use App\Enums\CustomerType;
use App\Support\Phone;
use App\Support\Revenue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who a campaign goes to. Only past buyers (a paid, not cancelled/returned order)
 * and leads with SMS consent can be in an audience; each valid mobile appears once
 * and opted-out numbers are always left out.
 */
class Audience
{
    public const MOBILE = '/^09\d{9}$/';

    /**
     * The final recipients.
     *
     * @return Collection<int, array{phone: string, name: string, user_id: ?int, lead_id: ?int, from: string}>
     */
    public function build(array $filters): Collection
    {
        return $this->resolve(CampaignFilters::normalize($filters))['recipients'];
    }

    /** Counts and a masked sample for the campaign form. */
    public function preview(array $filters): array
    {
        $r = $this->resolve(CampaignFilters::normalize($filters));
        $list = $r['recipients'];

        return [
            'total' => $list->count(),
            'buyers' => $list->where('from', 'buyer')->count(),
            'leads' => $list->where('from', 'lead')->count(),
            'opted_out' => $r['opted_out'],
            'sample' => $list->take(20)->map(fn ($p) => [
                'name' => $p['name'],
                'phone' => self::mask($p['phone']),
                'from' => $p['from'],
            ])->values(),
        ];
    }

    public static function mask(string $phone): string
    {
        return '••••' . substr($phone, -4);
    }

    private function resolve(array $f): array
    {
        $buyers = $this->buyers();             // user_id => profile
        $byPhone = [];
        foreach ($buyers as $b) {
            if ($b['phone']) {
                $byPhone[$b['phone']] ??= $b;
            }
        }
        $leads = $this->leadsByPerson();       // phone and user_id indexes of all leads (for type/interest/source)

        $people = [];
        if ($f['include_buyers']) {
            foreach ($buyers as $b) {
                if (!$b['phone'] || isset($people[$b['phone']])) {
                    continue;
                }
                $people[$b['phone']] = ['phone' => $b['phone'], 'name' => $b['name'], 'user_id' => $b['user_id'],
                    'lead_id' => null, 'from' => 'buyer', 'buyer' => $b];
            }
        }
        if ($f['include_leads']) {
            foreach ($leads['consenting'] as $l) {
                if (isset($people[$l['phone']])) {
                    $people[$l['phone']]['lead_id'] ??= $l['id'];
                    continue;
                }
                $buyer = ($l['user_id'] ? $buyers[$l['user_id']] ?? null : null) ?? $byPhone[$l['phone']] ?? null;
                $people[$l['phone']] = ['phone' => $l['phone'], 'name' => $l['name'], 'user_id' => $buyer['user_id'] ?? $l['user_id'],
                    'lead_id' => $l['id'], 'from' => 'lead', 'buyer' => $buyer];
            }
        }

        $matchOrders = $this->ordersMatchingProducts($f);
        $optedOut = DB::table('sms_opt_outs')->pluck('phone')->flip();
        $excluded = 0;
        $out = collect();

        foreach ($people as $phone => $p) {
            $personLeads = array_merge($leads['by_phone'][$phone] ?? [], $p['user_id'] ? ($leads['by_user'][$p['user_id']] ?? []) : []);
            if (!$this->matches($p, $personLeads, $f, $matchOrders)) {
                continue;
            }
            if (isset($optedOut[$phone])) {
                $excluded++;
                continue;
            }
            unset($p['buyer']);
            $out->push($p);
        }

        return ['recipients' => $out->values(), 'opted_out' => $excluded];
    }

    private function matches(array $p, array $personLeads, array $f, ?array $matchOrders): bool
    {
        $b = $p['buyer'];
        if (CampaignFilters::needsPurchase($f) && !$b) {
            return false;
        }

        if ($f['cities']) {
            $cities = array_merge($b['cities'] ?? [], array_column($personLeads, 'city'));
            if (!self::anyContains($cities, $f['cities'])) {
                return false;
            }
        }
        if ($f['channels']) {
            $channels = array_merge($b['channels'] ?? [], array_column($personLeads, 'source'));
            if (!array_intersect($channels, $f['channels'])) {
                return false;
            }
        }
        if ($matchOrders !== null && !array_intersect($b['order_ids'], $matchOrders)) {
            return false;
        }
        if ($f['interest'] && !self::anyContains(array_column($personLeads, 'interest'), [$f['interest']])) {
            return false;
        }
        if ($f['bought_after'] && $b['last_at']->lt(Carbon::parse($f['bought_after'], 'Asia/Tehran')->startOfDay())) {
            return false;
        }
        if ($f['bought_before'] && $b['last_at']->gte(Carbon::parse($f['bought_before'], 'Asia/Tehran')->addDay()->startOfDay())) {
            return false;
        }
        if ($f['min_orders'] && $b['orders'] < $f['min_orders']) {
            return false;
        }
        if ($f['customer_types']) {
            $type = $this->type($personLeads, $b['wholesale_quote'] ?? false);
            if (!in_array($type->value, $f['customer_types'], true)) {
                return false;
            }
        }

        return true;
    }

    /** Same order of precedence as the customer profile: wholesale, partner, consumer. */
    private function type(array $personLeads, bool $wholesaleQuote): CustomerType
    {
        $types = array_column($personLeads, 'customer_type');
        if ($wholesaleQuote || in_array(CustomerType::WHOLESALE->value, $types, true)) {
            return CustomerType::WHOLESALE;
        }

        return in_array(CustomerType::PARTNER->value, $types, true) ? CustomerType::PARTNER : CustomerType::CONSUMER;
    }

    private static function anyContains(array $values, array $needles): bool
    {
        foreach ($values as $v) {
            foreach ($needles as $n) {
                if ($v !== null && $n !== '' && mb_stripos((string) $v, (string) $n) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Users with at least one paid order that is not cancelled or returned.
     *
     * @return array<int, array>
     */
    private function buyers(): array
    {
        $paid = DB::table('payments')->selectRaw('1')->whereColumn('payments.order_id', 'orders.id')->where('payments.status', 'paid');

        $rows = DB::table('orders')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->whereExists($paid)
            ->whereNotIn('orders.status', Revenue::excludedStatuses())
            ->select('orders.id', 'orders.user_id', 'orders.city', 'orders.channel', 'orders.phone as order_phone',
                'orders.created_at', 'users.name', 'users.surname', 'users.phone')
            ->orderBy('orders.id')
            ->get();

        $wholesale = DB::table('quotes')->where('price_tier', 'wholesale')->whereNotNull('user_id')->pluck('user_id')->flip();

        $out = [];
        foreach ($rows as $o) {
            $id = (int) $o->user_id;
            $phone = Phone::normalize($o->phone ?: $o->order_phone);
            $out[$id] ??= [
                'user_id' => $id,
                'phone' => preg_match(self::MOBILE, $phone) ? $phone : (preg_match(self::MOBILE, Phone::normalize($o->order_phone)) ? Phone::normalize($o->order_phone) : null),
                'name' => trim($o->name . ' ' . $o->surname),
                'cities' => [], 'channels' => [], 'order_ids' => [], 'orders' => 0, 'last_at' => null,
                'wholesale_quote' => isset($wholesale[$id]),
            ];
            $b = &$out[$id];
            $b['orders']++;
            $b['order_ids'][] = (int) $o->id;
            $o->city && $b['cities'][] = $o->city;
            $o->channel && $b['channels'][] = $o->channel;
            $at = Carbon::parse($o->created_at, 'UTC');
            $b['last_at'] = $b['last_at'] && $b['last_at']->gt($at) ? $b['last_at'] : $at;
            unset($b);
        }

        return $out;
    }

    /** All leads indexed by phone and by user, plus the consenting ones. */
    private function leadsByPerson(): array
    {
        $out = ['by_phone' => [], 'by_user' => [], 'consenting' => []];
        $rows = DB::table('leads')->select('id', 'name', 'phone', 'city', 'source', 'customer_type', 'interest', 'user_id', 'sms_consent_at')
            ->orderBy('id')->get();

        foreach ($rows as $l) {
            $phone = Phone::normalize($l->phone);
            $row = ['id' => (int) $l->id, 'name' => $l->name, 'phone' => $phone, 'city' => $l->city, 'source' => $l->source,
                'customer_type' => $l->customer_type, 'interest' => $l->interest, 'user_id' => $l->user_id ? (int) $l->user_id : null];
            if ($phone !== '') {
                $out['by_phone'][$phone][] = $row;
            }
            if ($row['user_id']) {
                $out['by_user'][$row['user_id']][] = $row;
            }
            if ($l->sms_consent_at && preg_match(self::MOBILE, $phone)) {
                $out['consenting'][] = $row;
            }
        }

        return $out;
    }

    /** Order ids containing a chosen product or a product in a chosen category; null when not filtering. */
    private function ordersMatchingProducts(array $f): ?array
    {
        if (!$f['product_ids'] && !$f['category_ids']) {
            return null;
        }

        return DB::table('items')
            ->where(function ($q) use ($f) {
                if ($f['product_ids']) {
                    $q->orWhereIn('items.product_id', $f['product_ids']);
                }
                if ($f['category_ids']) {
                    $q->orWhereIn('items.product_id', DB::table('category_product')->whereIn('category_id', $f['category_ids'])->select('product_id'));
                }
            })
            ->whereNotNull('items.order_id')
            ->distinct()->pluck('items.order_id')->map(fn ($v) => (int) $v)->all();
    }
}
