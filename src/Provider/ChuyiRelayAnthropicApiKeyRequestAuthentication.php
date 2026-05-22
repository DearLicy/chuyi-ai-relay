<?php
/**
 * Anthropic-style API key authentication for relay slots.
 *
 * @package WordPress\ChuyiAiRelay\Provider
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Provider;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

/**
 * Adds the Anthropic headers while still using the Connectors API key field.
 */
final class ChuyiRelayAnthropicApiKeyRequestAuthentication extends ApiKeyRequestAuthentication
{
    private const ANTHROPIC_API_VERSION = '2023-06-01';

    /**
     * {@inheritDoc}
     */
    public function authenticateRequest(Request $request): Request
    {
        $request = $request->withHeader('anthropic-version', self::ANTHROPIC_API_VERSION);

        return $request->withHeader('x-api-key', $this->apiKey);
    }
}