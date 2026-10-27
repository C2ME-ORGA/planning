<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex, nofollow">

        <title>Planning du jour — Planning C2ME</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-stone-100 font-sans text-stone-900 antialiased">
        <a href="#planning-content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-amber-700 focus:px-4 focus:py-3 focus:text-white">
            Aller au planning
        </a>

        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Planning C2ME</p>
                    <h1 class="text-lg font-semibold">Planning prévisionnel</h1>
                </div>

                @auth
                    <p class="text-right text-xs text-stone-600">
                        <span class="block font-medium text-stone-900">{{ auth()->user()->name }}</span>
                        {{ auth()->user()->role->label() }}
                    </p>
                @endauth
            </div>
        </header>

        <main id="planning-content" class="mx-auto max-w-3xl px-4 py-6">
            {{ $slot }}
        </main>

        <footer class="mx-auto max-w-3xl px-4 pb-8 text-xs text-stone-500">
            Le planning est prévisionnel. Il ne remplace pas la saisie des heures réellement travaillées.
        </footer>

        @livewireScripts
        <script>
            setInterval(() => {
                const summary = document.querySelector('summary');
                if (! summary) { document.title = 'DIAG none'; return; }
                const style = getComputedStyle(summary);
                document.title = 'DIAG ' + JSON.stringify({
                    listStyleType: style.listStyleType,
                    display: style.display,
                    open: summary.parentElement.open,
                    marker: getComputedStyle(summary, '::marker').content,
                    parent: summary.parentElement.outerHTML.slice(0, 80),
                    detailsDisplay: getComputedStyle(summary.parentElement).display,
                    detailsContentVisibility: getComputedStyle(summary.parentElement).contentVisibility,
                    listDisplay: document.querySelector('details ol') ? getComputedStyle(document.querySelector('details ol')).display : 'none',
                    listRect: document.querySelector('details ol') ? document.querySelector('details ol').getBoundingClientRect().height : 0,
                    detailsRule: (() => { for (const sheet of document.styleSheets) { try { for (const rule of sheet.cssRules) { if (String(rule.cssText).includes('details')) return String(rule.cssText).slice(0, 300); } } catch (e) {} } return 'none'; })(),
                });
            }, 500);
        </script>
    </body>
</html>
