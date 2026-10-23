{{-- Public layout for the staff application form (/join). Not the admin
     layout: applicants have no account, no sidebar and no session to show.
     The venue name and logo come from Company Settings, as on the guest menu. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => 'Join the team · '.\App\Models\Company::displayName()])
        <meta name="robots" content="noindex, nofollow">
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
        <div class="mx-auto flex min-h-svh w-full max-w-xl flex-col gap-6 px-4 py-8">
            <header class="flex flex-col items-center gap-3 text-center">
                @if ($logo = \App\Services\BrandingLogo::url())
                    <img src="{{ $logo }}" alt="" class="h-16 w-auto">
                @endif
                <h1 class="text-xl font-semibold">{{ \App\Models\Company::displayName() }}</h1>
            </header>

            <main class="flex flex-col gap-6">
                {{ $slot }}
            </main>
        </div>
        @fluxScripts
    </body>
</html>
