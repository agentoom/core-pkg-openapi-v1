<?php

namespace Agentoom\OpenApiV1\Transformers;

use Agentoom\OpenApiV1\Services\StreamingRestorer;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class StreamTransformer
{
    /**
     * Stream event classes that should be silently consumed (not forwarded to the client).
     */
    private const SILENT_EVENTS = [
        ToolCall::class,
        ToolResult::class,
        ProviderToolEvent::class,
        ReasoningDelta::class,
        ReasoningStart::class,
        ReasoningEnd::class,
        StreamStart::class,
        TextStart::class,
        TextEnd::class,
        Citation::class,
    ];

    /**
     * Create an OpenAI-compatible SSE StreamedResponse from a StreamableAgentResponse.
     *
     * @param  StreamableAgentResponse  $stream  The streamable agent response
     * @param  string  $chatCompletionId  The pre-generated chat completion ID
     * @param  string  $model  The model slug used in the request
     * @param  StreamingRestorer|null  $restorer  Deanonymizes the text on the way out when PII anonymization is active
     */
    public function toResponse(
        StreamableAgentResponse $stream,
        string $chatCompletionId,
        string $model,
        ?StreamingRestorer $restorer = null,
    ): StreamedResponse {
        return response()->stream(
            function () use ($stream, $chatCompletionId, $model, $restorer): void {
                try {
                    $this->streamEvents($stream, $chatCompletionId, $model, $restorer);
                } catch (Throwable $e) {
                    $this->emitErrorChunk($e->getMessage());
                    $this->emitDone();
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]
        );
    }

    /**
     * Stream the events from the StreamableAgentResponse as OpenAI-compatible SSE chunks.
     */
    private function streamEvents(
        StreamableAgentResponse $stream,
        string $chatCompletionId,
        string $model,
        ?StreamingRestorer $restorer = null,
    ): void {
        $roleSent = false;
        $created = time();

        foreach ($stream as $event) {
            // Silently consume tool, reasoning, and other non-text events
            if ($this->isSilentEvent($event)) {
                continue;
            }

            if ($event instanceof TextDelta) {
                $this->emitText(
                    $restorer?->restoreDelta($event->delta) ?? $event->delta,
                    $chatCompletionId,
                    $model,
                    $created,
                    $roleSent,
                );
            } elseif ($event instanceof StreamEnd) {
                $this->emitText($restorer?->finish() ?? '', $chatCompletionId, $model, $created, $roleSent);

                $this->emitDone();
            } elseif ($event instanceof Error) {
                $this->emitText($restorer?->finish() ?? '', $chatCompletionId, $model, $created, $roleSent);

                $this->emitErrorChunk($event->toArray()['error'] ?? 'Stream error');
                $this->emitDone();
            }
        }
    }

    /**
     * Emit a content chunk, sending the assistant role with the first text that goes out.
     */
    private function emitText(
        string $text,
        string $chatCompletionId,
        string $model,
        int $created,
        bool &$roleSent,
    ): void {
        if ($text === '') {
            return;
        }

        if (! $roleSent) {
            $this->emitChunk($chatCompletionId, $model, $created, null, 'assistant');
            $roleSent = true;
        }

        $this->emitChunk($chatCompletionId, $model, $created, $text, null);
    }

    /**
     * Check if the event should be silently consumed.
     */
    private function isSilentEvent(object $event): bool
    {
        foreach (self::SILENT_EVENTS as $class) {
            if ($event instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Emit an OpenAI-compatible SSE chunk.
     */
    private function emitChunk(
        string $id,
        string $model,
        int $created,
        ?string $content,
        ?string $role,
    ): void {
        $delta = [];

        if ($role !== null) {
            $delta['role'] = $role;
        }

        if ($content !== null) {
            $delta['content'] = $content;
        }

        $chunk = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => $delta,
                ],
            ],
        ];

        echo 'data: '.json_encode($chunk)."\n\n";
    }

    /**
     * Emit an error chunk in OpenAI-compatible SSE format.
     */
    private function emitErrorChunk(string $message): void
    {
        $errorChunk = [
            'error' => [
                'message' => $message,
                'type' => 'server_error',
                'code' => 'agent_execution_failed',
                'param' => null,
            ],
        ];

        echo 'data: '.json_encode($errorChunk)."\n\n";
    }

    /**
     * Emit the [DONE] marker.
     */
    private function emitDone(): void
    {
        echo "data: [DONE]\n\n";
    }
}
