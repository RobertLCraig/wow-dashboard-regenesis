<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Regenesis</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #0f0f17; color: #e6e6f0; margin: 0; padding: 2rem; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        h1 { font-size: 1.5rem; margin: 0; }
        .me { font-size: 0.9rem; color: #aaa; }
        .me form { display: inline; }
        .me button { background: none; border: none; color: #5865F2; cursor: pointer; font: inherit; padding: 0; }
        .placeholder { padding: 2rem; background: #1a1a26; border-radius: 8px; color: #888; }
    </style>
</head>
<body>
<header>
    <h1>Regenesis Officer Dashboard</h1>
    <div class="me">
        Signed in as {{ auth()->user()->discord_username }} ({{ auth()->user()->tier }})
        <form method="POST" action="{{ route('logout') }}" style="display:inline">@csrf<button>Sign out</button></form>
    </div>
</header>
<div class="placeholder">
    Widgets coming next: roster health, recently inactive, recent log timeline, action queue, alt groups, upcoming events. The data layer is already populated - check `php artisan tinker` and query App\Models\Member / MemberEvent / LogEvent.
</div>
</body>
</html>
