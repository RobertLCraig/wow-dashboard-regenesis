<?php

namespace App\Services\RaidHelper;

use App\Models\EventSignup;
use App\Models\RaidEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Convert a Raid-Helper event payload (from a webhook OR a polled GET
 * response - same shape per the docs) into our local raid_events +
 * event_signups rows.
 *
 * Idempotent by design: the same payload re-applied is a no-op aside
 * from a touched updated_at and an ics_sequence bump if the underlying
 * fields actually changed.
 */
class EventUpserter
{
    /**
     * @param  array<string,mixed>  $payload  The Raid-Helper event object
     *                                         (id, channelId, title, etc.)
     */
    public function upsert(array $payload): RaidEvent
    {
        $eventId = (string) ($payload['id'] ?? '');
        if ($eventId === '') {
            throw new \InvalidArgumentException('Raid-Helper event payload missing "id"');
        }

        $serverId = (string) ($payload['serverId'] ?? config('raidhelper.server_id'));
        $channelId = (string) ($payload['channelId'] ?? config('raidhelper.default_channel_id'));

        $startsAt = isset($payload['startTime']) && is_int($payload['startTime'])
            ? CarbonImmutable::createFromTimestampUTC($payload['startTime'])
            : null;
        $endsAt = isset($payload['endTime']) && is_int($payload['endTime'])
            ? CarbonImmutable::createFromTimestampUTC($payload['endTime'])
            : null;
        // v4 list endpoint sends `closeTime`; the v2/v3 docs (and webhooks
        // historically) used `closingTime`. Accept either so the upserter
        // works for both webhook payloads and bulk-list backfills.
        $closingTimestamp = $payload['closingTime'] ?? $payload['closeTime'] ?? null;
        $closingAt = is_int($closingTimestamp)
            ? CarbonImmutable::createFromTimestampUTC($closingTimestamp)
            : null;

        return DB::transaction(function () use ($payload, $eventId, $serverId, $channelId, $startsAt, $endsAt, $closingAt) {
            // Stable RFC 5545 UID, minted once and never changed across
            // edits so calendar clients keep the same VEVENT identity.
            $event = RaidEvent::query()->withTrashed()->firstOrCreate(
                ['raidhelper_event_id' => $eventId],
                [
                    'ics_uid' => 'regenesis-' . Str::ulid()->toBase32() . '@regenesis-silvermoon.eu',
                    'ics_sequence' => 0,
                    'server_id' => $serverId,
                    'channel_id' => $channelId,
                    'title' => (string) ($payload['title'] ?? 'Untitled'),
                    'starts_at' => $startsAt,
                ]
            );

            // Detect material changes that should bump ics_sequence so
            // calendar clients refresh their copy. Sign-up changes don't
            // count; only the user-visible event details do. Skip on a
            // freshly created row - the firstOrCreate placeholders are
            // incomplete and would always look "different" from the full
            // payload, falsely bumping sequence to 1 on first sight.
            $changed = false;
            if (! $event->wasRecentlyCreated) {
                $materialFields = [
                    'title' => $payload['title'] ?? null,
                    'description' => $payload['description'] ?? null,
                    'starts_at' => $startsAt?->toDateTimeString(),
                    'ends_at' => $endsAt?->toDateTimeString(),
                    'channel_id' => $channelId,
                ];
                foreach ($materialFields as $key => $value) {
                    if ((string) $event->{$key} !== (string) $value) {
                        $changed = true;
                        break;
                    }
                }
            }

            $event->fill([
                'server_id' => $serverId,
                'channel_id' => $channelId,
                'leader_id' => $payload['leaderId'] ?? null,
                'leader_name' => $payload['leaderName'] ?? null,
                'title' => (string) ($payload['title'] ?? 'Untitled'),
                'description' => $payload['description'] ?? null,
                'template_id' => isset($payload['templateId']) ? (string) $payload['templateId'] : null,
                'color' => $payload['color'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'closing_at' => $closingAt,
                'advanced_settings_json' => $payload['advancedSettings'] ?? null,
                'classes_json' => $payload['classes'] ?? null,
                'roles_json' => $payload['roles'] ?? null,
                'last_synced_at' => now(),
                'deleted_at' => null,
            ]);

            if ($changed) {
                $event->ics_sequence = (int) $event->ics_sequence + 1;
            }
            $event->save();

            // Only touch signups when the payload explicitly carries the
            // array. The v4 list endpoint without IncludeSignUps=true
            // omits it entirely - we shouldn't wipe cached signups just
            // because a lighter response didn't include them.
            if (array_key_exists('signUps', $payload) && is_array($payload['signUps'])) {
                $this->syncSignups($event, $payload['signUps']);
            }

            return $event;
        });
    }

    public function softDelete(string $raidHelperEventId): void
    {
        RaidEvent::query()
            ->where('raidhelper_event_id', $raidHelperEventId)
            ->each(fn (RaidEvent $e) => $e->delete());
    }

    /**
     * Replace the cached sign-ups for an event with whatever the latest
     * payload contains. Raid-Helper's signUps array is the source of
     * truth - we just mirror.
     *
     * @param  array<int,array<string,mixed>>  $signups
     */
    private function syncSignups(RaidEvent $event, array $signups): void
    {
        $existing = $event->signups()->get()->keyBy('raidhelper_signup_id');
        $seen = [];

        foreach ($signups as $signup) {
            if (! is_array($signup)) {
                continue;
            }
            $signupId = isset($signup['id']) ? (string) $signup['id'] : null;
            if ($signupId === null) {
                continue;
            }
            $seen[$signupId] = true;
            $signedAt = isset($signup['entryTime']) && is_int($signup['entryTime'])
                ? CarbonImmutable::createFromTimestampUTC($signup['entryTime'])
                : null;

            $payload = [
                'raid_event_id' => $event->id,
                'raidhelper_signup_id' => $signupId,
                'user_id' => $signup['userId'] ?? null,
                'name' => (string) ($signup['name'] ?? $signup['userId'] ?? 'unknown'),
                'class_name' => $signup['className'] ?? null,
                'spec_name' => $signup['specName'] ?? null,
                'spec2_name' => $signup['spec2Name'] ?? null,
                'spec3_name' => $signup['spec3Name'] ?? null,
                'role' => $signup['role'] ?? null,
                'status' => (string) ($signup['status'] ?? $signup['role'] ?? 'signed'),
                'position' => $signup['position'] ?? null,
                'is_fake' => (bool) ($signup['isFake'] ?? false),
                'signed_up_at' => $signedAt,
            ];

            if ($existing->has($signupId)) {
                $existing[$signupId]->forceFill($payload)->save();
            } else {
                EventSignup::query()->create($payload);
            }
        }

        // Drop any signups that aren't in the latest payload (Raid-Helper
        // doesn't report removals separately - they just disappear from
        // the array).
        foreach ($existing as $signupId => $row) {
            if (! isset($seen[$signupId])) {
                $row->delete();
            }
        }
    }
}
