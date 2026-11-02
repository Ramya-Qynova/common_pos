<?php

use App\Models\User;
use App\Services\Auth\TwoFactorAuthService;
use App\Services\Onboarding\OnboardingService;

beforeEach(function () {
    $this->tenant = app(OnboardingService::class)->createTenant(
        shopCode: 'SHOP-2FA-'.uniqid(),
        tenantName: '2FA Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );

    $this->owner = User::withoutTenantScope()->where('tenant_id', $this->tenant->id)->first();

    $login = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $this->tenant->shop_code,
        'username' => 'owner',
        'password' => 'correct-password',
    ])->json('data');

    $this->authHeader = ['Authorization' => 'Bearer '.$login['access_token']];
});

test('user can setup 2FA, receiving secret, qr URI and recovery codes', function () {
    $res = test()->postJson('/api/v1/auth/2fa/setup', [], $this->authHeader);

    $res->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => ['secret', 'qr_uri', 'recovery_codes'],
        ]);

    $data = $res->json('data');
    expect($data['secret'])->toHaveLength(16);
    expect($data['qr_uri'])->toContain('otpauth://totp/');
    expect($data['recovery_codes'])->toHaveCount(8);

    $this->owner->refresh();
    expect($this->owner->two_factor_enabled)->toBeFalse();
    expect($this->owner->two_factor_secret)->toBe($data['secret']);
});

test('confirming 2FA with a valid TOTP code enables two-factor authentication', function () {
    $setup = test()->postJson('/api/v1/auth/2fa/setup', [], $this->authHeader)->json('data');
    $secret = $setup['secret'];

    $code = app(TwoFactorAuthService::class)->calculateTotpCode($secret);

    $confirmRes = test()->postJson('/api/v1/auth/2fa/confirm', [
        'code' => $code,
    ], $this->authHeader);

    $confirmRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.enabled', true);

    $this->owner->refresh();
    expect($this->owner->two_factor_enabled)->toBeTrue();
    expect($this->owner->two_factor_confirmed_at)->not->toBeNull();
});

test('login requires 2FA challenge when two-factor is enabled', function () {
    // Setup and confirm 2FA
    $setup = test()->postJson('/api/v1/auth/2fa/setup', [], $this->authHeader)->json('data');
    $code = app(TwoFactorAuthService::class)->calculateTotpCode($setup['secret']);
    test()->postJson('/api/v1/auth/2fa/confirm', ['code' => $code], $this->authHeader);

    // Now try to log in
    $loginRes = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $this->tenant->shop_code,
        'username' => 'owner',
        'password' => 'correct-password',
    ]);

    $loginRes->assertStatus(200)
        ->assertJsonPath('data.two_factor_required', true)
        ->assertJsonStructure(['data' => ['two_factor_token']]);

    // Verify invalid TOTP code is rejected
    $verifyFail = test()->postJson('/api/v1/auth/2fa/verify', [
        'two_factor_token' => $loginRes->json('data.two_factor_token'),
        'code' => '000000',
    ]);
    $verifyFail->assertStatus(422);

    // Verify valid TOTP code completes login
    $validCode = app(TwoFactorAuthService::class)->calculateTotpCode($setup['secret']);
    $verifySuccess = test()->postJson('/api/v1/auth/2fa/verify', [
        'two_factor_token' => $loginRes->json('data.two_factor_token'),
        'code' => $validCode,
    ]);

    $verifySuccess->assertStatus(200)
        ->assertJsonStructure([
            'data' => ['access_token', 'refresh_token', 'user', 'tenant'],
        ]);
});

test('logging in using a single-use recovery code succeeds and consumes the code', function () {
    $setup = test()->postJson('/api/v1/auth/2fa/setup', [], $this->authHeader)->json('data');
    $secret = $setup['secret'];
    $recoveryCodes = $setup['recovery_codes'];

    $code = app(TwoFactorAuthService::class)->calculateTotpCode($secret);
    test()->postJson('/api/v1/auth/2fa/confirm', ['code' => $code], $this->authHeader);

    // Initiate login
    $loginRes = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $this->tenant->shop_code,
        'username' => 'owner',
        'password' => 'correct-password',
    ]);

    $token = $loginRes->json('data.two_factor_token');
    $chosenRecoveryCode = $recoveryCodes[0];

    // Use recovery code
    $verifyRes = test()->postJson('/api/v1/auth/2fa/verify', [
        'two_factor_token' => $token,
        'code' => $chosenRecoveryCode,
    ]);

    $verifyRes->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->owner->refresh();
    expect($this->owner->two_factor_recovery_codes)->toHaveCount(7);

    // Reusing the same recovery code on a new login attempt fails
    $loginRes2 = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $this->tenant->shop_code,
        'username' => 'owner',
        'password' => 'correct-password',
    ]);

    $verifyFail = test()->postJson('/api/v1/auth/2fa/verify', [
        'two_factor_token' => $loginRes2->json('data.two_factor_token'),
        'code' => $chosenRecoveryCode,
    ]);
    $verifyFail->assertStatus(422);
});

test('user can disable 2FA with current password', function () {
    $setup = test()->postJson('/api/v1/auth/2fa/setup', [], $this->authHeader)->json('data');
    $code = app(TwoFactorAuthService::class)->calculateTotpCode($setup['secret']);
    test()->postJson('/api/v1/auth/2fa/confirm', ['code' => $code], $this->authHeader);

    // Disable with wrong password fails
    $failRes = test()->postJson('/api/v1/auth/2fa/disable', [
        'password' => 'wrong-password',
    ], $this->authHeader);
    $failRes->assertStatus(422);

    // Disable with correct password succeeds
    $disableRes = test()->postJson('/api/v1/auth/2fa/disable', [
        'password' => 'correct-password',
    ], $this->authHeader);
    $disableRes->assertStatus(200)->assertJsonPath('data.disabled', true);

    $this->owner->refresh();
    expect($this->owner->two_factor_enabled)->toBeFalse();
    expect($this->owner->two_factor_secret)->toBeNull();

    // Subsequent login no longer requires 2FA
    $loginRes = test()->postJson('/api/v1/auth/login', [
        'shop_code' => $this->tenant->shop_code,
        'username' => 'owner',
        'password' => 'correct-password',
    ]);

    $loginRes->assertStatus(200)
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
});
