<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anmelden · ClickUp-Intake</title>
    <link rel="icon" href="{{ asset('images/favicon.svg') }}" type="image/svg+xml">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-gray-50 font-sans text-brand-dark antialiased dark:bg-brand-dark dark:text-gray-100">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4">
        <div class="border border-gray-200 bg-white p-8 dark:border-gray-700 dark:bg-gray-900">
            <img src="{{ asset('images/logo-full.svg') }}" alt="GUYCOLLE" class="mb-8 h-8 dark:hidden">
            <img src="{{ asset('images/logo-full-dark.svg') }}" alt="GUYCOLLE" class="mb-8 hidden h-8 dark:block">

            <h1 class="mb-2 text-2xl font-semibold">ClickUp-Intake</h1>
            <p class="mb-6 text-gray-600 dark:text-gray-400">Mails aus Outlook als ClickUp-Tasks erfassen.</p>

            @if (session('login_error'))
                <p class="mb-6 border-l-4 border-red-600 bg-red-50 p-3 text-red-800 dark:bg-red-950 dark:text-red-200" role="alert">
                    {{ session('login_error') }}
                </p>
            @endif

            @if ($configured)
                <a href="{{ route('auth.microsoft.redirect') }}"
                   class="block w-full bg-brand px-4 py-3 text-center font-semibold text-white hover:bg-brand-dark focus:outline-2 focus:outline-offset-2 focus:outline-brand">
                    Mit Microsoft anmelden
                </a>
            @else
                <p class="border-l-4 border-brand bg-gray-50 p-3 dark:bg-gray-800">
                    Die App ist noch nicht mit Microsoft 365 verbunden.
                    Ein Admin startet die Einrichtung auf dem Server mit
                    <code class="font-mono text-sm">php artisan intake:install</code>.
                </p>
            @endif
        </div>
    </main>
</body>
</html>
