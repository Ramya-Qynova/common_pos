<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * section 10 "Platform" endpoint group names a health check; section 12
 * Phase 8 "monitoring + alerting". Public (no tenant/auth context exists
 * before login) - an uptime monitor or load balancer probe needs this to
 * work with no credentials. Checks the two dependencies this app cannot
 * run without: the database, and Redis when it's actually configured as
 * the cache/session/queue driver (section 2 "Redis in prod" - a local
 * dev box using the database/file drivers has nothing to check).
 */
class HealthController extends Controller
{
    public function show(Request $request)
    {
        $checks = ['database' => $this->checkDatabase()];

        if ($this->redisInUse()) {
            $checks['redis'] = $this->checkRedis();
        }

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function redisInUse(): bool
    {
        return in_array(config('cache.default'), ['redis'], true)
            || in_array(config('session.driver'), ['redis'], true)
            || in_array(config('queue.default'), ['redis'], true);
    }

    private function checkRedis(): bool
    {
        try {
            app('redis')->connection()->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
