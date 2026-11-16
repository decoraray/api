<?php

namespace Tests\Feature\Campaigns;

use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Support\Facades\DB;

trait CampaignFixtures
{
    private function staff(string $role = 'SuperAdmin', array $over = []): User
    {
        return tap(User::factory()->create($over), fn ($u) => $u->assignRole($role));
    }

    private function auth(User $u): array
    {
        return ['Authorization' => 'Bearer ' . AuthService::createToken($u)];
    }

    /** A customer with one order; paid unless $paid is false. */
    private function buyer(string $phone, array $o = []): User
    {
        $user = User::where('phone', $phone)->first()
            ?? User::factory()->create(['phone' => $phone, 'name' => $o['name'] ?? 'مشتری', 'surname' => '']);
        $this->order($user, $o);

        return $user;
    }

    private function order(User $user, array $o = []): Order
    {
        static $n = 0;
        $n++;
        $order = Order::create([
            'uuid' => "co{$n}", 'user_id' => $user->id, 'address' => 'x', 'city' => $o['city'] ?? 'تهران', 'county' => 'x',
            'postal_code' => '1', 'phone' => $user->phone, 'status' => $o['status'] ?? 'تحویل کالا', 'payment_method' => 'CARD',
            'total' => 1000, 'source' => 'site', 'channel' => $o['channel'] ?? 'instagram',
        ]);
        if (isset($o['at'])) {
            Order::whereKey($order->id)->update(['created_at' => $o['at']]);
        }
        if ($o['paid'] ?? true) {
            DB::table('payments')->insert([
                'uuid' => "cp{$n}", 'user_phone' => $user->phone, 'amount' => $o['amount'] ?? 1000, 'status' => 'paid',
                'order_id' => $order->id, 'created_at' => $o['at'] ?? now(), 'updated_at' => $o['at'] ?? now(),
            ]);
        }
        foreach ($o['products'] ?? [] as $productId) {
            DB::table('items')->insert(['title' => 'x', 'slug' => 'x', 'price' => 1, 'quantity' => 1,
                'product_id' => $productId, 'order_id' => $order->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $order;
    }

    private function consentingLead(string $phone, array $over = []): Lead
    {
        return Lead::create($over + ['name' => 'سرنخ', 'phone' => $phone, 'source' => 'website', 'stage' => 'new', 'sms_consent_at' => now()]);
    }

    private function product(): Product
    {
        return Product::factory()->create();
    }
}
