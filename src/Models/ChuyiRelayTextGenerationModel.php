<?php
/**
 * 初一中转 text generation model.
 *
 * @package WordPress\ChuyiAiRelay\Models
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Models;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\ChuyiAiRelay\Provider\ChuyiRelayProvider;

/**
 * Text generation through an OpenAI-compatible chat completions endpoint.
 */
final class ChuyiRelayTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /**
     * {@inheritDoc}
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request
    {
        return new Request(
            $method,
            ChuyiRelayProvider::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }
}