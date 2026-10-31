<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\TenantAuthService;
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

        return ApiResponse::success([
            'access_token' => $result['tokens']->accessToken,
            'refresh_token' => $result['tokens']->refreshToken,
            'access_expires_at' => $result['tokens']->accessExpiresAt->toIso8601String(),
            'user' => [
                'id' => $result['user']->id,
                'username' => $result['user']->username,
                'must_change_password' => $result['user']->must_change_password,
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

    public function me(Request $request)
    {
        $user = $request->user('tenant');

        return ApiResponse::success([
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'roles' => $user->roles()->pluck('code'),
            'permissions' => $user->permissionCodes(),
        ]);
    }
}
