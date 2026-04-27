<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-user display + theme prefs. Endpoints are intentionally tiny;
 * each one updates a single column on the authenticated user and
 * redirects back. Usable from a plain HTML <form> without any JS.
 */
class PreferencesController extends Controller
{
    public function display(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'display_mode' => ['required', Rule::in(User::DISPLAY_MODES)],
        ]);

        $request->user()->forceFill(['display_mode' => $data['display_mode']])->save();

        return redirect()->back();
    }

    public function theme(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(User::THEMES)],
        ]);

        $request->user()->forceFill(['theme' => $data['theme']])->save();

        return redirect()->back();
    }

    /**
     * Save the user's dashboard widget order. Accepts a `layout`
     * array of widget keys, or a `reset` flag to clear the saved
     * layout (so the user falls back to the project default order).
     *
     * Unknown keys are silently dropped at save time so a stale
     * layout never persists; the resolver does the same on render.
     */
    public function dashboardLayout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'layout'   => ['nullable', 'array'],
            'layout.*' => ['string'],
            'reset'    => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('reset')) {
            $request->user()->forceFill(['dashboard_layout' => null])->save();
            return redirect()->back();
        }

        $known = collect((array) config('dashboard.widgets', []))->pluck('key')->all();
        $layout = collect($data['layout'] ?? [])
            ->filter(fn ($key) => is_string($key) && in_array($key, $known, true))
            ->values()
            ->all();

        $request->user()->forceFill([
            'dashboard_layout' => $layout ?: null,
        ])->save();

        return redirect()->back();
    }
}
