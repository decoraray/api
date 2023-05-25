<!DOCTYPE html rtl>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Laravel</title>

        <!-- Fonts -->
        <link href="https://fonts.bunny.net/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">

        <!-- Styles -->

    </head>



    <body class="antialiased">
        <h1>Invoice</h1>

            {{-- "id" => 24
            "uuid" => "640b85437f487"
            "address" => "adres. asda"
            "city" => "آذرشهر"
            "county" => "آذربایجان شرقی"
            "postal_code" => "1234567891"
            "phone" => "12345678"
            "status" => "تایید شده"
            "discount_amount" => 0
            "total" => 740000
            "user_id" => 1
            "address_id" => 1
            "discount_id" => null
            "shipping_id" => 1
            "created_at" => "2023-03-10 19:30:11"
            "updated_at" => "2023-03-28 10:41:52"
            "payment_method" => "cash" --}}
        <p>شناسه: {{ $order->uuid }}</p>
        <p>تاریخ: {{ $order->created_at }}</p>
        <p>نام: {{ $order->fullName }}</p>
        <p>تلفن: {{ $order->phone }}</p>
        <p>وضعیت: {{ $order->status }}</p>
        <p>مجموع: {{ $order->total }}</p>

        <p>محصولات:</p>
        <table>
            <thead>
                <tr>
                    <th>نام</th>
                    <th>تعداد</th>
                    <th>رنگ</th>
                    <th>قیمت</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->title }}</td>
                        <td>{{ $item->quantity }}</td>
                        <td>{{ $item->variation->color->name}}</td>
                        <td>{{ $item->price }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- "id" => 1
        "uuid" => "63e6066af3f3e1"
        "name" => "admin"
        "surname" => "admin"
        "lang" => null
        "email" => "admin@admin"
        "email_verified_at" => null
        "national_code" => null
        "phone" => ""
        "phone_verified_at" => null
        "password" => "$2y$10$iNxVpcKb092CsY2WcJ2E9upshpRLCwHZ2xivIVkEOGni5wSyYif.G"
        "one_time_password" => null
        "one_time_password_created_at" => null
        "security" => null
        "remember_token" => null
        "active" => 1
        "deleted_at" => null
        "created_at" => null
        "updated_at" => "2023-02-27 19:23:13" --}}
        <p>Customer Details:</p>
        <p>Name: {{ $user->fullName }}</p>
        <p>Email: {{ $user->email }}</p>
        <p>Phone: {{ $user->phone }}</p>

{{-- 
        "id" => 1
        "name" => "باربری"
        "price" => 50000
        "description" => "هزینه ارسال به شهرستات ، تا باربری تهران ۵۰/۰۰۰ هزار تومان می باشد."
        "created_at" => null
        "updated_at" => null --}}
        <p>Shipping Details:</p>
        <p>Name: {{ $shipping->name }}</p>
        <p>Price: {{ $shipping->price }}</p>

        {{-- "id" => 27
        "uuid" => "63ea8bea87bc5"
        "user_phone" => "5528999690"
        "amount" => 1000
        "status" => "pending"
        "currency" => "IRI"
        "description" => "payment for order 63e6113d4b3a3"
        "gateway" => null
        "method" => "card"
        "user_id" => 1
        "order_id" => 24
        "created_at" => "2023-02-13 19:13:46"
        "updated_at" => "2023-02-13 19:14:59"
        "card_number" => "12345"
        "card_pan" => "23456"
        "ref_id" => "6ba99a6" --}}
        @if(isset($products))
            <p>Payment Details:</p>
            <p>Method: {{ $payment->method }}</p>
            <p>Transaction ID: {{ $payment->uuid }}</p>
        @endif


    </body>
</html>

<style> 
    * { font-family: DejaVu Sans, sans-serif;}

</style>