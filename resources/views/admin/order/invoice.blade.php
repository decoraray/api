<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  dir="rtl" lang="fa">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Laravel</title>

        <!-- Fonts -->
        <link href="https://fonts.bunny.net/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">

        <!-- Styles -->

    </head>

    <body class="antialiased">
        <h1>فاکتور فروش</h1>

        <p>شناسه: {{ $order->uuid }}</p>
        <p>تاریخ: {{ $order->created_at }}</p>
        <p>نام: {{ $order->fullName }}</p>
        <p>تلفن: {{ $order->phone }}</p>
        <p>وضعیت: {{ $order->status }}</p>
        <p>مجموع: {{ $order->total }}</p>

        <h2>محصولات:</h2>
        <table>
            <thead>
                <tr>
                    <th>نام</th>
                    <th>تعداد</th>
                    <th>رنگ</th>
                    <th>قیمت</th>
                    <th>توضیحات</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->title }}</td>
                        <td>{{ $item->quantity }}</td>
                        <td>{{ $item->variation->color->name}}</td>
                        <td>{{ $item->price }}</td>
                        <td>{{ $item->description }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>مشخصات مشتری: </h2>
        <p>نام: {{ $user->fullName }}</p>
        <p>ایمیل: {{ $user->email }}</p>
        <p>تلفن: {{ $user->phone }}</p>

        <h2>ارسال:</h2>
        <p>نام گیرنده: {{ $shipping->name }}</p>
        <p>هزینه: {{ $shipping->price }}</p>

        @if(isset($products))
            <p>مشخصات پرداخت:</p>
            <p>روش پرداخت: {{ $payment->method }}</p>
            <p>مبلغ پرداخت: {{ $payment->amount }}</p>
            <p>شناسه پرداخت: {{ $payment->uuid }}</p>
        @endif

    </body>
</html>

<style> 
    * { font-family: DejaVu Sans, sans-serif;}

</style>