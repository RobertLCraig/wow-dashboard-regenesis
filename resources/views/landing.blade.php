<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Regenesis | Officer Dashboard</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/brand/phoenix-mark.png') }}">

    {{-- Open Graph for Discord / Slack / Twitter unfurls. The
         large wordmark on the dark grey background previews well at
         the standard 1200x630-ish OG card size. --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="Regenesis Officer Dashboard">
    <meta property="og:description" content="Roster, raid leadership and Warcraft Logs tooling for the Regenesis guild on Silvermoon (EU).">
    <meta property="og:image" content="{{ url('img/brand/phoenix-wordmark-large.jpg') }}">
    <meta property="og:url" content="{{ url('/') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Regenesis Officer Dashboard">
    <meta name="twitter:description" content="Roster, raid leadership and Warcraft Logs tooling for the Regenesis guild on Silvermoon (EU).">
    <meta name="twitter:image" content="{{ url('img/brand/phoenix-wordmark-large.jpg') }}">

    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; background: #0f0f17; color: #e6e6f0; margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 2rem; }
        .card { max-width: 480px; padding: 2.5rem 2rem; text-align: center; }
        .brand { margin: 0 0 1.5rem; }
        .brand img { max-width: 240px; height: auto; }
        h1 { font-size: 1.75rem; margin: 0 0 0.5rem; }
        p { color: #aaa; line-height: 1.6; margin: 0 0 2rem; }
        a.btn { display: inline-block; background: #5865F2; color: white; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; font-weight: 600; }
        a.btn:hover { background: #4752c4; }
        .meta { margin-top: 2rem; font-size: 0.85rem; color: #666; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">
        <picture>
            <source srcset="{{ asset('img/brand/phoenix-wordmark.webp') }}" type="image/webp">
            <img src="{{ asset('img/brand/phoenix-wordmark.png') }}" alt="Regenesis" width="240">
        </picture>
    </div>
    <h1>Officer Dashboard</h1>
    <p>Sign in with Discord. Access is limited to members of the Regenesis server with an Officer, Big6, or GuildMaster role.</p>
    <a class="btn" href="{{ route('auth.discord.start') }}">Sign in with Discord</a>
    <div class="meta">
        @auth
            Signed in as {{ auth()->user()->discord_username }}. <a href="{{ route('dashboard') }}" style="color:#aaa">Open dashboard</a>.
        @endauth
    </div>
</div>
</body>
</html>
