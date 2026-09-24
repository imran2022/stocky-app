<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Dashboard\DashboardPreferences;
use Illuminate\Http\Request;

/**
 * Modern Dashboard preferences: which dashboard (Classic / Modern) and the section layout.
 * A user changes only their own row; the organisation default needs the same permission as system settings.
 */
class DashboardPreferenceController extends BaseController
{
    public function show(Request $request)
    {
        $user = $request->user('api');

        $canSetDefault = $user->can('update', Setting::class);

        return response()->json(DashboardPreferences::resolve((int) $user->id, $canSetDefault) + [
            'can_set_default' => $canSetDefault,
        ]);
    }

    public function updateMine(Request $request)
    {
        $user = $request->user('api');
        $request->validate([
            'style' => 'sometimes|nullable|in:'.implode(',', DashboardPreferences::STYLES),
            'layout' => 'sometimes|nullable|array',
        ]);
        // The organisation can switch the choice off; administrators keep it.
        if ($request->has('style') && ! DashboardPreferences::switchAllowed() && ! $user->can('update', Setting::class)) {
            return response()->json(['message' => 'Choosing the dashboard style is turned off for your organisation.'], 403);
        }
        DashboardPreferences::saveFor((int) $user->id, $request->only(['style', 'layout']));

        return $this->show($request);
    }

    public function updateDefault(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', Setting::class);
        $request->validate([
            'style' => 'sometimes|nullable|in:'.implode(',', DashboardPreferences::STYLES),
            'layout' => 'sometimes|nullable|array',
            'allow_user_switch' => 'sometimes|boolean',
        ]);
        DashboardPreferences::saveFor(null, $request->only(['style', 'layout', 'allow_user_switch']));

        return $this->show($request);
    }
}
