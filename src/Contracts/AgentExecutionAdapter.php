<?php

namespace Agentoom\OpenApiV1\Contracts;

use Agentoom\Core\Models\Agent;
use Agentoom\OpenApiV1\DTOs\AgentExecutionInput;
use Agentoom\OpenApiV1\DTOs\AgentStreamResult;
use Laravel\Ai\Responses\AgentResponse;

interface AgentExecutionAdapter
{
    /**
     * Execute a non-streaming prompt against the given agent.
     *
     * @param  Agent  $agent  The agent model to execute against
     * @param  AgentExecutionInput  $input  The converted execution input
     * @param  string|null  $conversationAnchor  session_id of the conversation this execution belongs to, when the client identified it
     * @return AgentResponse The completed agent response
     */
    public function prompt(Agent $agent, AgentExecutionInput $input, ?string $conversationAnchor = null): AgentResponse;

    /**
     * Execute a streaming prompt against the given agent.
     *
     * @param  Agent  $agent  The agent model to execute against
     * @param  AgentExecutionInput  $input  The converted execution input
     * @param  string|null  $conversationAnchor  session_id of the conversation this execution belongs to, when the client identified it
     * @return AgentStreamResult The streamable agent response and its deanonymizer, when PII anonymization applies
     */
    public function stream(Agent $agent, AgentExecutionInput $input, ?string $conversationAnchor = null): AgentStreamResult;
}
