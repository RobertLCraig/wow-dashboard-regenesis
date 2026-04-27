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
        'posted_at',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
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
