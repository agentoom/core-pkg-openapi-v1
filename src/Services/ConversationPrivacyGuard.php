<?php

namespace Agentoom\OpenApiV1\Services;

use Agentoom\Core\Contracts\PrivacyIntegration;
use Agentoom\Core\Models\Agent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies PII anonymization to OpenAI-compatible traffic.
 *
 * Mirrors the chat pipeline: AGPII tags are replaced first (always-on phase
 * one), then PII is swapped for deterministic placeholders when the agent has
 * anonymization enabled, and the placeholders are restored on the way back so
 * the client only ever sees clear text.
 *
 * The conversation id doubles as the mapping key, which is what keeps
 * pseudonyms identical across the turns of one conversation. Requests that
 * carry no conversation id (tracking disabled, or a client that could not be
 * identified) are forwarded untouched: without a stable key the placeholders
 * could not be restored, and the middleware that restores responses has no
 * conversation to key on either.
 */
class ConversationPrivacyGuard
{
    public function __construct(private PrivacyIntegration $privacy) {}

    /**
     * Prepare the prompt that is sent to the provider.
     */
    public function anonymizePrompt(string $prompt, Agent $agent, ?string $mappingKey): string
    {
        // Phase 1 (always-on): replace AGPII tags with deterministic pseudonyms
        // before the prompt reaches the privacy service or the LLM.
        $prompt = $this->privacy->redactAgpiiTags($prompt);

        if ($mappingKey === null || ! $this->privacy->shouldAnonymizeForAgent($agent)) {
            return $prompt;
        }

        try {
            return $this->privacy->anonymize($prompt, $mappingKey)['text'];
        } catch (Throwable) {
            // Never proceed silently: the privacy policy decides whether to stop
            // (throws) or continue unredacted (logged + notified).
            $this->privacy->handleUnavailable();

            return $prompt;
        }
    }

    /**
     * Restore the placeholders the provider may have echoed back.
     */
    public function restoreResponse(string $text, ?string $mappingKey): string
    {
        if ($mappingKey === null || $text === '' || ! $this->privacy->hasPlaceholders($text)) {
            return $text;
        }

        try {
            return $this->privacy->restore($text, $mappingKey);
        } catch (Throwable $e) {
            Log::warning('OpenApiV1: Failed to restore PII placeholders in the response', [
                'mapping_key' => $mappingKey,
                'error' => $e->getMessage(),
            ]);

            return $text;
        }
    }

    /**
     * Build the incremental restorer used to deanonymize a streamed response.
     */
    public function streamingRestorer(?string $mappingKey): ?StreamingRestorer
    {
        return $mappingKey === null ? null : new StreamingRestorer($this, $mappingKey);
    }
}
