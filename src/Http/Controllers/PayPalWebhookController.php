<?php

declare(strict_types=1);

namespace Filagora\Memberships\Http\Controllers;

use Filagora\Memberships\Providers\PayPal\PayPalWebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Receives PayPal's webhook deliveries. The body is read raw: PayPal signs the bytes it sent, so
 * the handler needs them as well as the decoded payload. An answer of 400 makes PayPal send the
 * delivery again.
 */
final class PayPalWebhookController
{
    private const HEADERS = [
        'PayPal-Auth-Algo',
        'PayPal-Cert-Url',
        'PayPal-Transmission-Id',
        'PayPal-Transmission-Sig',
        'PayPal-Transmission-Time',
    ];

    public function __invoke(Request $request, PayPalWebhookHandler $handler): Response
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return $this->refused();
        }

        try {
            $acknowledged = $handler->handle($this->headers($request), $payload, $rawBody);
        } catch (Throwable $exception) {
            report($exception);

            return $this->refused();
        }

        return $acknowledged ? response('', 200) : $this->refused();
    }

    private function refused(): Response
    {
        return response(__('filagora-memberships::memberships.webhook_invalid'), 400);
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach (self::HEADERS as $name) {
            $headers[strtolower($name)] = trim((string) $request->header($name, ''));
        }

        return $headers;
    }
}
