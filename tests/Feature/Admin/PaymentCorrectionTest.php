<?php

namespace Tests\Feature\Admin;

use App\Models\Payment;
use App\Models\PaymentAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Concerns\RecordsQuotePayments;
use Tests\TestCase;

class PaymentCorrectionTest extends TestCase
{
    use RefreshDatabase;
    use RecordsQuotePayments;

    private function patchPayment(Payment $p, array $body, array $headers)
    {
        return $this->patchJson("/api/v1/admin/payments/{$p->id}", $body, $headers);
    }

    public function test_fix_reference_method_and_date_keeps_amount_and_paid_date_for_reports()
    {
        $sales = $this->staffUser();
        $q = $this->quoteFor($sales);
        $p = $this->payQuote($q, $sales, 4000000, 'CASH_RECEIVED');

        $this->patchPayment($p, ['reference' => '654321', 'paid_at' => '2026-11-20', 'method' => 'OTHER'], $this->headersFor($sales))
            ->assertOk()
            ->assertJsonPath('data.paid', 4000000)
            ->assertJsonPath('data.payments.0.reference', '654321')
            ->assertJsonPath('data.payments.0.method', 'OTHER');

        $p->refresh();
        $this->assertSame(4000000, (int) $p->amount);
        $this->assertSame('2026-11-20', $p->created_at->format('Y-m-d'));
        // Revenue reports date payments by updated_at: it must follow the paid date, not "now".
        $this->assertSame('2026-11-20', $p->updated_at->format('Y-m-d'));
    }

    public function test_amount_cannot_be_edited()
    {
        $sales = $this->staffUser();
        $p = $this->payQuote($this->quoteFor($sales), $sales, 4000000);

        $this->patchPayment($p, ['amount' => 1000], $this->headersFor($sales))->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertSame(4000000, (int) $p->fresh()->amount);
    }

    public function test_switching_to_card_needs_a_receipt()
    {
        $sales = $this->staffUser();
        $p = $this->payQuote($this->quoteFor($sales), $sales, 4000000, 'CASH_RECEIVED');

        $this->patchPayment($p, ['method' => 'CARD'], $this->headersFor($sales))->assertStatus(422)->assertJsonValidationErrors('receipt_ids');
        $this->assertSame('نقدی', $p->fresh()->method);

        $id = $this->uploadReceipt($this->headersFor($sales));
        $this->patchPayment($p, ['method' => 'CARD', 'add_receipt_ids' => [$id]], $this->headersFor($sales))->assertOk();
        $this->assertSame('کارت به کارت', $p->fresh()->method);
    }

    public function test_replace_wrong_receipt_in_one_save_deletes_old_file_and_is_audited()
    {
        $sales = $this->staffUser();
        $h = $this->headersFor($sales);
        $p = $this->payQuote($this->quoteFor($sales), $sales, 4000000, 'CARD');
        $wrong = $p->attachments()->firstOrFail();
        $right = $this->uploadReceipt($h);

        $this->patchPayment($p, ['add_receipt_ids' => [$right], 'remove_receipt_ids' => [$wrong->id]], $h)
            ->assertOk()
            ->assertJsonCount(1, 'data.payments.0.receipts')
            ->assertJsonPath('data.payments.0.receipts.0.id', $right);

        $this->assertNull(PaymentAttachment::find($wrong->id));
        Storage::disk('receipts')->assertMissing($wrong->path);

        $entry = Activity::where('description', 'receipts-updated')->latest('id')->firstOrFail();
        $this->assertSame($sales->id, $entry->causer_id);
        $this->assertSame([$wrong->original_name], $entry->properties['receipts_removed']);
    }

    public function test_cannot_remove_last_receipt_of_card_payment()
    {
        $sales = $this->staffUser();
        $p = $this->payQuote($this->quoteFor($sales), $sales, 4000000, 'CARD');
        $only = $p->attachments()->firstOrFail();

        $this->patchPayment($p, ['remove_receipt_ids' => [$only->id]], $this->headersFor($sales))->assertStatus(422);
        $this->assertSame($p->id, $only->fresh()->payment_id);
        Storage::disk('receipts')->assertExists($only->path);
    }

    public function test_cannot_remove_receipt_of_another_payment_or_attach_someone_elses_upload()
    {
        $sales = $this->staffUser();
        $other = $this->staffUser();
        $q = $this->quoteFor($sales);
        $a = $this->payQuote($q, $sales, 2000000, 'CARD');
        $b = $this->payQuote($q, $sales, 2000000, 'CARD');

        $this->patchPayment($a, ['remove_receipt_ids' => [$b->attachments()->first()->id]], $this->headersFor($sales))
            ->assertStatus(422)->assertJsonValidationErrors('remove_receipt_ids');

        $foreign = $this->uploadReceipt($this->headersFor($other));
        $this->patchPayment($a, ['add_receipt_ids' => [$foreign]], $this->headersFor($sales))
            ->assertStatus(422)->assertJsonValidationErrors('add_receipt_ids');
    }

    public function test_change_is_audited_with_old_and_new_values()
    {
        $sales = $this->staffUser();
        $p = $this->payQuote($this->quoteFor($sales), $sales, 4000000);

        $this->patchPayment($p, ['reference' => '999'], $this->headersFor($sales))->assertOk();

        $entry = Activity::where('subject_type', Payment::class)->where('subject_id', $p->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('123456', $entry->properties['old']['ref_id']);
        $this->assertSame('999', $entry->properties['attributes']['ref_id']);
    }

    public function test_gateway_and_voided_payments_cannot_be_corrected()
    {
        $sales = $this->staffUser();
        $p = $this->payQuote($this->quoteFor($sales), $sales, 4000000);

        $p->forceFill(['gateway' => 'payping'])->save();
        $this->patchPayment($p, ['reference' => 'x'], $this->headersFor($sales))->assertStatus(422);

        $p->forceFill(['gateway' => null, 'status' => Payment::STATUS_VOID])->save();
        $this->patchPayment($p, ['reference' => 'x'], $this->headersFor($sales))->assertStatus(422);
        $this->assertSame('123456', $p->fresh()->ref_id);
    }

    public function test_correction_requires_payments_record()
    {
        $sales = $this->staffUser();
        $p = $this->payQuote($this->quoteFor($sales), $sales, 4000000);

        $this->patchPayment($p, ['reference' => 'x'], $this->headersFor($this->staffUser('Technic')))->assertStatus(403);
    }

    public function test_order_payment_rows_carry_action_flags_per_permission()
    {
        $sales = $this->staffUser();
        $q = $this->quoteFor($sales);
        $p = $this->payQuote($q, $sales, 4000000);
        $uuid = $p->order->uuid;

        $this->getJson("/api/v1/admin/orders/uuid/{$uuid}", $this->headersFor($sales))
            ->assertJsonPath('data.payments.0.can_edit', true)
            ->assertJsonPath('data.payments.0.can_void', false);

        $this->getJson("/api/v1/admin/orders/uuid/{$uuid}", $this->headersFor($this->staffUser('SuperAdmin')))
            ->assertJsonPath('data.payments.0.can_edit', true)
            ->assertJsonPath('data.payments.0.can_void', true);

        $this->getJson("/api/v1/admin/quotes/{$q->id}", $this->headersFor($sales))
            ->assertJsonPath('data.payments.0.can_edit', true)
            ->assertJsonPath('data.payments.0.can_void', false);
    }
}
