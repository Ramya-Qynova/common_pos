<?php

use App\Models\DeviceSession;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Services\Auth\TokenPair;
use App\Services\Onboarding\OnboardingService;
use App\Services\Settings\SettingsService;

function onboardTestTenant(string $shopCode = 'SHOP-100'): Tenant
{
    return app(OnboardingService::class)->createTenant(
        shopCode: $shopCode,
        tenantName: 'Login Test Shop',
        planCode: 'standard',
        templateCode: 'general_retail',
        ownerUsername: 'owner',
        ownerPassword: 'correct-password',
        ownerEmail: 'owner@example.test',
    );
}

test('login with correct credentials returns tokens and logs a security event', function () {
    $tenant = onboardTestTenant();

    $response = $this->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code,
        'username' => 'owner',
        'password' => 'correct-password',
    ]);

    $response->assertOk();
    $response->assertJsonPath('success', true);
    expect($response->json('data.access_token'))->not->toBeEmpty();
    expect($response->json('data.refresh_token'))->not->toBeEmpty();

    expect(SecurityEvent::where('tenant_id', $tenant->id)->where('event_type', 'login_success')->exists())->toBeTrue();
});

test('login with wrong password is rejected with a generic error', function () {
    $tenant = onboardTestTenant();

    $response = $this->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code,
        'username' => 'owner',
        'password' => 'totally-wrong',
    ]);

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('UNAUTHENTICATED');
});

test('login with an unknown shop code gets the same generic error as a wrong password (no user enumeration)', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'shop_code' => 'NO-SUCH-SHOP',
        'username' => 'owner',
        'password' => 'whatever',
    ]);

    $response->assertStatus(401);
    expect($response->json('error.code'))->toBe('UNAUTHENTICATED');
});

test('logging in resolves the correct tenant even when another tenant has a user with the identical username and password', function () {
    $tenantA = onboardTestTenant('SHOP-A');
    $tenantB = onboardTestTenant('SHOP-B'); // same username "owner", same literal password as tenant A

    $response = $this->postJson('/api/v1/auth/login', [
        'shop_code' => $tenantA->shop_code,
        'username' => 'owner',
        'password' => 'correct-password',
    ]);

    $response->assertOk();
    expect($response->json('data.tenant.shop_code'))->toBe($tenantA->shop_code);

    $session = DeviceSession::withoutTenantScope()
        ->where('access_token_hash', TokenPair::hash($response->json('data.access_token')))
        ->first();
    expect($session->tenant_id)->toBe($tenantA->id)->not->toBe($tenantB->id);
});

test('account locks after the configured number of failed attempts, and the lockout threshold is itself a setting', function () {
    $tenant = onboardTestTenant();
    app(SettingsService::class)->set($tenant->id, 'auth.max_failed_logins', 2);

    $attempt = fn () => $this->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'wrong',
    ]);

    $attempt()->assertStatus(401); // failure 1
    $attempt()->assertStatus(401); // failure 2 -> crosses the threshold of 2, locks the account

    $locked = $attempt(); // this call is itself with a wrong password; the account should already be locked
    $locked->assertStatus(403);
    expect($locked->json('error.code'))->toBe('FORBIDDEN');

    // Even the CORRECT password is now rejected while locked.
    $withCorrectPassword = $this->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
    ]);
    $withCorrectPassword->assertStatus(403);

    expect(SecurityEvent::where('tenant_id', $tenant->id)->where('event_type', 'account_locked')->exists())->toBeTrue();
});

test('logout revokes the access token so it can no longer authenticate', function () {
    $tenant = onboardTestTenant();

    $login = $this->postJson('/api/v1/auth/login', [
        'shop_code' => $tenant->shop_code, 'username' => 'owner', 'password' => 'correct-password',
    ])->json('data');

    $this->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->getJson('/api/v1/auth/me')->assertOk();

    $this->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->postJson('/api/v1/auth/logout')->assertOk();

    $this->withHeader('Authorization', 'Bearer '.$login['access_token'])
        ->getJson('/api/v1/auth/me')->assertStatus(401);
});
