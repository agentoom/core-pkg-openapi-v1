<?php

namespace Agentoom\OpenApiV1\Transformers;

use Agentoom\OpenApiV1\Services\OpenAIResponseBuilder;
use Laravel\Ai\Responses\AgentResponse;

class ChatCompletionTransformer
{
    public function __construct(
        private readonly OpenAIResponseBuilder $builder = new OpenAIResponseBuilder,
    ) {}

    /**
     * Transform an AgentResponse into an OpenAI-compatible chat completion array.
     *
     * Pure data transformation — no side effects, no logging, no accounting.
     *
     * @return array{id: string, object: string, created: int, model: string, choices: array, usage?: array}
     */
    public function transform(AgentResponse $response, string $model): array
    {
        $result = [
            'id' => $this->builder->generateId(),
            'object' => 'chat.completion',
            'created' => $this->builder->now(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $response->text,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];

        $usage = $this->builder->mapUsage($response->usage);
        if ($usage !== null) {
            $result['usage'] = $usage;
        }

        return $result;
    }
}
