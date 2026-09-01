<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sign in' }} - {{ config('branding.app_name', config('app.name')) }}</title>
</head>
<body>
    <main>
        <h1>{{ config('branding.app_name', config('app.name')) }}</h1>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <ul role="alert">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        {{ $slot ?? '' }}
        @yield('content')
    </main>
</body>
</html>
