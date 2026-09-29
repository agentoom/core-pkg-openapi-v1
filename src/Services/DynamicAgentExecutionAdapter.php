<?php

namespace Agentoom\OpenApiV1\Services;

use Agentoom\Core\Ai\Agents\DynamicAgent;
use Agentoom\Core\Models\Agent;
use Agentoom\OpenApiV1\Contracts\AgentExecutionAdapter;
use Agentoom\OpenApiV1\DTOs\AgentExecutionInput;
use Agentoom\OpenApiV1\DTOs\AgentStreamResult;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;

class DynamicAgentExecutionAdapter implements AgentExecutionAdapter
{
    public function __construct(
        private ExternalConversationRecorder $recorder,
        private ConversationPrivacyGuard $privacy,
    ) {}

    /**
     * Execute a non-streaming prompt against the given agent.
     */
    public function prompt(Agent $agent, AgentExecutionInput $input, ?string $conversationAnchor = null): AgentResponse
    {
        $dynamicAgent = $this->createDynamicAgent($agent, $input, $conversationAnchor);
        $mappingKey = $dynamicAgent->currentConversation();

        $response = $dynamicAgent->prompt(
            $this->privacy->anonymizePrompt($input->toPrompt(), $agent, $mappingKey)
        );

        $response->text = $this->privacy->restoreResponse((string) $response->text, $mappingKey);

        $this->recorder->record($dynamicAgent, $input, $response);

        return $response;
    }

    /**
     * Execute a streaming prompt against the given agent.
     */
    public function stream(Agent $agent, AgentExecutionInput $input, ?string $conversationAnchor = null): AgentStreamResult
    {
        $dynamicAgent = $this->createDynamicAgent($agent, $input, $conversationAnchor);
        $mappingKey = $dynamicAgent->currentConversation();

        $stream = $dynamicAgent->stream(
            $this->privacy->anonymizePrompt($input->toPrompt(), $agent, $mappingKey)
        );

        // The streamed response only carries the full text once every event has been consumed.
        if ($mappingKey !== null) {
            $stream->then(fn (StreamedAgentResponse $response) => $this->recorder->record($dynamicAgent, $input, $response));
        }

        return new AgentStreamResult($stream, $this->privacy->streamingRestorer($mappingKey));
    }

    /**
     * Create a DynamicAgent instance configured for headless API execution.
     *
     * Headless mode suppresses Reverb broadcasting and timestamp prefixing; the
     * conversation middleware stays detached, so persistence is handled by the
     * recorder. Stored history is never replayed into the prompt because the
     * client resends the full transcript on every request.
     *
     * When the client identified its conversation, the agent is bound to that
     * thread (creating it on first use) so messages, traces and the PII mapping
     * all stay keyed on the same conversation.
     */
    private function createDynamicAgent(Agent $agent, AgentExecutionInput $input, ?string $conversationAnchor): DynamicAgent
    {
        $user = auth()->user();

        $dynamicAgent = new DynamicAgent(
            agentModel: $agent,
            isHeadless: true,
            silent: true,
            user: $user,
            extraInstructions: $input->systemInstructions,
            replayConversationHistory: false,
        );

        if ($conversationAnchor !== null) {
            $dynamicAgent->withThread(
                threadId: $conversationAnchor,
                allowMemoryAnalysis: (bool) $agent->allow_memory_analysis,
                userId: $user?->id,
                title: Str::limit($input->currentUserMessage, 60),
            );
        }

        return $dynamicAgent;
    }
}
