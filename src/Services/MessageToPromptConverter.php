<?php

namespace Agentoom\OpenApiV1\Services;

use Agentoom\OpenApiV1\DTOs\AgentExecutionInput;
use Agentoom\OpenApiV1\DTOs\Message;
use Agentoom\OpenApiV1\Exceptions\OpenApiException;

class MessageToPromptConverter
{
    /**
     * Convert an array of OpenAI Messages into an AgentExecutionInput.
     *
     * Conversion rules:
     * - System messages are extracted and concatenated as supplementary instructions.
     * - User and assistant messages are split into conversation history
     *   (all turns except the last user message) and the current user message.
     * - The last message MUST be from the user.
     * - Unsupported roles throw a validation error.
     * - Empty message content throws a validation error.
     *
     * @param  Message[]  $messages
     *
     * @throws OpenApiException
     */
    public function convert(array $messages): AgentExecutionInput
    {
        if (empty($messages)) {
            throw OpenApiException::missingMessages();
        }

        // Extract system messages
        $systemMessages = array_filter(
            $messages,
            fn (Message $msg) => $msg->isRole('system')
        );
        $systemInstructions = ! empty($systemMessages)
            ? implode("\n\n", array_map(fn (Message $msg) => $msg->content, $systemMessages))
            : null;

        // Get non-system messages
        $conversationMessages = array_values(array_filter(
            $messages,
            fn (Message $msg) => ! $msg->isRole('system')
        ));

        // Validate: at least one user/assistant message exists
        if (empty($conversationMessages)) {
            throw OpenApiException::invalidMessageOrder();
        }

        // The last non-system message must be from the user
        $lastMessage = end($conversationMessages);
        if (! $lastMessage->isRole('user')) {
            throw OpenApiException::invalidMessageOrder();
        }

        // The last user message is the current prompt
        $currentUserMessage = $lastMessage->content;

        // Everything before the last user message is conversation history
        $historyMessages = array_slice($conversationMessages, 0, -1);
        $conversationHistory = ! empty($historyMessages)
            ? $this->formatHistory($historyMessages)
            : null;

        return new AgentExecutionInput(
            systemInstructions: $systemInstructions,
            conversationHistory: $conversationHistory,
            currentUserMessage: $currentUserMessage,
        );
    }

    /**
     * Format conversation history messages into a structured string.
     *
     * Format:
     *   [User]
     *   message content
     *
     *   [Assistant]
     *   message content
     *
     * @param  Message[]  $messages
     */
    private function formatHistory(array $messages): string
    {
        $lines = [];

        foreach ($messages as $message) {
            $label = match ($message->role) {
                'user' => '[User]',
                'assistant' => '[Assistant]',
                default => '[Unknown]',
            };

            $lines[] = $label;
            $lines[] = $message->content;
            $lines[] = ''; // Empty line separator
        }

        return implode("\n", $lines);
    }
}
