<?php

namespace Agentoom\OpenApiV1\Services;

/**
 * Deanonymizes a streamed response piece by piece.
 *
 * SSE deltas can split a placeholder across chunks (`<REDACTED-PER` + `SON-AB12>`)
 * and an incomplete placeholder can neither be restored nor shown to the client,
 * so the tail of the buffer is withheld until it is known to be complete text.
 */
class StreamingRestorer
{
    /**
     * Marker every placeholder produced by the privacy service starts with.
     */
    private const PLACEHOLDER_MARKER = '<REDACTED-';

    private string $buffer = '';

    public function __construct(
        private ConversationPrivacyGuard $guard,
        private string $mappingKey,
    ) {}

    /**
     * Buffer a delta and return the text that is safe to forward.
     */
    public function restoreDelta(string $delta): string
    {
        $this->buffer .= $delta;

        $ready = $this->flushablePrefix();

        if ($ready === '') {
            return '';
        }

        $this->buffer = substr($this->buffer, strlen($ready));

        return $this->guard->restoreResponse($ready, $this->mappingKey);
    }

    /**
     * Return whatever is still buffered once the stream has ended.
     */
    public function finish(): string
    {
        if ($this->buffer === '') {
            return '';
        }

        $remaining = $this->buffer;
        $this->buffer = '';

        return $this->guard->restoreResponse($remaining, $this->mappingKey);
    }

    /**
     * Get the prefix of the buffer that cannot contain a partial placeholder.
     */
    private function flushablePrefix(): string
    {
        $lastOpen = strrpos($this->buffer, '<');

        if ($lastOpen === false) {
            return $this->buffer;
        }

        // A closing bracket after the last '<' means nothing is half-written.
        if (strpos($this->buffer, '>', $lastOpen) !== false) {
            return $this->buffer;
        }

        $tail = substr($this->buffer, $lastOpen);

        // Only hold back when the tail could still grow into a placeholder;
        // unterminated markup such as '<div' is forwarded as-is.
        if (! $this->couldStartPlaceholder($tail)) {
            return $this->buffer;
        }

        return substr($this->buffer, 0, $lastOpen);
    }

    /**
     * Determine whether the tail is still a prefix of the placeholder marker.
     */
    private function couldStartPlaceholder(string $tail): bool
    {
        $length = min(strlen($tail), strlen(self::PLACEHOLDER_MARKER));

        return strcasecmp(
            substr($tail, 0, $length),
            substr(self::PLACEHOLDER_MARKER, 0, $length),
        ) === 0;
    }
}
