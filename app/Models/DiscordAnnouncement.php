<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscordAnnouncement extends Model
{
    protected $fillable = [
        'discord_message_id',
        'guild_id',
        'channel_id',
        'author_username',
        'author_id',
        'content',
        'attachments',
        'posted_at',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'posted_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * The attachments the feed can draw as a thumbnail. Discord reports
     * a content_type for anything it recognises; a file it doesn't
     * (rare) stays stored but isn't rendered as an image.
     *
     * @return list<array<string,mixed>>
     */
    public function imageAttachments(): array
    {
        return array_values(array_filter(
            $this->attachments ?? [],
            fn ($a) => is_array($a) && str_starts_with((string) ($a['content_type'] ?? ''), 'image/'),
        ));
    }

    /**
     * Discord deep-link to the original message. Only resolvable when
     * we know the guild_id; falls back to the channel page if not.
     */
    public function discordUrl(): string
    {
        if ($this->guild_id) {
            return sprintf(
                'https://discord.com/channels/%s/%s/%s',
                $this->guild_id,
                $this->channel_id,
                $this->discord_message_id,
            );
        }

        return "https://discord.com/channels/@me/{$this->channel_id}";
    }
}
