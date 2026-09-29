<?php

namespace Agentoom\OpenApiV1\Tests\Unit;

use Agentoom\Core\Models\Agent;
use Agentoom\OpenApiV1\Exceptions\OpenApiException;
use Agentoom\OpenApiV1\Services\AgentResolver;
use Agentoom\OpenApiV1\Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AgentResolverTest extends TestCase
{
    use RefreshDatabase;

    private AgentResolver $resolver;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new AgentResolver;
        $this->user = User::factory()->create();
    }

    public function test_resolves_active_public_agent(): void
    {
        $agent = Agent::factory()->create([
            'slug' => 'public-agent',
            'is_active' => true,
            'system_agent' => false,
            'is_public' => true,
        ]);

        $resolved = $this->resolver->resolve('public-agent', $this->user);

        $this->assertInstanceOf(Agent::class, $resolved);
        $this->assertSame($agent->id, $resolved->id);
    }

    public function test_resolves_agent_owned_by_user(): void
    {
        $agent = Agent::factory()->create([
            'slug' => 'my-agent',
            'is_active' => true,
            'system_agent' => false,
            'is_public' => false,
            'user_id' => $this->user->id,
        ]);

        $resolved = $this->resolver->resolve('my-agent', $this->user);

        $this->assertInstanceOf(Agent::class, $resolved);
        $this->assertSame($agent->id, $resolved->id);
    }

    public function test_throws_on_nonexistent_slug(): void
    {
        $this->expectException(OpenApiException::class);
        $this->expectExceptionCode(0);
        $this->expectExceptionMessage('The model "nonexistent" does not exist or you do not have access to it.');

        $this->resolver->resolve('nonexistent', $this->user);
    }

    public function test_throws_on_inactive_agent(): void
    {
        Agent::factory()->create([
            'slug' => 'inactive-agent',
            'is_active' => false,
            'system_agent' => false,
            'is_public' => true,
        ]);

        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('The model "inactive-agent" does not exist or you do not have access to it.');

        $this->resolver->resolve('inactive-agent', $this->user);
    }

    public function test_throws_on_system_agent(): void
    {
        Agent::factory()->create([
            'slug' => 'system-agent',
            'is_active' => true,
            'system_agent' => true,
            'is_public' => true,
        ]);

        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('The model "system-agent" does not exist or you do not have access to it.');

        $this->resolver->resolve('system-agent', $this->user);
    }

    public function test_throws_on_private_agent_not_accessible_by_user(): void
    {
        $anotherUser = User::factory()->create();

        Agent::factory()->create([
            'slug' => 'private-agent',
            'is_active' => true,
            'system_agent' => false,
            'is_public' => false,
            'user_id' => $anotherUser->id,
        ]);

        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('The model "private-agent" does not exist or you do not have access to it.');

        $this->resolver->resolve('private-agent', $this->user);
    }

    public function test_not_found_exception_has_correct_properties(): void
    {
        try {
            $this->resolver->resolve('nope', $this->user);
            $this->fail('Expected exception was not thrown');
        } catch (OpenApiException $e) {
            $this->assertSame('invalid_request_error', $e->type);
            $this->assertSame('model_not_found', $e->errorCode);
            $this->assertSame('model', $e->param);
        }
    }
}
