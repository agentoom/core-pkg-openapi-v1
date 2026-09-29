<?php

namespace Agentoom\OpenApiV1\Tests\Feature;

use Agentoom\Core\Ai\Agents\DynamicAgent;
use Agentoom\Core\Models\Agent;
use Agentoom\Core\Models\Configuration;
use Agentoom\OpenApiV1\Contracts\AgentExecutionAdapter;
use Agentoom\OpenApiV1\Models\OpenApiToken;
use Agentoom\OpenApiV1\Services\DynamicAgentExecutionAdapter;
use Agentoom\OpenApiV1\Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Spatie\Permission\Models\Role;

class OpenApiConversationTrackingTest extends TestCase
{
    use RefreshDatabase;

    private string $plainToken;

    private User $user;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'superadmin']);

        Ai::fakeAgent(DynamicAgent::class, fn () => 'Assistant reply');
        Configuration::setValue('app_ai_credits', 1000000);

        // Exercise the real execution path: tracking is part of the adapter.
        $this->app->bind(AgentExecutionAdapter::class, DynamicAgentExecutionAdapter::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('superadmin');

        $this->agent = Agent::factory()->create([
            'slug' => 'tracking-agent',
            'is_active' => true,
            'system_agent' => false,
            'is_public' => true,
        ]);

        $this->plainToken = 'sk-agt-'.Str::random(40);

        OpenApiToken::factory()->create([
            'user_id' => $this->user->id,
            'token' => hash('sha256', $this->plainToken),
            'token_prefix' => substr($this->plainToken, 0, 14),
        ]);
    }

    public function test_multi_turn_chat_is_tracked_as_one_conversation(): void
    {
        $headers = $this->authHeader([
            'X-OpenWebUI-Chat-Id' => 'chat-abc',
            // Client-side identity headers must not be able to claim ownership.
            'X-OpenWebUI-User-Id' => 'someone-else',
        ]);

        $this->postJson('/v1/chat/completions', [
            'model' => 'tracking-agent',
            'messages' => [['role' => 'user', 'content' => 'first turn']],
        ], $headers)->assertOk();

        $this->postJson('/v1/chat/completions', [
            'model' => 'tracking-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'first turn'],
                ['role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second turn'],
            ],
        ], $headers)->assertOk();

        $conversations = DB::table('agent_conversations')->get();

        $this->assertCount(1, $conversations);

        $conversation = $conversations->first();

        $this->assertSame('openapi_'.$this->user->id.'_chat-abc', $conversation->session_id);
        $this->assertSame($this->user->id, $conversation->user_id);
        $this->assertSame($this->agent->id, $conversation->agent_id);
        $this->assertSame('first turn', $conversation->title);

        $messages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $this->assertSame(['user', 'assistant', 'user', 'assistant'], $messages->pluck('role')->all());
        $this->assertSame(['first turn', 'Assistant reply', 'second turn', 'Assistant reply'], $messages->pluck('content')->all());

        $this->assertSame(
            2,
            DB::table('ai_agent_traces')->where('conversation_id', $conversation->id)->count(),
        );
    }

    public function test_stored_history_is_not_replayed_into_the_prompt(): void
    {
        $headers = $this->authHeader(['X-OpenWebUI-Chat-Id' => 'chat-replay']);

        $this->postJson('/v1/chat/completions', [
            'model' => 'tracking-agent',
            'messages' => [['role' => 'user', 'content' => 'first turn']],
        ], $headers)->assertOk();

        $this->postJson('/v1/chat/completions', [
            'model' => 'tracking-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'first turn'],
                ['role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second turn'],
            ],
        ], $headers)->assertOk();

        Ai::assertAgentWasPrompted(DynamicAgent::class, function ($prompt): bool {
            return str_contains($prompt->prompt, 'second turn')
                && substr_count($prompt->prompt, 'first turn') === 1;
        }, message: 'The transcript persisted by agentoom must not be replayed next to the client-supplied history.');
    }

    public function test_distinct_chat_ids_are_tracked_separately(): void
    {
        foreach (['chat-one', 'chat-two'] as $chatId) {
            $this->postJson('/v1/chat/completions', [
                'model' => 'tracking-agent',
                'messages' => [['role' => 'user', 'content' => 'hello']],
            ], $this->authHeader(['X-OpenWebUI-Chat-Id' => $chatId]))->assertOk();
        }

        $this->assertSame(
            ['openapi_'.$this->user->id.'_chat-one', 'openapi_'.$this->user->id.'_chat-two'],
            DB::table('agent_conversations')->orderBy('session_id')->pluck('session_id')->all(),
        );
    }

    public function test_requests_without_client_headers_use_the_opening_message_fingerprint(): void
    {
        $headers = $this->authHeader();

        $this->postJson('/v1/chat/completions', [
            'model' => 'tracking-agent',
            'messages' => [['role' => 'user', 'content' => 'first turn']],
        ], $headers)->assertOk();

        $this->postJson('/v1/chat/completions', [
            'model' => 'tracking-agent',
            'messages' => [
                ['role' => 'user', 'content' => 'first turn'],
                ['role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second turn'],
            ],
        ], $headers)->assertOk();

        $anchor = DB::table('agent_conversations')->value('session_id');

        $this->assertStringStartsWith('openapi_'.$this->user->id.'_fp_', $anchor);
        $this->assertSame(1, DB::table('agent_conversations')->count());
        $this->assertSame(4, DB::table('agent_conversation_messages')->count());
    }

    public function test_streaming_traffic_is_tracked(): void
    {
        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'tracking-agent',
            'messages' => [['role' => 'user', 'content' => 'streamed turn']],
            'stream' => true,
        ], $this->authHeader(['X-OpenWebUI-Chat-Id' => 'chat-stream']));

        $response->assertOk();
        $this->assertStringContainsString('[DONE]', $response->streamedContent());

        $conversation = DB::table('agent_conversations')->first();

        $this->assertNotNull($conversation);
        $this->assertSame('openapi_'.$this->user->id.'_chat-stream', $conversation->session_id);
        $this->assertSame(
            ['user', 'assistant'],
            DB::table('agent_conversation_messages')
                ->where('conversation_id', $conversation->id)
                ->orderBy('created_at')
                ->pluck('role')
                ->all(),
        );
        $this->assertSame(
            1,
            DB::table('ai_agent_traces')->where('conversation_id', $conversation->id)->count(),
        );
    }

    public function test_tracking_can_be_disabled(): void
    {
        config(['openapi-v1.conversation.track' => false]);

        $this->postJson('/v1/chat/completions', [
            'model' => 'tracking-agent',
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ], $this->authHeader(['X-OpenWebUI-Chat-Id' => 'chat-abc']))->assertOk();

        $this->assertSame(0, DB::table('agent_conversations')->count());
        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
        $this->assertSame(1, DB::table('ai_agent_traces')->count());
        $this->assertNull(DB::table('ai_agent_traces')->value('conversation_id'));
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function authHeader(array $extra = []): array
    {
        return ['Authorization' => 'Bearer '.$this->plainToken] + $extra;
    }
}
