<?php

namespace App\Services\Discord;

use App\Models\DiscordWebhook;
use Illuminate\Support\Collection;

/**
 * Resolves which webhook(s) a sender should post to. Senders ask for
 *   "all webhooks for the weekly digest"
 * or
 *   "all webhooks for event announcements on the heroic team"
 * and we hand back the matching DiscordWebhook rows in priority order.
 *
 * Team-scoped lookup falls back to guild-wide (team_slug NULL) when no
 * team-specific webhook is configured. That way a team announcement
 * still goes somewhere even if the officer has only set up the generic
 * announce webhook.
 */
class WebhookRouter
{
    /**
     * @return Collection<int, DiscordWebhook>
     */
    public function routeFor(string $purpose, ?string $teamSlug = null): Collection
    {
        $matches = DiscordWebhook::query()
            ->enabled()
            ->forPurpose($purpose)
            ->get();

        if ($teamSlug === null) {
            return $matches->where('team_slug', null)->values();
        }

        // Team-specific first, then guild-wide as a fallback.
        $teamScoped = $matches->where('team_slug', $teamSlug)->values();
        if ($teamScoped->isNotEmpty()) {
            return $teamScoped;
        }
        return $matches->where('team_slug', null)->values();
    }
}
