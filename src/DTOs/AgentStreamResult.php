<?php

namespace Agentoom\OpenApiV1\DTOs;

use Agentoom\OpenApiV1\Services\StreamingRestorer;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * Outcome of a streaming execution.
 *
 * The restorer travels with the stream because SSE deltas are forwarded to the
 * client as they arrive: placeholders have to be swapped back for the original
 * values on the way out, not after the fact.
 */
readonly class AgentStreamResult
{
    public function __construct(
        public StreamableAgentResponse $stream,
        public ?StreamingRestorer $restorer = null,
    ) {}
}
