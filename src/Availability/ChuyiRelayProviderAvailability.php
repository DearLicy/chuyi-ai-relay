<?php
/**
 * Provider availability for 初一 AI 中转.
 *
 * @package WordPress\ChuyiAiRelay\Availability
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Availability;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\ChuyiAiRelay\Settings;

/**
 * Verifies relay availability through the authenticated /models endpoint.
 */
final class ChuyiRelayProviderAvailability implements ProviderAvailabilityInterface, WithRequestAuthenticationInterface
{
    /**
     * @var string Relay slot ID.
     */
    private string $slotId;

    /**
     * @var RequestAuthenticationInterface|null Authentication injected by the AI Client registry.
     */
    private ?RequestAuthenticationInterface $requestAuthentication = null;

    /**
     * Constructor.
     */
    public function __construct(string $slotId = Settings::DEFAULT_SLOT_ID)
    {
        $this->slotId = $slotId;
    }

    /**
     * {@inheritDoc}
     */
    public function setRequestAuthentication(RequestAuthenticationInterface $authentication): void
    {
        $this->requestAuthentication = $authentication;
    }

    /**
     * {@inheritDoc}
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        if ($this->requestAuthentication === null) {
            throw new \RuntimeException('初一 AI 中转认证信息尚未设置。');
        }

        return $this->requestAuthentication;
    }

    /**
     * {@inheritDoc}
     */
    public function isConfigured(): bool
    {
        $baseUrl = Settings::getBaseUrl($this->slotId);
        $apiKey = $this->getConfiguredApiKey();

        return $baseUrl !== '' && $apiKey !== '';
    }

    /**
     * Returns the API key currently being validated or the configured fallback key.
     */
    private function getConfiguredApiKey(): string
    {
        if ($this->requestAuthentication instanceof ApiKeyRequestAuthentication) {
            return trim($this->requestAuthentication->getApiKey());
        }

        return trim(Settings::getApiKey($this->slotId));
    }

    /**
     * Builds protocol-specific authentication headers.
     *
     * @return array<string,string>
     */
    private function getAuthHeaders(string $apiKey): array
    {
        $headers = array('Accept' => 'application/json');

        if (Settings::getMode($this->slotId) === Settings::MODE_ANTHROPIC) {
            $headers['x-api-key'] = $apiKey;
            $headers['anthropic-version'] = '2023-06-01';
            return $headers;
        }

        $headers['Authorization'] = 'Bearer ' . $apiKey;
        return $headers;
    }
}