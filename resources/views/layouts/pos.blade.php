<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'POS' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        pos: {
                            bg: '#1f1f1f',
                            panel: '#2b2b2b',
                            card: '#3a3a3a',
                            accent: '#714B67',
                            accentHover: '#8a5f80',
                            line: '#4a4a4a',
                        }
                    }
                }
            }
        }
    </script>
    @livewireStyles
    <style>
        html, body { height: 100%; overflow: hidden; }
        .pos-scroll { scrollbar-width: thin; scrollbar-color: #555 transparent; }
        .pos-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
        .pos-scroll::-webkit-scrollbar-thumb { background: #555; border-radius: 4px; }
    </style>
</head>
<body class="bg-pos-bg text-white antialiased h-screen">
    {{ $slot }}
    @livewireScripts
</body>
</html>
