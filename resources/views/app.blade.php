<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'BaseCRM') }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        (function () {
            var STORAGE_KEY = 'crm-theme';
            @auth
            window.__USER_THEME__ = @json(auth()->user()->theme ?? 'system');
            @else
            window.__USER_THEME__ = null;
            @endauth
            var preference = window.__USER_THEME__ || localStorage.getItem(STORAGE_KEY) || 'system';

            function resolveTheme(pref) {
                if (pref === 'dark') return 'dark';
                if (pref === 'light') return 'light';
                return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }

            if (resolveTheme(preference) === 'dark') {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ mix('css/app.css') }}">
    @inertiaHead
</head>
<body class="font-sans antialiased bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
    @inertia
    <script src="{{ mix('js/app.js') }}" defer></script>
</body>
</html>
