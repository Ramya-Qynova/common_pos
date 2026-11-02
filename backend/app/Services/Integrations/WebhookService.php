<?php

namespace App\Services\Integrations;

use App\Exceptions\ApiException;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebhookService
{
    public function register(int $tenantId, array $data): Webhook
    {
        return Webhook::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'uuid' => (string) Str::uuid(),
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $data['secret'] ?? Str::random(32),
            'events' => $data['events'] ?? ['sale.created'],
            'is_active' => $data['is_active'] ?? true,
            'headers' => $data['headers'] ?? null,
        ]);
    }

    public function update(int $tenantId, int $webhookId, array $data): Webhook
    {
        $webhook = Webhook::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $webhookId)
            ->firstOrFail();

        $webhook->update(array_filter([
            'name' => $data['name'] ?? null,
            'url' => $data['url'] ?? null,
            'secret' => $data['secret'] ?? null,
            'events' => $data['events'] ?? null,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : null,
            'headers' => array_key_exists('headers', $data) ? $data['headers'] : null,
        ], fn ($val) => $val !== null));

        return $webhook->fresh();
    }

    public function delete(int $tenantId, int $webhookId): bool
    {
        $webhook = Webhook::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $webhookId)
            ->first();

        if (! $webhook) {
            return false;
        }

        return (bool) $webhook->delete();
    }

    /**
     * Dispatches an event payload to all subscribed active webhooks for the tenant.
     * Signs payload with HMAC-SHA256 signature in X-POS-Signature.
     */
    public function dispatch(int $tenantId, string $event, array $payload): Collection
    {
        $webhooks = Webhook::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $deliveries = collect();

        foreach ($webhooks as $webhook) {
            $events = $webhook->events ?? [];
            if (! in_array($event, $events, true) && ! in_array('*', $events, true)) {
                continue;
            }

            $deliveries->push($this->sendToWebhook($webhook, $event, $payload));
        }

        return $deliveries;
    }

    public function testPing(int $tenantId, int $webhookId): WebhookDelivery
    {
        $webhook = Webhook::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $webhookId)
            ->firstOrFail();

        $testPayload = [
            'event' => 'ping',
            'timestamp' => now()->toIso8601String(),
            'tenant_id' => $tenantId,
            'message' => 'Antigravity POS webhook test ping',
        ];

        return $this->sendToWebhook($webhook, 'ping', $testPayload);
    }

    public function retry(int $tenantId, int $deliveryId): WebhookDelivery
    {
        $delivery = WebhookDelivery::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $deliveryId)
            ->firstOrFail();

        $webhook = Webhook::withoutTenantScope()->find($delivery->webhook_id);
        if (! $webhook) {
            throw new ApiException('WEBHOOK_NOT_FOUND', 'Associated webhook no longer exists.', 404);
        }

        $delivery->attempt++;

        return $this->executeDelivery($webhook, $delivery);
    }

    private function sendToWebhook(Webhook $webhook, string $event, array $payload): WebhookDelivery
    {
        $delivery = WebhookDelivery::withoutTenantScope()->create([
            'tenant_id' => $webhook->tenant_id,
            'uuid' => (string) Str::uuid(),
            'webhook_id' => $webhook->id,
            'event' => $event,
            'payload' => $payload,
            'status' => 'pending',
            'attempt' => 1,
            'sent_at' => now(),
        ]);

        return $this->executeDelivery($webhook, $delivery);
    }

    private function executeDelivery(Webhook $webhook, WebhookDelivery $delivery): WebhookDelivery
    {
        $jsonPayload = json_encode($delivery->payload);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$jsonPayload}", $webhook->secret);

        $headers = array_merge([
            'Content-Type' => 'application/json',
            'X-POS-Event' => $delivery->event,
            'X-POS-Delivery' => $delivery->uuid,
            'X-POS-Timestamp' => $timestamp,
            'X-POS-Signature' => $signature,
            'User-Agent' => 'Universal-POS-Webhook-Engine/1.0',
        ], $webhook->headers ?? []);

        try {
            $response = Http::timeout(5)
                ->withHeaders($headers)
                ->send('POST', $webhook->url, [
                    'body' => $jsonPayload,
                ]);

            $delivery->response_status = $response->status();
            $delivery->response_body = Str::limit($response->body(), 1000);
            $delivery->status = $response->successful() ? 'delivered' : 'failed';
            $delivery->error_message = $response->successful() ? null : "HTTP status {$response->status()}";
        } catch (\Throwable $e) {
            $delivery->response_status = 0;
            $delivery->status = 'failed';
            $delivery->error_message = Str::limit($e->getMessage(), 500);
        }

        $delivery->sent_at = now();
        $delivery->save();

        return $delivery;
    }
}
