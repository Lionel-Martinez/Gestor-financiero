<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Cierre remoto de sesión: un dispositivo revocado deja de poder usar su token (el token se llama como el dispositivo). */
final class EnsureDeviceActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->user()?->currentAccessToken()?->name;
        $device = $deviceId ? DB::table('devices')->where('id', $deviceId)->first() : null;

        if ($device === null || $device->revoked_at !== null) {
            return response()->json(['message' => 'Sesión revocada.', 'code' => 'device_revoked'], 401);
        }

        DB::table('devices')->where('id', $deviceId)->update(['last_seen_at' => now()->utc()->format('Y-m-d H:i:s.v')]);
        $request->attributes->set('device_id', $deviceId);

        return $next($request);
    }
}
