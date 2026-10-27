<!DOCTYPE html>
<html lang="{{ \Brume\Support\LocaleMap::toFrontend(app()->getLocale()) }}" style="--br-accent-custom: {{ \Brume\Support\Color::accent() }}">
<head>
    <script{!! ($cspNonce = \Illuminate\Support\Facades\Vite::cspNonce()) ? ' nonce="'.e($cspNonce).'"' : '' !!}>(function(){try{var s=localStorage.getItem('brume.color-scheme');if(s==='light'||s==='dark'){document.documentElement.dataset.theme=s;}}catch(e){}})();</script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('brume.name') }}</title>
    {{ \Brume\Support\Assets::tags() }}
    @inertiaHead
</head>
{{-- The stylesheet defines the token but nothing paints <body> until React mounts .br-root;
     without this a dark-mode reload shows the browser's white canvas first. --}}
<body style="background-color: var(--br-surface-canvas)">
    @inertia
</body>
</html>
