<!DOCTYPE html>
<html lang="{{ \Brume\Support\LocaleMap::toFrontend(app()->getLocale()) }}" style="--br-accent-custom: {{ config('brume.layout.accent') }}">
<head>
    <script>(function(){try{var s=localStorage.getItem('brume.color-scheme');if(s==='light'||s==='dark'){document.documentElement.dataset.theme=s;}}catch(e){}})();</script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('brume.name') }}</title>
    {{ \Brume\Support\Assets::tags() }}
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
