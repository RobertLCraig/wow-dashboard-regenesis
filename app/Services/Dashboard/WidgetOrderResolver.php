<?php

namespace App\Services\Dashboard;

/**
 * Resolve the final widget order for a user's dashboard render.
 *
 * Inputs:
 *   - $available: the project's catalogue of widgets (from
 *                 config('dashboard.widgets')) in default order
 *   - $userOrder: the user's saved order (list of widget keys, or
 *                 null if no saved layout)
 *
 * Output: list of widget definitions in render order.
 *
 * Rules:
 *   - Widgets named in $userOrder render in that order.
 *   - Widgets in $available but not in $userOrder are appended in
 *     their default order, so newly-added widgets surface without
 *     forcing the user to re-edit their layout.
 *   - Keys in $userOrder that don't exist in $available are
 *     silently ignored (catches deleted widgets and typos).
 */
class WidgetOrderResolver
{
    /**
     * @param  list<array<string, mixed>>  $available
     * @param  list<string>|null           $userOrder
     * @return list<array<string, mixed>>
     */
    public static function resolve(array $available, ?array $userOrder): array
    {
        if (! $userOrder) {
            return array_values($available);
        }

        $byKey = [];
        foreach ($available as $widget) {
            $byKey[$widget['key']] = $widget;
        }

        $resolved = [];
        $seen = [];
        foreach ($userOrder as $key) {
            if (is_string($key) && isset($byKey[$key]) && ! isset($seen[$key])) {
                $resolved[] = $byKey[$key];
                $seen[$key] = true;
            }
        }

        // Append any default widgets the saved layout didn't cover.
        foreach ($available as $widget) {
            if (! isset($seen[$widget['key']])) {
                $resolved[] = $widget;
            }
        }

        return $resolved;
    }
}
