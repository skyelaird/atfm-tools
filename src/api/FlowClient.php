<?php

declare(strict_types=1);

namespace Atfm\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin HTTP client for the atfm-flow Laravel backend.
 *
 * This lives in atfm-tools (not inside the flow fork) so the toolset can
 * talk to flow over its public API instead of linking GPL'd PHP classes
 * directly. Keeps license boundaries clean.
 */
final class FlowClient
{
    private Client $http;

    public function __construct(string $baseUrl, ?string $token = null)
    {
        $headers = ['Accept' => 'application/json'];
        if ($token !== null && $token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $this->http = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'headers'  => $headers,
            'timeout'  => 10.0,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws GuzzleException
     */
    public function activeFlowMeasures(): array
    {
        $res = $this->http->get('flow-measures', [
            'query' => ['state' => 'active'],
        ]);

        $decoded = json_decode((string) $res->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
