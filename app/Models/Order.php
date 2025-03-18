<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Http\Requests\CheckoutRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'address',
        'city',
        'county',
        'postal_code',
        'phone',
        'status',
        'payment_method',
        'total',
        'discount_amount',
        'discount_id',
        'address_id',
        'shipping_id',
        'description',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('quantity', 'description')
            ->withTimestamps();
    }

    public function discount()
    {
        return $this->hasOne(Discount::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function address()
    {
        return $this->hasOne(Address::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function shipping()
    {
        return $this->belongsTo(Shipping::class);
    }

    public function getPayableAmount()
    {
        $method = $this->payment_method;
        $payable = $this->total - $this->discount_amount;
        switch ($method) {
            case 'online':
                return round($payable);
            case 'partial':
                return round($payable * 0.6);
            case 'door':
                return $payable;
            default:
                return $payable;
        }
    }

    public static function createFromRequest(CheckoutRequest $request)
    {

        $addressId = $request->address_id;

        $paymentMethod = $request->payment_method;

        $user = auth()->user();

        $address = Address::where('uuid', $addressId)->first();

        $cart = $user->cart;

        $shipping = City::where('id', $address->city_id)->first()->shipping; // gets overided by user in the db if exists
        $shippingPrice = $shipping->price + ($shipping->price * ($cart->totalQuantity - 1) * $shipping->additional_percent / 100);

        // create order
        $order = Order::make([
            'user_id' => $user->id,
            'uuid' => uniqid(),
            'address' => $address->address,
            'city' => $address->city,
            'county' => $address->county,
            'postal_code' => $address->postal_code,
            'phone' => $address->phone,
            'status' => OrderStatus::PENDING,
            'total' =>  $cart->total + $shippingPrice,
            'payment_method' => $paymentMethod,
            'discount_amount' => $cart->discountAmount + $cart->campaignAmount,
            'discount_id' => $cart->discount_id,
            'address_id' => $address->id,
            'shipping_id' => $shipping->id,
            'description' => $request->description,
        ]);

        $order->save();

        // add items to order
        for ($i = 0; $i < $cart->variations->count(); $i++) {
            Item::create([
                'title' => $cart->variations[$i]->product->title,
                'slug' => $cart->variations[$i]->product->slug,
                'order_id' => $order->id, // order must be saved to have id
                'product_id' => $cart->variations[$i]->product->id,
                'variation_id' => $cart->variations[$i]->id,
                'quantity' => $cart->variations[$i]->pivot->quantity,
                'price' => $cart->variations[$i]->calculatedPrice,
                'discount' => $cart->variations[$i]->product->deal ? $cart->variations[$i]->product->deal->discount : 0,
                'description' => $cart->variations[$i]->product->deal ? $cart->variations[$i]->product->deal->description : '',
            ]);
        }

        return $order;
    }
}
