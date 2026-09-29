<?php

namespace Agentoom\OpenApiV1\Services;

use Agentoom\Core\Models\Agent;
use Agentoom\OpenApiV1\Exceptions\OpenApiException;
use App\Models\User;

class AgentResolver
{
    /**
     * Resolve a model slug to an Agent, verifying it is active, not a system agent,
     * and accessible by the given User.
     *
     * @param  string  $slug  The agent slug (OpenAI model ID)
     * @param  User  $user  The authenticated user
     *
     * @throws OpenApiException If the agent is not found, inactive, is a system agent, or not accessible.
     */
    public function resolve(string $slug, User $user): Agent
    {
        /** @var Agent|null $agent */
        $agent = Agent::where('slug', $slug)->first();

        if ($agent === null) {
            throw OpenApiException::modelNotFound($slug);
        }

        if (! $agent->is_active) {
            throw OpenApiException::modelNotFound($slug);
        }

        if ($agent->system_agent) {
            throw OpenApiException::modelNotFound($slug);
        }

        if (! $agent->isAccessibleBy($user)) {
            throw OpenApiException::modelNotFound($slug);
        }

        return $agent;
    }
}
