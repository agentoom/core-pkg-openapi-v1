<?php

namespace Agentoom\OpenApiV1\DTOs;

readonly class AgentExecutionInput
{
    /**
     * @param  string|null  $systemInstructions  Supplementary system instructions from API messages
     * @param  string|null  $conversationHistory  Formatted conversation history (prior user/assistant turns)
     * @param  string  $currentUserMessage  The last user message — the main prompt
     */
    public function __construct(
        public ?string $systemInstructions,
        public ?string $conversationHistory,
        public string $currentUserMessage,
    ) {}

    /**
     * Build the full prompt string from the execution input.
     *
     * The prompt is structured as:
     *   [Conversation History]
     *   [Current User Message]
     *
     * System instructions are passed separately as extraInstructions to DynamicAgent.
     */
    public function toPrompt(): string
    {
        $parts = [];

        if ($this->conversationHistory !== null && $this->conversationHistory !== '') {
            $parts[] = $this->conversationHistory;
        }

        $parts[] = $this->currentUserMessage;

        return implode("\n\n", $parts);
    }
}
