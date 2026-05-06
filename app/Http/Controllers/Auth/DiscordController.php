<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Discord\RoleVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class DiscordController extends Controller
{
    /**
     * Kick off the OAuth handshake. We ask for two scopes only:
     *   - identify: basic Discord user info (id, username, avatar)
     *   - guilds.members.read: read THIS user's roles within OUR guild
     *
     * Notably we do NOT request `email` or `guilds` (the broader
     * "list every server you're in" scope) - we don't need either.
     */
    public function start(): Response
    {
        return Socialite::driver('discord')
            ->scopes(['identify', 'guilds.members.read'])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        Log::info('Discord OAuth callback received', [
            'has_code' => request()->has('code'),
            'has_state' => request()->has('state'),
            'request_state' => request()->input('state'),
            'session_state' => request()->session()->get('state'),
            'session_id' => request()->session()->getId(),
            'exception_class' => null,
        ]);

        try {
            $discordUser = Socialite::driver('discord')->user();
        } catch (\Throwable $e) {
            Log::warning('Discord OAuth callback failed', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'request_state' => request()->input('state'),
                'session_state' => request()->session()->get('state'),
                'session_id' => request()->session()->getId(),
            ]);
            return redirect()->route('auth.discord.failed');
        }

        // Find or create the local User row keyed on the Discord snowflake.
        // The default Laravel users.email column is NOT NULL so we synthesise
        // a placeholder; OAuth-only accounts never use email/password.
        $user = User::query()->where('discord_id', $discordUser->getId())->first();

        if (! $user) {
            $user = new User;
            $user->discord_id = $discordUser->getId();
            $user->email = "discord-{$discordUser->getId()}@regenesis.invalid";
            $user->password = bcrypt(bin2hex(random_bytes(32)));
        }

        $user->name = $discordUser->getName() ?? $discordUser->getNickname() ?? 'Discord User';
        $user->discord_username = $discordUser->getNickname() ?? $discordUser->getName();
        $user->avatar_url = $discordUser->getAvatar();
        $user->discord_refresh_token = $discordUser->refreshToken;
        $user->save();

        // Verify they're an officer NOW so we can fail fast at the OAuth
        // step rather than at the next page load.
        $tier = RoleVerifier::fromConfig()->tierFor($user, force: true);
        if ($tier === null) {
            Auth::logout();
            return redirect()->route('auth.discord.unauthorised');
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(): RedirectResponse
    {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return redirect('/');
    }
}
