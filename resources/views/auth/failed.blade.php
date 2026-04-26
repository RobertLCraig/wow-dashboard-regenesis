<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sign-in failed | Regenesis</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/brand/phoenix-mark.png') }}">
    <style>
        body{font-family:ui-sans-serif,system-ui,sans-serif;background:#0f0f17;color:#e6e6f0;margin:0;min-height:100vh;display:grid;place-items:center;text-align:center;padding:2rem;}
        .card{max-width:480px;}
        .brand{margin:0 0 1.5rem;}
        .brand img{max-width:200px;height:auto;}
        h1{font-size:1.5rem;}
        p{color:#aaa;line-height:1.6;}
        a{color:#5865F2;}
    </style>
</head>
<body>
<div class="card">
    <div class="brand">
        <picture>
            <source srcset="{{ asset('img/brand/phoenix-wordmark.webp') }}" type="image/webp">
            <img src="{{ asset('img/brand/phoenix-wordmark.png') }}" alt="Regenesis" width="200">
        </picture>
    </div>
    <h1>Sign-in didn't complete</h1>
    <p>Discord rejected the OAuth handshake. Common causes: cancelled the prompt, the Discord application's redirect URI doesn't match, or the configured client secret is wrong.</p>
    <p><a href="{{ route('auth.discord.start') }}">Try again</a> or <a href="{{ route('landing') }}">go home</a>.</p>
</div>
</body>
</html>
