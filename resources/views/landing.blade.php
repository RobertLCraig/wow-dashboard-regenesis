<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Regenesis | Officer Dashboard</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; background: #0f0f17; color: #e6e6f0; margin: 0; min-height: 100vh; display: grid; place-items: center; }
        .card { max-width: 480px; padding: 2.5rem 2rem; text-align: center; }
        h1 { font-size: 1.75rem; margin: 0 0 0.5rem; }
        p { color: #aaa; line-height: 1.6; margin: 0 0 2rem; }
        a.btn { display: inline-block; background: #5865F2; color: white; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; font-weight: 600; }
        a.btn:hover { background: #4752c4; }
        .meta { margin-top: 2rem; font-size: 0.85rem; color: #666; }
    </style>
</head>
<body>
<div class="card">
    <h1>Regenesis Officer Dashboard</h1>
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
