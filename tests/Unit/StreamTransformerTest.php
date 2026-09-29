<?php

namespace Agentoom\OpenApiV1\Tests\Unit;

use Agentoom\OpenApiV1\Tests\TestCase;
use Agentoom\OpenApiV1\Transformers\StreamTransformer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

class StreamTransformerTest extends TestCase
{
    private StreamTransformer $transformer;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transformer = new StreamTransformer;
        $this->now = time();
    }

    public function test_single_text_delta_produces_role_and_content_chunks(): void
    {
        $events = [
            new TextDelta('id-1', 'msg-1', 'Hello world', $this->now),
            new StreamEnd('id-2', 'stop', new Usage, $this->now),
        ];

        $output = $this->captureStream($events, 'chatcmpl-test-1', 'test-model');
        $lines = $this->parseSSELines($output);

        $this->assertGreaterThanOrEqual(3, count($lines), 'Expected at least 3 SSE lines');

        // First line should contain the role chunk
        $this->assertStringContainsString('assistant', $lines[0]);
        // Second line should contain the content chunk
        $this->assertStringContainsString('Hello world', $lines[1]);
        // Last line should be [DONE]
        $this->assertStringContainsString('[DONE]', end($lines));
    }

    public function test_chunks_have_correct_openai_format(): void
    {
        $events = [
            new TextDelta('id-1', 'msg-1', 'Hello', $this->now),
            new StreamEnd('id-2', 'stop', new Usage, $this->now),
        ];

        $output = $this->captureStream($events, 'chatcmpl-test-fmt', 'my-model');
        $lines = $this->parseSSELines($output);

        $this->assertGreaterThanOrEqual(1, count($lines));

        $data = $this->extractJsonData($lines[0]);
        $this->assertSame('chat.completion.chunk', $data['object']);
        $this->assertSame('chatcmpl-test-fmt', $data['id']);
        $this->assertSame('my-model', $data['model']);
        $this->assertArrayHasKey('choices', $data);
        $this->assertCount(1, $data['choices']);
        $this->assertArrayHasKey('delta', $data['choices'][0]);
        $this->assertSame('assistant', $data['choices'][0]['delta']['role']);
    }

    public function test_tool_call_events_are_silently_consumed(): void
    {
        $events = [
            new TextDelta('id-1', 'msg-1', 'Before call', $this->now),
            new ToolCall('id-tool', new ToolCallData('tool-1', 'web_search', ['query' => 'test']), $this->now),
            new ToolResult('id-result', new ToolResultData('tool-1', 'web_search', ['query' => 'test'], 'Result text'), true, null, $this->now),
            new TextDelta('id-2', 'msg-1', 'After call', $this->now),
            new StreamEnd('id-3', 'stop', new Usage, $this->now),
        ];

        $output = $this->captureStream($events, 'chatcmpl-test-events', 'test-model');

        // Should not contain any tool-related content
        $this->assertStringNotContainsStringIgnoringCase('web_search', $output);

        // Should contain only text content and DONE
        $this->assertStringContainsString('Before call', $output);
        $this->assertStringContainsString('After call', $output);
    }

    public function test_error_event_produces_error_chunk_then_done(): void
    {
        $events = [
            new Error('err-1', 'agent_execution_failed', 'Something went wrong', false, $this->now),
        ];

        $output = $this->captureStream($events, 'chatcmpl-test-err', 'test-model');
        $lines = $this->parseSSELines($output);

        // Error chunk + [DONE] — at least 2 lines
        $this->assertGreaterThanOrEqual(2, count($lines));

        $errorData = $this->extractJsonData($lines[0]);
        $this->assertArrayHasKey('error', $errorData);
        $this->assertSame('server_error', $errorData['error']['type']);
        $this->assertSame('agent_execution_failed', $errorData['error']['code']);

        // Last line must be [DONE]
        $this->assertStringContainsString('[DONE]', end($lines));
    }

    public function test_response_has_correct_headers(): void
    {
        $events = [
            new TextDelta('id-1', 'msg-1', 'Hi', $this->now),
            new StreamEnd('id-2', 'stop', new Usage, $this->now),
        ];

        $stream = $this->createMockStream($events);
        $response = $this->transformer->toResponse($stream, 'chatcmpl-test-hdr', 'test-model');

        $this->assertSame('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    /**
     * Create a mock StreamableAgentResponse that yields the given events.
     */
    private function createMockStream(array $events): StreamableAgentResponse
    {
        return new StreamableAgentResponse(
            invocationId: 'inv-test-stream',
            generator: (function () use ($events) {
                yield from $events;
            }),
            meta: new Meta,
        );
    }

    /**
     * Capture the SSE output from a stream.
     */
    private function captureStream(array $events, string $id, string $model): string
    {
        $stream = $this->createMockStream($events);

        ob_start();
        // Invoke the stream callback directly (same as what response()->stream() would do)
        $response = $this->transformer->toResponse($stream, $id, $model);

        // Extract the callback from the StreamedResponse and run it
        $reflection = new \ReflectionClass($response);
        $callbackProp = $reflection->getProperty('callback');
        $callbackProp->setAccessible(true);
        $callback = $callbackProp->getValue($response);

        ($callback)();

        return ob_get_clean();
    }

    /**
     * Parse SSE output into an array of non-empty lines.
     */
    private function parseSSELines(string $output): array
    {
        return array_values(array_filter(explode("\n", $output), function (string $line) {
            return trim($line) !== '';
        }));
    }

    /**
     * Extract the JSON data from an SSE "data: ..." line.
     */
    private function extractJsonData(string $line): array
    {
        $line = trim($line);
        // Remove "data: " prefix
        if (str_starts_with($line, 'data: ')) {
            $json = substr($line, 6);
        } else {
            $json = $line;
        }

        return json_decode($json, true);
    }
}
