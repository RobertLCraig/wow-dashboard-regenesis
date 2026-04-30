<?php

namespace App\Services\Discord;

/**
 * Thrown when a Discord API call fails in a way that's likely transient
 * (5xx, 429, network error, timeout) rather than a real authorisation
 * problem. Callers catch this and fall back to the last known good
 * state instead of locking the user out for the cache TTL.
 */
class TransientDiscordFailure extends \RuntimeException
{
}
