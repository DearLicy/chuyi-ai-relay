<?php
/**
 * Provider availability for 初一中转.
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
 * Treats a non-empty API key as configured without probing relay endpoints.
 */
final class ChuyiRelayProviderAvailability implements ProviderAvailabilityInterface, WithRequestAuthenticationInterface
{
    /**
     * @var RequestAuthenticationInterface|null Authentication injected by the AI Client registry.
     */
    private ?RequestAuthenticationInterface $requestAuthentication = null;

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
            throw new \RuntimeException('初一中转认证信息尚未设置。');
        }

        return $this->requestAuthentication;
    }

    /**
     * {@inheritDoc}
     */
    public function isConfigured(): bool
    {
        if ($this->requestAuthentication instanceof ApiKeyRequestAuthentication) {
            return trim($this->requestAuthentication->getApiKey()) !== '';
        }

        return Settings::getApiKey() !== '';
    }
}