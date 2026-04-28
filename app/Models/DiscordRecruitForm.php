<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscordRecruitForm extends Model
{
    protected $fillable = [
        'discord_thread_id',
        'guild_id',
        'channel_id',
        'thread_title',
        'discord_username',
        'character_name',
        'form_embed_raw',
        'form_fields',
        'posted_at',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'form_embed_raw' => 'array',
            'form_fields' => 'array',
            'posted_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Discord deep-link to the forum post. Forum posts are themselves
     * channels, so the URL is /channels/{guild}/{thread_id}.
     */
    public function discordUrl(): string
    {
        if ($this->guild_id) {
            return sprintf(
                'https://discord.com/channels/%s/%s',
                $this->guild_id,
                $this->discord_thread_id,
            );
        }
        return "https://discord.com/channels/@me/{$this->discord_thread_id}";
    }
}
