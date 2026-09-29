<?php

namespace Agentoom\OpenApiV1\Tests\Feature;

use Agentoom\Core\Ai\Agents\DynamicAgent;
use Agentoom\Core\Contracts\PrivacyIntegration;
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
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Permission\Models\Role;

class OpenApiAnonymizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Placeholder the stubbed privacy service swaps the subject for.
     */
    private const PLACEHOLDER = '<REDACTED-PERSON-8F13A2C4E1>';

    private const SUBJECT = 'Ada Lovelace';

    private string $plainToken;

    private User $user;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'superadmin']);

        Configuration::setValue('app_ai_credits', 1000000);

        $this->app->bind(AgentExecutionAdapter::class, DynamicAgentExecutionAdapter::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('superadmin');

        $this->agent = Agent::factory()->create([
            'slug' => 'privacy-agent',
            'is_active' => true,
            'system_agent' => false,
            'is_public' => true,
            'anonymization_enabled' => true,
        ]);

        $this->plainToken = 'sk-agt-'.Str::random(40);

        OpenApiToken::factory()->create([
            'user_id' => $this->user->id,
            'token' => hash('sha256', $this->plainToken),
            'token_prefix' => substr($this->plainToken, 0, 14),
        ]);
    }

    public function test_prompt_is_anonymized_and_the_response_is_restored(): void
    {
        $this->stubPrivacy();
        Ai::fakeAgent(DynamicAgent::class, fn () => 'Hello '.self::PLACEHOLDER.', welcome back.');

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'privacy-agent',
            'messages' => [['role' => 'user', 'content' => 'My name is '.self::SUBJECT]],
        ], $this->authHeader())->assertOk();

        // The provider only ever sees the placeholder…
        Ai::assertAgentWasPrompted(
            DynamicAgent::class,
            fn ($prompt): bool => str_contains($prompt->prompt, self::PLACEHOLDER)
                && ! str_contains($prompt->prompt, self::SUBJECT),
            message: 'The prompt sent to the provider must be anonymized.',
        );

        // …while the client only ever sees the original values.
        $response->assertJsonPath('choices.0.message.content', 'Hello '.self::SUBJECT.', welcome back.');

        $conversation = DB::table('agent_conversations')->first();

        $this->assertNotNull($conversation);

        $userMessage = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->first();

        $this->assertSame('My name is '.self::SUBJECT, $userMessage->content);
        $this->assertStringContainsString(self::PLACEHOLDER, (string) $userMessage->anonymized_content);

        $assistantMessage = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->first();

        $this->assertSame('Hello '.self::SUBJECT.', welcome back.', $assistantMessage->content);
    }

    public function test_streaming_response_placeholders_are_restored_before_reaching_the_client(): void
    {
        $this->stubPrivacy();
        Ai::fakeAgent(DynamicAgent::class, fn () => 'Hi '.self::PLACEHOLDER.' bye');

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'privacy-agent',
            'messages' => [['role' => 'user', 'content' => 'My name is '.self::SUBJECT]],
            'stream' => true,
        ], $this->authHeader());

        $response->assertOk();

        $body = $response->streamedContent();

        $this->assertStringContainsString(self::SUBJECT, $body);
        $this->assertStringNotContainsString('<REDACTED-', $body);
        $this->assertStringContainsString('[DONE]', $body);

        // The persisted transcript holds clear text too, with the redacted copy beside it.
        $assistantMessage = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();

        $this->assertSame('Hi '.self::SUBJECT.' bye', $assistantMessage->content);
    }

    public function test_agent_without_anonymization_forwards_text_unchanged(): void
    {
        $this->stubPrivacy();
        $this->agent->update(['anonymization_enabled' => false]);

        Ai::fakeAgent(DynamicAgent::class, fn () => 'Hello '.self::SUBJECT.', welcome back.');

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'privacy-agent',
            'messages' => [['role' => 'user', 'content' => 'My name is '.self::SUBJECT]],
        ], $this->authHeader())->assertOk();

        Ai::assertAgentWasPrompted(
            DynamicAgent::class,
            fn ($prompt): bool => str_contains($prompt->prompt, self::SUBJECT),
            message: 'An agent without anonymization enabled must receive the original text.',
        );

        $response->assertJsonPath('choices.0.message.content', 'Hello '.self::SUBJECT.', welcome back.');
    }

    public function test_untracked_traffic_is_not_anonymized(): void
    {
        config(['openapi-v1.conversation.track' => false]);

        $this->stubPrivacy();
        Ai::fakeAgent(DynamicAgent::class);

        $this->postJson('/v1/chat/completions', [
            'model' => 'privacy-agent',
            'messages' => [['role' => 'user', 'content' => 'My name is '.self::SUBJECT]],
        ], $this->authHeader())->assertOk();

        // Without a conversation there is no mapping key to restore from.
        Ai::assertAgentWasPrompted(
            DynamicAgent::class,
            fn ($prompt): bool => str_contains($prompt->prompt, self::SUBJECT),
            message: 'Untracked traffic has no mapping key and must be forwarded untouched.',
        );
    }

    public function test_unavailable_privacy_service_stops_execution_when_the_policy_requires_it(): void
    {
        $this->stubPrivacy(serviceAvailable: false, stopWhenUnavailable: true);
        Ai::fakeAgent(DynamicAgent::class);

        $this->postJson('/v1/chat/completions', [
            'model' => 'privacy-agent',
            'messages' => [['role' => 'user', 'content' => 'My name is '.self::SUBJECT]],
        ], $this->authHeader())->assertStatus(500);

        Ai::assertAgentNeverPrompted(DynamicAgent::class);
    }

    public function test_unavailable_privacy_service_continues_unredacted_when_the_policy_allows_it(): void
    {
        $this->stubPrivacy(serviceAvailable: false);
        Ai::fakeAgent(DynamicAgent::class);

        $this->postJson('/v1/chat/completions', [
            'model' => 'privacy-agent',
            'messages' => [['role' => 'user', 'content' => 'My name is '.self::SUBJECT]],
        ], $this->authHeader())->assertOk();

        Ai::assertAgentWasPrompted(
            DynamicAgent::class,
            fn ($prompt): bool => str_contains($prompt->prompt, self::SUBJECT),
            message: 'With warn_and_continue the request must proceed unredacted rather than fail.',
        );
    }

    /**
     * Stub the privacy capability with deterministic placeholder swapping, so the
     * tests can assert what the provider and the client each get to see.
     */
    private function stubPrivacy(bool $serviceAvailable = true, bool $stopWhenUnavailable = false): void
    {
        $this->mock(PrivacyIntegration::class, function (MockInterface $mock) use ($serviceAvailable, $stopWhenUnavailable) {
            $store = [];

            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('redactAgpiiTags')->andReturnUsing(fn (string $text): string => $text);
            $mock->shouldReceive('hasPlaceholders')->andReturnUsing(fn (string $text): bool => str_contains($text, '<REDACTED-'));
            $mock->shouldReceive('shouldAnonymizeForAgent')->andReturnUsing(fn (Agent $agent): bool => (bool) $agent->anonymization_enabled);
            $mock->shouldReceive('shouldAnonymize')->andReturnUsing(fn (?string $conversationId): bool => $conversationId !== null);

            $mock->shouldReceive('anonymize')->andReturnUsing(function (string $text, string $mappingKey) use (&$store, $serviceAvailable): array {
                if (! $serviceAvailable) {
                    throw new RuntimeException('Anonymization service is unreachable.');
                }

                $mapping = $store[$mappingKey] ?? [];

                if (str_contains($text, self::SUBJECT)) {
                    $mapping[self::PLACEHOLDER] = self::SUBJECT;
                }

                $store[$mappingKey] = $mapping;

                return [
                    'text' => str_replace(array_values($mapping), array_keys($mapping), $text),
                    'mapping_key' => $mappingKey,
                ];
            });

            $mock->shouldReceive('restore')->andReturnUsing(function (string $text, string $mappingKey) use (&$store): string {
                if (! isset($store[$mappingKey])) {
                    throw new RuntimeException("No PII mapping found for key: {$mappingKey}");
                }

                return str_replace(array_keys($store[$mappingKey]), array_values($store[$mappingKey]), $text);
            });

            $mock->shouldReceive('handleUnavailable')->andReturnUsing(function () use ($stopWhenUnavailable) {
                if ($stopWhenUnavailable) {
                    throw new RuntimeException('Anonymization service is unreachable and execution is configured to stop.');
                }

                return 'Privacy anonymization service is unreachable.';
            });
        });
    }

    /**
     * @return array<string, string>
     */
    private function authHeader(): array
    {
        return ['Authorization' => 'Bearer '.$this->plainToken];
    }
}
