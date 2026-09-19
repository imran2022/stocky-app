<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\UserLoginSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Build I1 — Activity Log report (admin-facing, all users).
 *
 * Combines two sources into one chronological feed:
 *  - `activity_logs` (Sale/Purchase/Product/Customer create/update/delete —
 *    written by ActivityLogServiceProvider + the two explicit
 *    ClientController call sites)
 *  - `user_login_sessions`, filtered to ALL users (not the current one) —
 *    the existing table behind the self-service Login Activity Report,
 *    reused rather than duplicated, mapped to module 'Auth' / action
 *    'login'
 *
 * Deliberately NOT a UNION SQL query across the two tables: they have
 * different column shapes (activity_logs has old/new value JSON and a
 * subject; user_login_sessions has device/token info) and different
 * pagination needs are hard to reason about as one SQL statement. Instead
 * each source is queried, page-sliced in PHP, and merged — simple and
 * correct for the data volumes an activity log realistically has, at the
 * cost of not scaling to a huge combined row count as gracefully as a true
 * SQL union would. Acceptable for Phase 1; worth revisiting if it's ever
 * slow in practice.
 */
class ActivityLogController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'activity_log_report', Setting::class);

        $limit = (int) $request->get('limit', 25);
        $page = max(1, (int) $request->get('page', 1));
        $search = trim((string) $request->get('search', ''));
        $module = $request->get('module');
        $userId = $request->get('user_id');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        $logQuery = ActivityLog::query()->with('user:id,firstname,lastname');
        $loginQuery = UserLoginSession::query()->with('user:id,firstname,lastname');

        if ($userId) {
            $logQuery->where('user_id', $userId);
            $loginQuery->where('user_id', $userId);
        }

        if ($dateFrom) {
            $logQuery->whereDate('created_at', '>=', $dateFrom);
            $loginQuery->whereDate('logged_in_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $logQuery->whereDate('created_at', '<=', $dateTo);
            $loginQuery->whereDate('logged_in_at', '<=', $dateTo);
        }

        if ($search !== '') {
            $logQuery->where('description', 'like', "%{$search}%");
            // A login row has no free-text description; matching search
            // against it would need a join to users for the name, which
            // the $userId filter above already covers for the common case
            // of "find this person's activity" — search stays log-only.
            $loginQuery->whereRaw('0 = 1');
        }

        // Module filter: 'Auth' means "logins only", anything else (or
        // none) means "activity_logs only" for that module — a user
        // picking a specific business module isn't asking for logins mixed
        // in, and picking nothing means "everything", handled by unioning
        // both below rather than filtering either query further.
        $includeLogs = $module !== 'Auth';
        $includeLogins = ! $module || $module === 'Auth';

        if ($module && $module !== 'Auth') {
            $logQuery->where('module', $module);
        }

        $rows = collect();

        if ($includeLogs) {
            $rows = $rows->merge($logQuery->get()->map(fn ($r) => $this->mapLog($r)));
        }

        if ($includeLogins) {
            $rows = $rows->merge($loginQuery->get()->map(fn ($r) => $this->mapLogin($r)));
        }

        $rows = $rows->sortByDesc('date')->values();
        $total = $rows->count();

        if ($limit != -1) {
            $rows = $rows->slice(($page - 1) * $limit, $limit)->values();
        }

        return response()->json([
            'rows' => $rows,
            'totalRows' => $total,
            'users' => \App\Models\User::select('id', 'firstname', 'lastname')
                ->orderBy('firstname')
                ->get()
                ->map(fn ($u) => ['id' => $u->id, 'name' => trim("{$u->firstname} {$u->lastname}")]),
        ]);
    }

    /**
     * `users.name` doesn't exist in this app (firstname/lastname instead) —
     * every user-display spot in this controller goes through this helper
     * rather than assuming a `name` attribute.
     */
    private function userName($user, string $fallback): string
    {
        if (! $user) {
            return $fallback;
        }

        $full = trim("{$user->firstname} {$user->lastname}");

        return $full !== '' ? $full : $fallback;
    }

    private function mapLog(ActivityLog $log): array
    {
        return [
            'id' => 'log-'.$log->id,
            'date' => optional($log->created_at)->toDateTimeString(),
            'user' => $this->userName($log->user, 'System'),
            'module' => $log->module,
            'action' => $log->action,
            'description' => $log->description,
            'ip_address' => $log->ip_address,
            'device' => $this->parseDevice($log->user_agent),
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'status' => 'success',
        ];
    }

    private function mapLogin(UserLoginSession $session): array
    {
        return [
            'id' => 'login-'.$session->id,
            'date' => optional($session->logged_in_at)->toDateTimeString(),
            'user' => $this->userName($session->user, 'Unknown'),
            'module' => 'Auth',
            'action' => 'login',
            'description' => 'User logged in',
            'ip_address' => $session->ip_address,
            'device' => $this->parseDevice($session->user_agent),
            'old_values' => null,
            'new_values' => null,
            'status' => $session->revoked_at ? 'ended' : 'success',
        ];
    }

    /**
     * Same lightweight "Browser on OS" string the existing Login Activity
     * Report shows (see SecuritySettingsController) — kept independent
     * rather than extracted into a shared helper, to avoid touching that
     * existing, working controller for this delivery.
     */
    private function parseDevice(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown';
        }

        $browser = 'Unknown browser';
        if (str_contains($userAgent, 'Edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($userAgent, 'Chrome/') && ! str_contains($userAgent, 'Chromium')) {
            $browser = 'Chrome';
        } elseif (str_contains($userAgent, 'Firefox/')) {
            $browser = 'Firefox';
        } elseif (str_contains($userAgent, 'Safari/') && ! str_contains($userAgent, 'Chrome')) {
            $browser = 'Safari';
        }

        $os = 'Unknown OS';
        if (str_contains($userAgent, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($userAgent, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
            $os = 'iOS';
        } elseif (str_contains($userAgent, 'Mac OS')) {
            $os = 'macOS';
        } elseif (str_contains($userAgent, 'Linux')) {
            $os = 'Linux';
        }

        return "{$browser} on {$os}";
    }
}
