<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>About</title>
    <link href="https://fonts.bunny.net/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Nunito', sans-serif; margin: 0; background: #f3f4f6; }
        .container { max-width: 72rem; margin: 0 auto; padding: 2rem 1.5rem; }
        .card { background: #fff; border-radius: 0.5rem; box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); padding: 1.5rem; }
        h1 { font-size: 1.5rem; margin-top: 0; }
        a { color: #4f46e5; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>About</h1>
            <p>This is the about page.</p>
            <p><a href="{{ url('/') }}">Back to home</a></p>
        </div>
    </div>
</body>
</html>
