<?php

namespace Agentoom\OpenApiV1\Http\Controllers;

use Agentoom\Core\Models\Agent;
use Agentoom\OpenApiV1\Transformers\ModelTransformer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ModelController
{
    /**
     * List available models (agents) visible to the authenticated user.
     */
    public function __invoke(ModelTransformer $transformer): JsonResponse
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'message' => 'Invalid or missing authentication.',
                    'type' => 'authentication_error',
                    'code' => 'invalid_api_key',
                    'param' => null,
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $agents = Agent::query()
            ->where('is_active', true)
            ->where('system_agent', false);

        $agents->visibleTo($user);

        $models = $agents->get()
            ->map(fn (Agent $agent) => $transformer->transform($agent))
            ->values()
            ->toArray();

        return response()->json([
            'object' => 'list',
            'data' => $models,
        ]);
    }
}
