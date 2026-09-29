<?php

namespace Agentoom\OpenApiV1\Services;

use Agentoom\Core\Ai\Agents\DynamicAgent;
use Agentoom\Core\Storage\AgentAwareConversationStore;
use Agentoom\OpenApiV1\DTOs\AgentExecutionInput;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Persists OpenAI-compatible API traffic against the anchored conversation.
 *
 * Headless executions do not run the Laravel AI conversation middleware, so this
 * channel records both turns explicitly once the provider response is available.
 * Without it the anchor would exist but stay empty, and the compliance tracer
 * (which looks messages up by conversation) would have nothing to attach to.
 */
class ExternalConversationRecorder
{
    public function __construct(private AgentAwareConversationStore $store) {}

    /**
     * Record the user turn and the assistant reply for the given execution.
     */
    public function record(DynamicAgent $agent, AgentExecutionInput $input, AgentResponse $response): void
    {
        $conversationId = $agent->currentConversation();

        if ($conversationId === null) {
            return;
        }

        $userId = auth()->user()?->id;

        $this->store->ensureUserMessageStored(
            conversationId: $conversationId,
            userId: $userId,
            agent: $agent::class,
            content: $input->currentUserMessage,
        );

        if (filled($response->text) || $response->toolCalls->isNotEmpty()) {
            $this->store->storeCompletedAssistantMessage(
                conversationId: $conversationId,
                userId: $userId,
                agent: $agent::class,
                content: (string) $response->text,
                response: $response,
            );
        }
    }
}
