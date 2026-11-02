<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\TenantAuthService;
use App\Services\Auth\TwoFactorAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(private TenantAuthService $auth) {}

    public function login(Request $request)
    {
        $data = Validator::make($request->all(), [
            'shop_code' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'device_uuid' => 'nullable|string',
            'device_name' => 'nullable|string',
        ])->validate();

        $result = $this->auth->login(
            $data['shop_code'],
            $data['username'],
            $data['password'],
            $data['device_uuid'] ?? null,
            $data['device_name'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        if ($result['two_factor_required'] ?? false) {
            return ApiResponse::success([
                'two_factor_required' => true,
                'two_factor_token' => $result['two_factor_token'],
            ]);
        }

        return ApiResponse::success([
            'access_token' => $result['tokens']->accessToken,
            'refresh_token' => $result['tokens']->refreshToken,
            'access_expires_at' => $result['tokens']->accessExpiresAt->toIso8601String(),
            'user' => [
                'id' => $result['user']->id,
                'username' => $result['user']->username,
                'must_change_password' => $result['user']->must_change_password,
                'two_factor_enabled' => (bool) $result['user']->two_factor_enabled,
            ],
            'tenant' => [
                'shop_code' => $result['tenant']->shop_code,
                'name' => $result['tenant']->name,
                'status' => $result['tenant']->status,
            ],
        ]);
    }

    public function refresh(Request $request)
    {
        $data = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ])->validate();

        $result = $this->auth->refresh($data['refresh_token'], $request->ip(), $request->userAgent());

        return ApiResponse::success([
            'access_token' => $result['tokens']->accessToken,
            'refresh_token' => $result['tokens']->refreshToken,
            'access_expires_at' => $result['tokens']->accessExpiresAt->toIso8601String(),
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->bearerToken();
        if ($token) {
            $this->auth->logout($token);
        }

        return ApiResponse::success(['logged_out' => true]);
    }

    public function verifyTwoFactor(Request $request)
    {
        $data = Validator::make($request->all(), [
            'two_factor_token' => 'required|string',
            'code' => 'required|string',
        ])->validate();

        $result = $this->auth->completeTwoFactorLogin(
            $data['two_factor_token'],
            $data['code'],
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::success([
            'access_token' => $result['tokens']->accessToken,
            'refresh_token' => $result['tokens']->refreshToken,
            'access_expires_at' => $result['tokens']->accessExpiresAt->toIso8601String(),
            'user' => [
                'id' => $result['user']->id,
                'username' => $result['user']->username,
                'must_change_password' => $result['user']->must_change_password,
                'two_factor_enabled' => (bool) $result['user']->two_factor_enabled,
            ],
            'tenant' => [
                'shop_code' => $result['tenant']->shop_code,
                'name' => $result['tenant']->name,
                'status' => $result['tenant']->status,
            ],
        ]);
    }

    public function setupTwoFactor(Request $request, TwoFactorAuthService $twoFactor)
    {
        $user = $request->user('tenant');
        $tenant = $user->tenant;

        $setupData = $twoFactor->setup($user, $tenant->name);

        return ApiResponse::success($setupData);
    }

    public function confirmTwoFactor(Request $request, TwoFactorAuthService $twoFactor)
    {
        $data = Validator::make($request->all(), [
            'code' => 'required|string',
        ])->validate();

        $user = $request->user('tenant');
        $confirmed = $twoFactor->confirm($user, $data['code']);

        return ApiResponse::success($confirmed);
    }

    public function disableTwoFactor(Request $request, TwoFactorAuthService $twoFactor)
    {
        $data = Validator::make($request->all(), [
            'password' => 'required|string',
        ])->validate();

        $user = $request->user('tenant');
        $twoFactor->disable($user, $data['password']);

        return ApiResponse::success(['disabled' => true]);
    }

    public function me(Request $request)
    {
        $user = $request->user('tenant');

        return ApiResponse::success([
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'roles' => $user->roles()->pluck('code'),
            'permissions' => $user->permissionCodes(),
        ]);
    }
}
