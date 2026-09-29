<?php

namespace Agentoom\OpenApiV1\Tests\Unit;

use Agentoom\Core\Models\Agent;
use Agentoom\OpenApiV1\DTOs\ChatCompletionRequest;
use Agentoom\OpenApiV1\Services\ConversationAnchorResolver;
use Agentoom\OpenApiV1\Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

class ConversationAnchorResolverTest extends TestCase
{
    use RefreshDatabase;

    private ConversationAnchorResolver $resolver;

    private User $user;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ConversationAnchorResolver;

        $this->user = User::factory()->create();
        $this->agent = Agent::factory()->create(['slug' => 'anchor-agent', 'is_active' => true]);
    }

    public function test_client_chat_header_is_used_and_namespaced(): void
    {
        $anchor = $this->resolver->resolve(
            $this->request(['X-OpenWebUI-Chat-Id' => 'chat-abc']),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'user', 'content' => 'hello']]),
        );

        $this->assertSame('openapi_'.$this->user->id.'_chat-abc', $anchor);
    }

    public function test_headers_are_checked_in_configured_order(): void
    {
        $anchor = $this->resolver->resolve(
            $this->request([
                'X-Conversation-Id' => 'generic-id',
                'X-OpenWebUI-Chat-Id' => 'openwebui-id',
            ]),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'user', 'content' => 'hello']]),
        );

        $this->assertSame('openapi_'.$this->user->id.'_openwebui-id', $anchor);
    }

    public function test_header_matching_is_case_insensitive_for_client_configured_names(): void
    {
        // LibreChat sends X-Conversation-ID via a {{LIBRECHAT_BODY_CONVERSATIONID}} header template.
        $anchor = $this->resolver->resolve(
            $this->request(['X-Conversation-ID' => 'librechat-conversation']),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'user', 'content' => 'hello']]),
        );

        $this->assertSame('openapi_'.$this->user->id.'_librechat-conversation', $anchor);
    }

    public function test_unsafe_characters_are_stripped_from_client_ids(): void
    {
        $anchor = $this->resolver->resolve(
            $this->request(['X-Conversation-Id' => 'chat/../1 %']),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'user', 'content' => 'hello']]),
        );

        $this->assertSame('openapi_'.$this->user->id.'_chat..1', $anchor);
    }

    public function test_header_that_sanitizes_to_nothing_falls_through(): void
    {
        $anchor = $this->resolver->resolve(
            $this->request([
                'X-OpenWebUI-Chat-Id' => '///',
                'X-Conversation-Id' => 'fallback-id',
            ]),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'user', 'content' => 'hello']]),
        );

        $this->assertSame('openapi_'.$this->user->id.'_fallback-id', $anchor);
    }

    public function test_client_id_length_is_capped(): void
    {
        $anchor = $this->resolver->resolve(
            $this->request(['X-Conversation-Id' => str_repeat('a', 400)]),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'user', 'content' => 'hello']]),
        );

        $this->assertSame('openapi_'.$this->user->id.'_'.str_repeat('a', 120), $anchor);
    }

    public function test_fingerprint_of_opening_message_is_stable_across_turns(): void
    {
        $openingTurn = $this->resolver->resolve(
            $this->request(),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'user', 'content' => 'first turn']]),
        );

        $laterTurn = $this->resolver->resolve(
            $this->request(),
            $this->user,
            $this->agent,
            $this->completion([
                ['role' => 'system', 'content' => 'be brief'],
                ['role' => 'user', 'content' => 'first turn'],
                ['role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second turn'],
            ]),
        );

        $this->assertNotNull($openingTurn);
        $this->assertStringStartsWith('openapi_'.$this->user->id.'_fp_', $openingTurn);
        $this->assertSame($openingTurn, $laterTurn);
    }

    public function test_fingerprint_differs_per_opening_message_and_agent(): void
    {
        $otherAgent = Agent::factory()->create(['slug' => 'other-anchor-agent', 'is_active' => true]);

        $base = $this->resolver->resolve(
            $this->request(),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'user', 'content' => 'first turn']]),
        );

        $otherMessage = $this->resolver->resolve(
            $this->request(),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'user', 'content' => 'different opening']]),
        );

        $otherAgentAnchor = $this->resolver->resolve(
            $this->request(),
            $this->user,
            $otherAgent,
            $this->completion([['role' => 'user', 'content' => 'first turn']]),
        );

        $this->assertNotSame($base, $otherMessage);
        $this->assertNotSame($base, $otherAgentAnchor);
    }

    public function test_no_user_message_yields_no_anchor(): void
    {
        $anchor = $this->resolver->resolve(
            $this->request(),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'system', 'content' => 'be brief']]),
        );

        $this->assertNull($anchor);
    }

    public function test_fingerprint_fallback_can_be_disabled(): void
    {
        config(['openapi-v1.conversation.fingerprint_fallback' => false]);

        $anchor = $this->resolver->resolve(
            $this->request(),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'user', 'content' => 'first turn']]),
        );

        $this->assertNull($anchor);
    }

    public function test_tracking_can_be_disabled(): void
    {
        config(['openapi-v1.conversation.track' => false]);

        $anchor = $this->resolver->resolve(
            $this->request(['X-OpenWebUI-Chat-Id' => 'chat-abc']),
            $this->user,
            $this->agent,
            $this->completion([['role' => 'user', 'content' => 'hello']]),
        );

        $this->assertNull($anchor);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(array $headers = []): Request
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/v1/chat/completions', 'POST', server: $server);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function completion(array $messages): ChatCompletionRequest
    {
        return ChatCompletionRequest::fromArray([
            'model' => 'anchor-agent',
            'messages' => $messages,
        ]);
    }
}
