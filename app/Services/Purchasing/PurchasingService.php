<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderState;
use App\Enums\PurchaseRequestState;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Request → order → receipt. Placing commits to the supplier; receiving brings stock in at the order's price,
 * so whoever counts the delivery never handles money.
 */
class PurchasingService
{
    public function __construct(private InventoryService $inventory)
    {
    }

    /**
     * Creates a draft, or replaces a draft's content.
     *
     * @param array{supplier_id: int, expected_at?: ?string, note?: ?string, lines: array<int, array{stock_item_id: int, quantity: float|string, unit_price: int, purchase_request_id?: ?int}>} $data
     */
    public function saveDraft(?PurchaseOrder $order, array $data, ?User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data, $user) {
            if (!Supplier::whereKey($data['supplier_id'])->where('active', true)->exists()) {
                $this->fail('supplier_id', 'این تأمین‌کننده غیرفعال است.');
            }

            if ($order) {
                $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
                if ($order->state !== PurchaseOrderState::DRAFT) {
                    $this->fail('state', 'فقط سفارش پیش‌نویس قابل ویرایش است.');
                }
                $order->update(['supplier_id' => $data['supplier_id'], 'expected_at' => $data['expected_at'] ?? null, 'note' => $data['note'] ?? null]);
                // Unlink requests from the old lines before they go.
                PurchaseRequest::whereIn('purchase_order_line_id', $order->lines()->pluck('id'))->update(['purchase_order_line_id' => null]);
                $order->lines()->delete();
            } else {
                $order = PurchaseOrder::create([
                    'supplier_id' => $data['supplier_id'],
                    'expected_at' => $data['expected_at'] ?? null,
                    'note' => $data['note'] ?? null,
                    'created_by' => $user?->id,
                ]);
            }

            foreach ($data['lines'] as $row) {
                $line = $order->lines()->create([
                    'stock_item_id' => $row['stock_item_id'],
                    'quantity' => round((float) $row['quantity'], 3),
                    'unit_price' => (int) $row['unit_price'],
                ]);

                if (!empty($row['purchase_request_id'])) {
                    $request = PurchaseRequest::lockForUpdate()->findOrFail($row['purchase_request_id']);
                    if ($request->state !== PurchaseRequestState::OPEN || $request->purchase_order_line_id) {
                        $this->fail('lines', 'درخواست خرید انتخاب‌شده باز نیست یا در سفارش دیگری آمده است.');
                    }
                    if ($request->stock_item_id !== $line->stock_item_id) {
                        $this->fail('lines', 'درخواست خرید با قلم این ردیف یکی نیست.');
                    }
                    $request->update(['purchase_order_line_id' => $line->id]);
                }
            }

            return $order->refresh();
        });
    }

    public function place(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            if ($order->state !== PurchaseOrderState::DRAFT) {
                $this->fail('state', 'فقط سفارش پیش‌نویس را می‌توان ثبت کرد.');
            }

            $order->update(['state' => PurchaseOrderState::PLACED, 'placed_at' => now()]);
            PurchaseRequest::whereIn('purchase_order_line_id', $order->lines()->pluck('id'))
                ->update(['state' => PurchaseRequestState::ORDERED->value]);

            return $order;
        });
    }

    /** Nothing received yet: the order is off and its requests are open again. */
    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            if (!in_array($order->state, [PurchaseOrderState::DRAFT, PurchaseOrderState::PLACED], true)) {
                $this->fail('state', 'سفارشی که کالایی از آن رسیده لغو نمی‌شود؛ آن را ببندید.');
            }

            $order->update(['state' => PurchaseOrderState::CANCELLED]);
            $this->reopenRequests($order->lines()->pluck('id')->all());

            return $order;
        });
    }

    /** Stop waiting for the rest of a partly delivered order. */
    public function close(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            if ($order->state !== PurchaseOrderState::PARTIALLY_RECEIVED) {
                $this->fail('state', 'فقط سفارشی که بخشی از آن رسیده بسته می‌شود.');
            }

            $order->update(['state' => PurchaseOrderState::CLOSED]);
            $short = $order->lines()->get()->filter(fn (PurchaseOrderLine $line) => $line->outstanding > 0)->pluck('id')->all();
            $this->reopenRequests($short);

            return $order;
        });
    }

    /**
     * @param array<int, array{purchase_order_line_id: int, quantity: float|string}> $lines
     */
    public function receive(PurchaseOrder $order, array $lines, ?User $user, ?string $note = null): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $lines, $user, $note) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            if (!in_array($order->state, PurchaseOrderState::receivable(), true)) {
                $this->fail('state', 'برای این سفارش رسید ثبت نمی‌شود.');
            }

            $orderLines = $order->lines()->with('stockItem')->get()->keyBy('id');
            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->id,
                'note' => $note ?: null,
                'received_by' => $user?->id,
                'created_at' => now(),
            ]);

            foreach ($lines as $row) {
                $line = $orderLines->get($row['purchase_order_line_id']);
                $quantity = round((float) $row['quantity'], 3);
                if (!$line) {
                    $this->fail('lines', 'ردیف رسید به این سفارش تعلق ندارد.');
                }
                if ($quantity > $line->outstanding) {
                    $this->fail('lines', "بیش از مانده سفارش ({$line->stockItem->name}) دریافت نمی‌شود.");
                }

                $receipt->lines()->create(['purchase_order_line_id' => $line->id, 'quantity' => $quantity]);
                $this->inventory->receive($line->stockItem, $quantity, $line->unit_price, $user, $order->number, $receipt);
                $line->update(['received_quantity' => round($line->received_quantity + $quantity, 3)]);
            }

            $fresh = $order->lines()->get();
            $order->update(['state' => $fresh->every(fn (PurchaseOrderLine $l) => $l->outstanding <= 0)
                ? PurchaseOrderState::RECEIVED
                : PurchaseOrderState::PARTIALLY_RECEIVED]);

            return $receipt;
        });
    }

    public function evaluate(PurchaseOrder $order, int $score, ?string $note): PurchaseOrder
    {
        if (!in_array($order->state, [PurchaseOrderState::RECEIVED, PurchaseOrderState::CLOSED], true)) {
            $this->fail('score', 'فقط سفارشِ دریافت‌شده یا بسته‌شده امتیاز می‌گیرد.');
        }
        $order->update(['score' => $score, 'score_note' => $note ?: null]);

        return $order;
    }

    public function cancelRequest(PurchaseRequest $request): PurchaseRequest
    {
        if ($request->state !== PurchaseRequestState::OPEN) {
            $this->fail('state', 'فقط درخواست باز لغو می‌شود.');
        }
        $request->update(['state' => PurchaseRequestState::CANCELLED, 'purchase_order_line_id' => null]);

        return $request;
    }

    private function reopenRequests(array $lineIds): void
    {
        PurchaseRequest::whereIn('purchase_order_line_id', $lineIds)
            ->where('state', '!=', PurchaseRequestState::CANCELLED->value)
            ->update(['state' => PurchaseRequestState::OPEN->value, 'purchase_order_line_id' => null]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
