<?php

namespace Tests\Feature\Concerns;

use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Services\Auth\AuthService;

/** A quote recorded through the API and payments on it, as staff do it in the admin. */
trait RecordsQuotePayments
{
    use UploadsReceipts;

    protected function staffUser(string $role = 'Sales'): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    protected function headersFor(User $u): array
    {
        return ['Authorization' => 'Bearer ' . AuthService::createToken($u), 'Accept' => 'application/json'];
    }

    /** Quote payable 10,000,000 (one line, shipping on delivery, no discount). */
    protected function quoteFor(User $sales, int $price = 10000000): Quote
    {
        $r = $this->postJson('/api/v1/admin/quotes', [
            'customer_name' => 'علی رضایی',
            'phone' => '09123456789',
            'shipping_mode' => 'on_delivery',
            'lines' => [['description' => 'میز', 'quantity' => 1, 'unit_price' => $price]],
        ], $this->headersFor($sales))->assertStatus(201);

        return Quote::findOrFail($r->json('data.id'));
    }

    /** Records a payment on the quote and returns it. Card payments get a fresh receipt. */
    protected function payQuote(Quote $quote, User $staff, int $amount, string $method = 'CASH_RECEIVED', array $extra = []): Payment
    {
        $h = $this->headersFor($staff);
        $ids = in_array($method, ['CARD', 'CHEQUE'], true) ? [$this->uploadReceipt($h)] : [];

        $this->postJson("/api/v1/admin/quotes/{$quote->id}/payments", $extra + [
            'amount' => $amount, 'method' => $method, 'paid_at' => '2026-11-18', 'reference' => '123456', 'receipt_ids' => $ids,
        ], $h)->assertStatus(201);

        return Payment::latest('id')->firstOrFail();
    }
}
