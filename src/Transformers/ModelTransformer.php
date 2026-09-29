<?php

namespace Agentoom\OpenApiV1\Transformers;

use Agentoom\Core\Models\Agent;

class ModelTransformer
{
    /**
     * Transform an Agent into an OpenAI-compatible model object.
     *
     * @return array{id: string, object: string, created: int, owned_by: string}
     */
    public function transform(Agent $agent): array
    {
        return [
            'id' => $agent->slug,
            'object' => 'model',
            'created' => $agent->created_at?->timestamp ?? 0,
            'owned_by' => 'agentoom',
        ];
    }
}
