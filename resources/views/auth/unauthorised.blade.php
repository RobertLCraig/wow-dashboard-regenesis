<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Not authorised | Regenesis</title>
    <style>body{font-family:ui-sans-serif,system-ui,sans-serif;background:#0f0f17;color:#e6e6f0;margin:0;min-height:100vh;display:grid;place-items:center;text-align:center;padding:2rem;} .card{max-width:480px;} h1{font-size:1.5rem;} p{color:#aaa;line-height:1.6;} a{color:#5865F2;}</style>
</head>
<body>
<div class="card">
    <h1>You don't have access</h1>
    <p>This dashboard is restricted to members of the Regenesis Discord server with an Officer, Big6, or GuildMaster role.</p>
    <p>If you should have access, ask an officer to verify your role assignment, then <a href="{{ route('auth.discord.start') }}">sign in again</a>.</p>
</div>
</body>
</html>
