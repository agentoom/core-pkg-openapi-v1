<?php

namespace Agentoom\OpenApiV1\DTOs;

use Agentoom\OpenApiV1\Exceptions\OpenApiException;

readonly class ChatCompletionRequest
{
    /**
     * Supported message roles.
     */
    private const SUPPORTED_ROLES = ['system', 'user', 'assistant'];

    /**
     * @param  Message[]  $messages
     */
    public function __construct(
        public string $model,
        public array $messages,
        public bool $stream = false,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?float $topP = null,
        public ?float $frequencyPenalty = null,
        public ?float $presencePenalty = null,
    ) {}

    /**
     * Create a ChatCompletionRequest from a raw JSON request body array.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws OpenApiException
     */
    public static function fromArray(array $data): self
    {
        if (! isset($data['model']) || ! is_string($data['model']) || $data['model'] === '') {
            throw OpenApiException::missingModel();
        }

        if (! isset($data['messages']) || ! is_array($data['messages'])) {
            throw OpenApiException::missingMessages();
        }

        $messages = [];
        foreach ($data['messages'] as $index => $msg) {
            if (! isset($msg['role']) || ! is_string($msg['role'])) {
                throw OpenApiException::invalidMessageRole('(missing)');
            }
            if (! in_array($msg['role'], self::SUPPORTED_ROLES, true)) {
                throw OpenApiException::invalidMessageRole($msg['role']);
            }
            if (! isset($msg['content']) || ! is_string($msg['content'])) {
                $content = $msg['content'] ?? '';
                if (is_string($content) && $content === '') {
                    throw OpenApiException::emptyMessageContent();
                }
                // Handle array content (multimodal) — convert to string
                if (is_array($content)) {
                    $content = self::flattenMultimodalContent($content);
                } else {
                    throw OpenApiException::emptyMessageContent();
                }
            } else {
                $content = $msg['content'];
            }
            if ($content === '') {
                throw OpenApiException::emptyMessageContent();
            }
            $messages[] = new Message(role: $msg['role'], content: $content);
        }

        return new self(
            model: $data['model'],
            messages: $messages,
            stream: (bool) ($data['stream'] ?? false),
            temperature: isset($data['temperature']) ? (float) $data['temperature'] : null,
            maxTokens: isset($data['max_tokens']) ? (int) $data['max_tokens'] : null,
            topP: isset($data['top_p']) ? (float) $data['top_p'] : null,
            frequencyPenalty: isset($data['frequency_penalty']) ? (float) $data['frequency_penalty'] : null,
            presencePenalty: isset($data['presence_penalty']) ? (float) $data['presence_penalty'] : null,
        );
    }

    /**
     * Flatten multimodal content array to a string.
     *
     * @param  array<int, array{type: string, text?: string}>  $content
     */
    private static function flattenMultimodalContent(array $content): string
    {
        $texts = [];
        foreach ($content as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $texts[] = $part['text'];
            }
        }

        return implode('', $texts);
    }
}
