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
}
