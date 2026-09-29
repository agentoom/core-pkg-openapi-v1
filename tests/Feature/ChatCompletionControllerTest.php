<?php

namespace Agentoom\OpenApiV1\Tests\Feature;

use Agentoom\Core\Models\Agent;
use Agentoom\OpenApiV1\Contracts\AgentExecutionAdapter;
use Agentoom\OpenApiV1\DTOs\AgentStreamResult;
use Agentoom\OpenApiV1\Models\OpenApiToken;
use Agentoom\OpenApiV1\Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Spatie\Permission\Models\Role;

class ChatCompletionControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $plainToken;

    private OpenApiToken $token;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'superadmin']);

        $this->user = User::factory()->create();
        $this->user->assignRole('superadmin');

        $this->plainToken = 'sk-agt-test-'.Str::random(32);
        $this->token = OpenApiToken::factory()->create([
            'user_id' => $this->user->id,
            'token' => hash('sha256', $this->plainToken),
            'token_prefix' => substr($this->plainToken, 0, 14),
        ]);
    }

    private function authHeader(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->plainToken,
        ];
    }

    public function test_non_streaming_chat_completion(): void
    {
        $agent = Agent::factory()->create([
            'slug' => 'test-agent',
            'is_active' => true,
            'system_agent' => false,
            'is_public' => true,
        ]);

        $fakeResponse = new AgentResponse(
            invocationId: 'inv-test-1',
            text: 'Hello! How can I help you today?',
            usage: new Usage(promptTokens: 10, completionTokens: 5),
            meta: new Meta,
        );

        $this->mockAdapter(fakePromptResponse: $fakeResponse);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'test-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello!'],
            ],
            'stream' => false,
        ], $this->authHeader());

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'id',
            'object',
            'created',
            'model',
            'choices',
            'usage',
        ]);
        $response->assertJsonPath('object', 'chat.completion');
        $response->assertJsonPath('model', 'test-agent');
        $response->assertJsonPath('choices.0.message.role', 'assistant');
        $response->assertJsonPath('choices.0.message.content', 'Hello! How can I help you today?');
        $response->assertJsonPath('choices.0.finish_reason', 'stop');
        $response->assertJsonPath('usage.prompt_tokens', 10);
        $response->assertJsonPath('usage.completion_tokens', 5);
        $response->assertJsonPath('usage.total_tokens', 15);
    }

    public function test_non_streaming_without_usage_omits_usage_field(): void
    {
        $agent = Agent::factory()->create([
            'slug' => 'test-agent',
            'is_active' => true,
            'system_agent' => false,
            'is_public' => true,
        ]);

        $fakeResponse = new AgentResponse(
            invocationId: 'inv-test-2',
            text: 'Hello!',
            usage: new Usage(promptTokens: 0, completionTokens: 0),
            meta: new Meta,
        );

        $this->mockAdapter(fakePromptResponse: $fakeResponse);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'test-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello!'],
            ],
        ], $this->authHeader());

        $response->assertStatus(200);
        $response->assertJsonMissing(['usage']);
    }

    public function test_model_not_found_returns_404(): void
    {
        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'nonexistent-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello!'],
            ],
        ], $this->authHeader());

        $response->assertStatus(404);
        $response->assertJson([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'model_not_found',
                'param' => 'model',
            ],
        ]);
    }

    public function test_inactive_agent_returns_404(): void
    {
        Agent::factory()->create([
            'slug' => 'inactive-agent',
            'is_active' => false,
            'system_agent' => false,
            'is_public' => true,
        ]);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'inactive-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello!'],
            ],
        ], $this->authHeader());

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'model_not_found');
    }

    public function test_system_agent_returns_404(): void
    {
        Agent::factory()->create([
            'slug' => 'system-agent',
            'is_active' => true,
            'system_agent' => true,
            'is_public' => true,
        ]);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'system-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello!'],
            ],
        ], $this->authHeader());

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'model_not_found');
    }

    public function test_missing_model_returns_400(): void
    {
        $response = $this->postJson('/v1/chat/completions', [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello!'],
            ],
        ], $this->authHeader());

        $response->assertStatus(400);
        $response->assertJson([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'invalid_request',
                'param' => 'model',
            ],
        ]);
    }

    public function test_missing_messages_returns_400(): void
    {
        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'test-agent',
        ], $this->authHeader());

        $response->assertStatus(400);
        $response->assertJson([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'invalid_request',
                'param' => 'messages',
            ],
        ]);
    }

    public function test_invalid_message_role_returns_400(): void
    {
        Agent::factory()->create([
            'slug' => 'test-agent',
            'is_active' => true,
            'system_agent' => false,
            'is_public' => true,
        ]);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'test-agent',
            'messages' => [
                ['role' => 'tool', 'content' => 'some tool content'],
            ],
        ], $this->authHeader());

        $response->assertStatus(400);
        $response->assertJson([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'invalid_message_role',
                'param' => 'messages',
            ],
        ]);
    }

    public function test_empty_message_content_returns_400(): void
    {
        Agent::factory()->create([
            'slug' => 'test-agent',
            'is_active' => true,
            'system_agent' => false,
            'is_public' => true,
        ]);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'test-agent',
            'messages' => [
                ['role' => 'user', 'content' => ''],
            ],
        ], $this->authHeader());

        $response->assertStatus(400);
        $response->assertJson([
            'error' => [
                'code' => 'empty_message',
                'param' => 'messages',
            ],
        ]);
    }

    public function test_last_message_not_user_returns_400(): void
    {
        Agent::factory()->create([
            'slug' => 'test-agent',
            'is_active' => true,
            'system_agent' => false,
            'is_public' => true,
        ]);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'test-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello!'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
            ],
        ], $this->authHeader());

        $response->assertStatus(400);
        $response->assertJson([
            'error' => [
                'code' => 'invalid_message_order',
                'param' => 'messages',
            ],
        ]);
    }

    public function test_invalid_json_returns_400(): void
    {
        $response = $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Authorization' => 'Bearer '.$this->plainToken,
        ], 'not valid json');

        $response->assertStatus(400);
        $response->assertJson([
            'error' => [
                'code' => 'invalid_request',
            ],
        ]);
    }

    public function test_missing_api_key_returns_401_with_openai_format(): void
    {
        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'test-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello!'],
            ],
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => [
                'type' => 'authentication_error',
                'code' => 'invalid_api_key',
            ],
        ]);
    }

    public function test_invalid_api_key_returns_401_with_openai_format(): void
    {
        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'test-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello!'],
            ],
        ], [
            'Authorization' => 'Bearer sk-agt-unknown-key',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => [
                'type' => 'authentication_error',
                'code' => 'invalid_api_key',
            ],
        ]);
    }

    public function test_streaming_returns_sse(): void
    {
        $agent = Agent::factory()->create([
            'slug' => 'stream-agent',
            'is_active' => true,
            'system_agent' => false,
            'is_public' => true,
        ]);

        $now = time();
        $fakeStream = new StreamableAgentResponse(
            invocationId: 'inv-stream-1',
            generator: (function () use ($now) {
                yield new TextDelta('id-1', 'msg-1', 'Hello streaming', $now);
                yield new StreamEnd('id-2', 'stop', new Usage, $now);
            })
        );

        $this->mockAdapter(fakeStreamResponse: $fakeStream);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'stream-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello!'],
            ],
            'stream' => true,
        ], $this->authHeader());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('[DONE]', $content);
    }

    /**
     * Mock the AgentExecutionAdapter in the service container.
     */
    private function mockAdapter(
        ?AgentResponse $fakePromptResponse = null,
        ?StreamableAgentResponse $fakeStreamResponse = null,
    ): void {
        $mock = $this->createMock(AgentExecutionAdapter::class);

        if ($fakePromptResponse !== null) {
            $mock->method('prompt')
                ->willReturn($fakePromptResponse);
        }

        if ($fakeStreamResponse !== null) {
            $mock->method('stream')
                ->willReturn(new AgentStreamResult($fakeStreamResponse));
        }

        $this->app->instance(AgentExecutionAdapter::class, $mock);
    }
}
