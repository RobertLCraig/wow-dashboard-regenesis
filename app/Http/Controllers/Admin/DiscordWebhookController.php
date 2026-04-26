<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiscordWebhook;
use App\Services\Discord\DiscordWebhookPoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Officer CRUD for Discord webhooks. The senders (digest, future event
 * announcer, etc.) all read through WebhookRouter, so adding a row here
 * is the only thing an officer needs to do to wire up a new channel.
 */
class DiscordWebhookController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        return view('admin.webhooks.index', [
            'webhooks' => DiscordWebhook::query()
                ->orderBy('purpose')->orderBy('team_slug')->orderBy('label')
                ->get(),
            'purposes' => DiscordWebhook::PURPOSES,
            'teamSlugs' => array_keys((array) config('raidhelper.teams', [])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $validated = $this->validatePayload($request);
        $validated['created_by_user_id'] = auth()->id();
        DiscordWebhook::query()->create($validated);

        return redirect()
            ->route('admin.webhooks.index')
            ->with('status', "Added webhook \"{$validated['label']}\".");
    }

    public function update(Request $request, DiscordWebhook $webhook): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $validated = $this->validatePayload($request, isUpdate: true);
        // Empty url on edit means "leave the existing one alone".
        if (empty($validated['url'])) {
            unset($validated['url']);
        }
        $webhook->forceFill($validated)->save();

        return redirect()
            ->route('admin.webhooks.index')
            ->with('status', "Updated \"{$webhook->label}\".");
    }

    public function destroy(DiscordWebhook $webhook): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $label = $webhook->label;
        $webhook->delete();

        return redirect()
            ->route('admin.webhooks.index')
            ->with('status', "Removed \"{$label}\".");
    }

    /**
     * Send a tiny "ping from Regenesis dashboard" so the officer can
     * confirm the URL is wired correctly without waiting for the next
     * scheduled job. Doesn't update last_posted_at - that's reserved
     * for real sends.
     */
    public function test(DiscordWebhook $webhook): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $r = (new DiscordWebhookPoster($webhook->url))->post($this->pingMessage($webhook));
        if ($r['error']) {
            return redirect()
                ->route('admin.webhooks.index')
                ->withErrors(['webhook' => "Test failed: {$r['error']}"]);
        }
        return redirect()
            ->route('admin.webhooks.index')
            ->with('status', "Test ping sent to \"{$webhook->label}\".");
    }

    /**
     * Bulk-ping every enabled webhook in one click. Useful after
     * editing several rows or migrating from the legacy env var to
     * confirm the whole set is wired.
     */
    public function testAll(): RedirectResponse
    {
        abort_unless(auth()->user()?->isOfficerTier(), 403);

        $hooks = DiscordWebhook::query()->enabled()->get();
        if ($hooks->isEmpty()) {
            return redirect()
                ->route('admin.webhooks.index')
                ->with('status', 'No enabled webhooks to test.');
        }

        $ok = 0;
        $failed = [];
        foreach ($hooks as $hook) {
            $r = (new DiscordWebhookPoster($hook->url))->post($this->pingMessage($hook));
            if ($r['error']) {
                $failed[] = "\"{$hook->label}\" ({$r['error']})";
                continue;
            }
            $ok++;
        }

        if ($failed === []) {
            return redirect()
                ->route('admin.webhooks.index')
                ->with('status', "Test ping sent to {$ok} webhook(s).");
        }
        $failedList = implode('; ', $failed);
        return redirect()
            ->route('admin.webhooks.index')
            ->withErrors([
                'webhook' => "Test ping reached {$ok} webhook(s) but failed for: {$failedList}",
            ]);
    }

    private function pingMessage(DiscordWebhook $webhook): string
    {
        return sprintf(
            "Test ping from Regenesis dashboard - %s (%s%s).",
            $webhook->label,
            DiscordWebhook::purposeLabel($webhook->purpose),
            $webhook->team_slug ? " / {$webhook->team_slug}" : '',
        );
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $known = array_keys(DiscordWebhook::PURPOSES);

        return $request->validate([
            'label' => ['required', 'string', 'max:255'],
            // On create the URL is required; on update it can be left
            // blank to mean "keep the existing URL".
            'url' => [
                $isUpdate ? 'nullable' : 'required',
                'string',
                'max:1024',
                'regex:/^https:\/\/(canary\.|ptb\.)?discord(app)?\.com\/api\/webhooks\/[0-9]+\/[A-Za-z0-9_\-]+$/',
            ],
            'purpose' => ['required', 'string', 'max:32', 'in:' . implode(',', $known)],
            'team_slug' => ['nullable', 'string', 'max:32'],
            'enabled' => ['nullable', 'boolean'],
        ]);
    }
}
