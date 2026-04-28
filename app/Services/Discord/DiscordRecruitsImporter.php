<?php

namespace App\Services\Discord;

use App\Models\DiscordRecruitForm;
use Carbon\CarbonImmutable;

/**
 * Pulls forum posts from the new-recruits channel into
 * discord_recruit_forms. Each forum post is a thread; the post body
 * (form bot's embed) is the first message of that thread.
 *
 * Title pattern is "Character: NAME | Discord: NAME" - threads that
 * don't match the pattern (pinned instructional posts, free-form
 * conversation threads, etc.) are skipped silently.
 */
class DiscordRecruitsImporter
{
    /**
     * Title regex. Tolerant of the surrounding whitespace variations
     * the form bot has shipped historically.
     */
    private const TITLE_PATTERN = '/^\s*Character:\s*(?<character>.+?)\s*\|\s*Discord:\s*(?<discord>.+?)\s*$/u';

    public function __construct(
        private readonly DiscordRecruitsClient $client,
    ) {}

    /**
     * @return array{imported:int, skipped:int, total_seen:int, archived_pages:int}
     */
    public function pull(int $archivedPages = 5): array
    {
        if (! $this->client->isConfigured()) {
            throw new \RuntimeException(
                'Discord bot token / recruits channel id are not configured.'
            );
        }

        $now = CarbonImmutable::now();
        $guildId = (string) config('discord.guild_id', '');

        $threads = array_merge(
            $this->client->activeThreads(),
            $this->client->archivedThreads($archivedPages),
        );

        $imported = 0;
        $skipped = 0;

        foreach ($threads as $thread) {
            $threadId = $thread['id'] ?? null;
            $title = $thread['name'] ?? null;
            if (! is_string($threadId) || $threadId === '' || ! is_string($title)) {
                $skipped++;
                continue;
            }

            $parsed = $this->parseTitle($title);
            if ($parsed === null) {
                // Pinned / instructional / free-form threads. Not an
                // error; just not a recruit form.
                $skipped++;
                continue;
            }

            // Forum thread creation time lives on the thread itself,
            // not on its first message; fall back to now if missing.
            $postedAt = $this->extractPostedAt($thread) ?? $now;

            // Fetch the form bot's embed for the body. One HTTP call
            // per thread - acceptable at recruit volumes (<1k lifetime).
            $firstMessage = null;
            try {
                $firstMessage = $this->client->firstMessage($threadId);
            } catch (\Throwable) {
                // Body fetch failed; persist the title-derived data
                // anyway so the alias is still useful.
            }

            [$embedRaw, $fields] = $this->extractEmbed($firstMessage);

            DiscordRecruitForm::query()->updateOrCreate(
                ['discord_thread_id' => $threadId],
                [
                    'guild_id' => $guildId !== '' ? $guildId : null,
                    'channel_id' => $this->client->channelId(),
                    'thread_title' => $title,
                    'discord_username' => $parsed['discord'],
                    'character_name' => $parsed['character'],
                    'form_embed_raw' => $embedRaw,
                    'form_fields' => $fields,
                    'posted_at' => $postedAt,
                    'fetched_at' => $now,
                ],
            );
            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'total_seen' => count($threads),
            'archived_pages' => $archivedPages,
        ];
    }

    /**
     * @return array{character:string, discord:string}|null
     */
    private function parseTitle(string $title): ?array
    {
        if (preg_match(self::TITLE_PATTERN, $title, $m) !== 1) {
            return null;
        }
        $character = trim((string) ($m['character'] ?? ''));
        $discord = trim((string) ($m['discord'] ?? ''));
        if ($character === '' || $discord === '') {
            return null;
        }
        return ['character' => $character, 'discord' => $discord];
    }

    private function extractPostedAt(array $thread): ?CarbonImmutable
    {
        // Forum posts expose creation time via thread_metadata.create_timestamp.
        // Fall back to the snowflake's embedded timestamp if not present.
        $iso = $thread['thread_metadata']['create_timestamp'] ?? null;
        if (is_string($iso) && $iso !== '') {
            try {
                return CarbonImmutable::parse($iso);
            } catch (\Throwable) {
                // fall through to snowflake decode
            }
        }
        $id = $thread['id'] ?? null;
        if (is_string($id) && ctype_digit($id)) {
            // Discord epoch is 2015-01-01T00:00:00Z = 1420070400000ms.
            $ms = ((int) $id >> 22) + 1420070400000;
            return CarbonImmutable::createFromTimestampMs($ms);
        }
        return null;
    }

    /**
     * @return array{0: array<string,mixed>|null, 1: array<string,string>|null}
     */
    private function extractEmbed(?array $message): array
    {
        if ($message === null) {
            return [null, null];
        }
        $embeds = $message['embeds'] ?? null;
        if (! is_array($embeds) || empty($embeds)) {
            // Form bot might post without an embed in unusual cases;
            // keep the message content as a single field so we don't
            // lose context.
            $content = $message['content'] ?? null;
            if (is_string($content) && $content !== '') {
                return [null, ['content' => $content]];
            }
            return [null, null];
        }
        $embed = $embeds[0];
        if (! is_array($embed)) {
            return [null, null];
        }

        $fields = [];
        $rawFields = $embed['fields'] ?? [];
        if (is_array($rawFields)) {
            foreach ($rawFields as $f) {
                if (! is_array($f)) {
                    continue;
                }
                $name = isset($f['name']) && is_string($f['name']) ? $f['name'] : null;
                $value = isset($f['value']) && is_string($f['value']) ? $f['value'] : null;
                if ($name === null || $value === null) {
                    continue;
                }
                $fields[$this->snakeKey($name)] = trim($value);
            }
        }
        // Embed body / description sits outside fields on some templates.
        $description = $embed['description'] ?? null;
        if (is_string($description) && $description !== '' && ! isset($fields['description'])) {
            $fields['description'] = trim($description);
        }

        return [$embed, $fields !== [] ? $fields : null];
    }

    private function snakeKey(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9]+/u', '_', $name) ?? $name;
        return trim($name, '_');
    }
}
