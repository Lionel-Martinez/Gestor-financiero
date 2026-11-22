<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Security\Totp;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class AuthController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function login(Request $r): JsonResponse
    {
        $data = $r->validate([
            'email' => 'required|email', 'password' => 'required|string',
            'device_id' => 'required|uuid', 'device_name' => 'required|string|max:100', 'platform' => 'required|in:android,ios,other',
            'totp_code' => 'nullable|string', 'recovery_code' => 'nullable|string',
        ]);

        $user = User::where('email', $data['email'])->first();
        if ($user === null || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas.'], 422);
        }

        if ($user->hasTwoFactor()) {
            $ok = false;
            if (!empty($data['totp_code'])) {
                $ok = Totp::verify($user->two_factor_secret, $data['totp_code']);
            } elseif (!empty($data['recovery_code'])) {
                $ok = $this->consumeRecoveryCode($user, $data['recovery_code']);
            }
            if (!$ok) {
                return response()->json(['message' => 'Se requiere un código de verificación válido.', 'two_factor_required' => true], 422);
            }
        }

        $now = now()->utc()->format('Y-m-d H:i:s.v');
        DB::table('devices')->updateOrInsert(['id' => $data['device_id']], [
            'user_id' => $user->id, 'name' => $data['device_name'], 'platform' => $data['platform'],
            'last_seen_at' => $now, 'revoked_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // El nombre del token es el id del dispositivo: así se puede revocar por dispositivo.
        $user->tokens()->where('name', $data['device_id'])->delete();
        $token = $user->createToken($data['device_id'], ['*'], now()->addDays(90))->plainTextToken;

        $this->audit->log('session', $data['device_id'], 'login', null, $user->id, $data['device_id']);

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'reference_currency' => $user->reference_currency, 'reference_rate_type' => $user->reference_rate_type,
                'two_factor' => $user->hasTwoFactor()],
        ]);
    }

    public function logout(Request $r): JsonResponse
    {
        $deviceId = $r->user()->currentAccessToken()->name;
        $r->user()->currentAccessToken()->delete();
        DB::table('devices')->where('id', $deviceId)->update(['revoked_at' => now()->utc()->format('Y-m-d H:i:s.v')]);

        return response()->json(['ok' => true]);
    }

    public function devices(Request $r): JsonResponse
    {
        return response()->json(DB::table('devices')->where('user_id', $r->user()->id)->orderByDesc('last_seen_at')->get());
    }

    public function revokeDevice(Request $r, string $id): JsonResponse
    {
        $device = DB::table('devices')->where('id', $id)->where('user_id', $r->user()->id)->first();
        abort_if($device === null, 404);

        $r->user()->tokens()->where('name', $id)->delete();
        DB::table('devices')->where('id', $id)->update(['revoked_at' => now()->utc()->format('Y-m-d H:i:s.v')]);
        $this->audit->log('session', $id, 'remote_revoke', null, $r->user()->id, $r->attributes->get('device_id'));

        return response()->json(['ok' => true]);
    }

    public function twoFactorSetup(Request $r): JsonResponse
    {
        $user = $r->user();
        abort_if($user->hasTwoFactor(), 409, 'El 2FA ya está activo.');

        $secret = Totp::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return response()->json(['secret' => $secret, 'otpauth_uri' => Totp::provisioningUri($secret, $user->email)]);
    }

    public function twoFactorConfirm(Request $r): JsonResponse
    {
        $data = $r->validate(['code' => 'required|string']);
        $user = $r->user();
        abort_if($user->two_factor_secret === null || $user->hasTwoFactor(), 409);

        if (!Totp::verify($user->two_factor_secret, $data['code'])) {
            return response()->json(['message' => 'Código inválido.'], 422);
        }

        $codes = Totp::generateRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map([Totp::class, 'hashRecoveryCode'], $codes),
        ])->save();
        $this->audit->log('user', $user->id, '2fa_enabled', null, $user->id, $r->attributes->get('device_id'));

        // Los códigos se muestran una sola vez; en base de datos solo queda el hash.
        return response()->json(['recovery_codes' => $codes]);
    }

    public function twoFactorDisable(Request $r): JsonResponse
    {
        $data = $r->validate(['password' => 'required|string', 'code' => 'required|string']);
        $user = $r->user();

        if (!Hash::check($data['password'], $user->password) || !Totp::verify((string) $user->two_factor_secret, $data['code'])) {
            return response()->json(['message' => 'Contraseña o código inválidos.'], 422);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->log('user', $user->id, '2fa_disabled', null, $user->id, $r->attributes->get('device_id'));

        return response()->json(['ok' => true]);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hash = Totp::hashRecoveryCode($code);
        $codes = $user->two_factor_recovery_codes ?? [];
        $i = array_search($hash, $codes, true);
        if ($i === false) {
            return false;
        }
        unset($codes[$i]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();   // un solo uso

        return true;
    }
}
