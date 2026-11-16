<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings;
use App\Support\WholesalePricing;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    private const TEXT_KEYS = [
        'company_name', 'company_address', 'company_phone',
        'bank_holder', 'default_notes',
    ];

    public function show(Settings $settings)
    {
        return response()->json($this->payload($settings));
    }

    public function update(Request $request, Settings $settings)
    {
        $input = $request->only([
            ...self::TEXT_KEYS, 'bank_card', 'bank_sheba', 'default_deposit_percent',
        ]);

        $errors = [];
        $changes = [];

        foreach (self::TEXT_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $value = is_string($input[$key]) ? trim($input[$key]) : $input[$key];
                $changes[$key] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('bank_card', $input)) {
            $card = $this->digits((string) $input['bank_card']);
            if ($card === '') {
                $changes['bank_card'] = null;
            } elseif (!preg_match('/^\d{16}$/', $card)) {
                $errors['bank_card'] = ['شماره کارت باید ۱۶ رقم باشد.'];
            } else {
                $changes['bank_card'] = $card;
            }
        }

        if (array_key_exists('bank_sheba', $input)) {
            $sheba = strtoupper($this->digits((string) $input['bank_sheba']));
            if ($sheba === '') {
                $changes['bank_sheba'] = null;
            } elseif (!preg_match('/^IR\d{24}$/', $sheba)) {
                $errors['bank_sheba'] = ['شماره شبا باید با IR شروع شود و ۲۴ رقم داشته باشد.'];
            } else {
                $changes['bank_sheba'] = $sheba;
            }
        }

        if (array_key_exists('default_deposit_percent', $input)) {
            $percent = $input['default_deposit_percent'];
            if (!is_numeric($percent) || (int) $percent != $percent || $percent < 0 || $percent > 100) {
                $errors['default_deposit_percent'] = ['درصد بیعانه باید عددی بین ۰ تا ۱۰۰ باشد.'];
            } else {
                $changes['default_deposit_percent'] = (int) $percent;
            }
        }

        if (array_key_exists('late_order_days', $input = $request->all())) {
            $days = $input['late_order_days'];
            $days = is_string($days) ? $this->digits($days) : $days;
            if (!is_numeric($days) || (int) $days != $days || $days < 1 || $days > 180) {
                $errors['late_order_days'] = ['تعداد روز باید عددی بین ۱ تا ۱۸۰ باشد.'];
            } else {
                $changes['late_order_days'] = (int) $days;
            }
        }

        if (array_key_exists('orders_tracking_since', $input = $request->all())) {
            $d = $input['orders_tracking_since'];
            // digits() strips dashes, so convert Persian/Arabic digits only
            $d = is_string($d) ? trim(strtr($d, [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ])) : $d;
            $ok = is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
                && \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $d)->format('Y-m-d') === $d;
            if (!$ok) {
                $errors['orders_tracking_since'] = ['تاریخ شروع پیگیری سفارش‌ها معتبر نیست.'];
            } else {
                $changes['orders_tracking_since'] = $d;
            }
        }

        if ($request->has('web_lead_assignee')) {
            $v = $request->input('web_lead_assignee');
            if ($v === null || $v === '') {
                $changes['web_lead_assignee'] = null;
            } else {
                $u = is_numeric($v) ? \App\Models\User::find((int) $v) : null;
                if (!$u || !$u->can('leads.manage')) {
                    $errors['web_lead_assignee'] = ['مسئول سرنخ‌های سایت باید کاربری با دسترسی مدیریت سرنخ‌ها باشد.'];
                } else {
                    $changes['web_lead_assignee'] = $u->id;
                }
            }
        }

        if ($request->has('wholesale_discount_percent')) {
            if (!$request->user()?->can('prices.wholesale')) {
                abort(403, 'شما اجازه تغییر درصد تخفیف همکار را ندارید.');
            }
            $percent = $request->input('wholesale_discount_percent');
            $percent = is_string($percent) ? $this->digits($percent) : $percent;
            if (!is_numeric($percent) || (int) $percent != $percent || $percent < 0 || $percent > 90) {
                $errors['wholesale_discount_percent'] = ['درصد تخفیف همکار باید عددی بین ۰ تا ۹۰ باشد.'];
            } else {
                $changes['wholesale_discount_percent'] = (int) $percent;
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $current = $settings->all();
        foreach ($changes as $key => $value) {
            $old = $current[$key] ?? null;
            if ((string) $old === (string) $value) {
                continue;
            }
            $settings->set($key, $value);
            activity('settings')
                ->causedBy($request->user())
                ->withProperties(['key' => $key, 'old' => $old, 'new' => $value])
                ->log("Setting {$key} changed");
        }

        return response()->json($this->payload($settings));
    }

    /** Strip spaces/dashes and convert Persian/Arabic digits to Latin. */
    private function digits(string $value): string
    {
        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return preg_replace('/[\s\-]+/u', '', $value);
    }

    private function payload(Settings $settings): array
    {
        $all = $settings->all();
        $out = [];
        foreach ([...self::TEXT_KEYS, 'bank_card', 'bank_sheba'] as $key) {
            $v = $all[$key] ?? null;
            $out[$key] = $v === '' ? null : $v;
        }
        $percent = $all['default_deposit_percent'] ?? null;
        $out['default_deposit_percent'] = $percent === null || $percent === '' ? 60 : (int) $percent;
        $out['late_order_days'] = \App\Services\Dashboard\DashboardReport::lateDays();
        $out['orders_tracking_since'] = \App\Services\Dashboard\DashboardReport::trackingSince();
        if (request()->user()?->can('prices.wholesale')) {
            $out['wholesale_discount_percent'] = WholesalePricing::percent();
        }
        $assignee = $all['web_lead_assignee'] ?? null;
        $out['web_lead_assignee'] = is_numeric($assignee) ? (int) $assignee : null;
        $out['sms_quote_enabled'] = filled(config('services.sms_provider.templates.quote'));

        return $out;
    }
}
