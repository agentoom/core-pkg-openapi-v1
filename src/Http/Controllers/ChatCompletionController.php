<?php

namespace Agentoom\OpenApiV1\Http\Controllers;

use Agentoom\OpenApiV1\Contracts\AgentExecutionAdapter;
use Agentoom\OpenApiV1\DTOs\ChatCompletionRequest;
use Agentoom\OpenApiV1\Exceptions\OpenApiException;
use Agentoom\OpenApiV1\Services\AgentResolver;
use Agentoom\OpenApiV1\Services\ConversationAnchorResolver;
use Agentoom\OpenApiV1\Services\MessageToPromptConverter;
use Agentoom\OpenApiV1\Services\OpenAIResponseBuilder;
use Agentoom\OpenApiV1\Transformers\ChatCompletionTransformer;
use Agentoom\OpenApiV1\Transformers\StreamTransformer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatCompletionController
{
    /**
     * Handle a chat completion request.
     */
    public function __invoke(
        Request $request,
        AgentResolver $resolver,
        MessageToPromptConverter $converter,
        AgentExecutionAdapter $adapter,
        ChatCompletionTransformer $transformer,
        StreamTransformer $streamTransformer,
        OpenAIResponseBuilder $responseBuilder,
        ConversationAnchorResolver $anchorResolver,
    ): JsonResponse|StreamedResponse {
        // Parse and validate the JSON body
        $data = json_decode($request->getContent(), true);

        if (! is_array($data)) {
            throw OpenApiException::invalidJson();
        }

        // Parse and validate into DTO (throws OpenApiException on validation failure)
        $completionRequest = ChatCompletionRequest::fromArray($data);

        // Resolve the authenticated user
        /** @var User|null $user */
        $user = auth()->user();

        if ($user === null) {
            throw new OpenApiException(
                'Invalid or missing authentication.',
                'authentication_error',
                'invalid_api_key',
                null,
                Response::HTTP_UNAUTHORIZED,
            );
        }

        // Resolve the model to an agent
        $agent = $resolver->resolve($completionRequest->model, $user);

        // Convert messages to execution input
        $input = $converter->convert($completionRequest->messages);

        // Identify the client's conversation so the traffic is tracked against it
        $conversationAnchor = $anchorResolver->resolve($request, $user, $agent, $completionRequest);

        // Streaming path
        if ($completionRequest->stream) {
            $execution = $adapter->stream($agent, $input, $conversationAnchor);
            $chatCompletionId = $responseBuilder->generateId();

            return $streamTransformer->toResponse(
                $execution->stream,
                $chatCompletionId,
                $completionRequest->model,
                $execution->restorer,
            );
        }

        // Non-streaming path
        $response = $adapter->prompt($agent, $input, $conversationAnchor);

        // Transform to OpenAI-compatible format
        $result = $transformer->transform($response, $completionRequest->model);

        return response()->json($result);
    }
}
