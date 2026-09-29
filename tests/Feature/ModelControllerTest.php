<?php

namespace Agentoom\OpenApiV1\Tests\Feature;

use Agentoom\Core\Models\Agent;
use Agentoom\OpenApiV1\Models\OpenApiToken;
use Agentoom\OpenApiV1\Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class ModelControllerTest extends TestCase
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

    public function test_returns_agents_visible_to_user(): void
    {
        $agent1 = Agent::factory()->create([
            'slug' => 'my-agent',
            'name' => 'My Agent',
            'is_active' => true,
            'system_agent' => false,
        ]);

        $agent2 = Agent::factory()->create([
            'slug' => 'another-agent',
            'name' => 'Another Agent',
            'is_active' => true,
            'system_agent' => false,
        ]);

        $response = $this->getJson('/v1/models', $this->authHeader());

        $response->assertStatus(200);
        $response->assertJson([
            'object' => 'list',
        ]);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $agent1->slug);
        $response->assertJsonPath('data.1.id', $agent2->slug);
    }

    public function test_returns_openai_compatible_model_format(): void
    {
        $agent = Agent::factory()->create([
            'slug' => 'test-model',
            'name' => 'Test Model',
            'is_active' => true,
            'system_agent' => false,
        ]);

        $response = $this->getJson('/v1/models', $this->authHeader());

        $response->assertStatus(200);
        $response->assertJsonPath('object', 'list');
        $response->assertJsonPath('data.0.id', 'test-model');
        $response->assertJsonPath('data.0.object', 'model');
        $response->assertJsonPath('data.0.owned_by', 'agentoom');
        $this->assertArrayHasKey('created', $response->json('data.0'));
    }

    public function test_excludes_inactive_agents(): void
    {
        Agent::factory()->create([
            'slug' => 'active-agent',
            'is_active' => true,
            'system_agent' => false,
        ]);

        Agent::factory()->create([
            'slug' => 'inactive-agent',
            'is_active' => false,
            'system_agent' => false,
        ]);

        $response = $this->getJson('/v1/models', $this->authHeader());

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', 'active-agent');
    }

    public function test_excludes_system_agents(): void
    {
        Agent::factory()->create([
            'slug' => 'normal-agent',
            'is_active' => true,
            'system_agent' => false,
        ]);

        Agent::factory()->create([
            'slug' => 'system-agent',
            'is_active' => true,
            'system_agent' => true,
        ]);

        $response = $this->getJson('/v1/models', $this->authHeader());

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', 'normal-agent');
    }

    public function test_returns_empty_list_when_no_agents(): void
    {
        $response = $this->getJson('/v1/models', $this->authHeader());

        $response->assertStatus(200);
        $response->assertJson([
            'object' => 'list',
            'data' => [],
        ]);
    }

    public function test_returns_401_with_openai_format_when_missing_api_key(): void
    {
        $response = $this->getJson('/v1/models');

        $response->assertStatus(401);
        $response->assertJson([
            'error' => [
                'type' => 'authentication_error',
                'code' => 'invalid_api_key',
            ],
        ]);
    }

    public function test_returns_401_with_openai_format_when_api_key_invalid(): void
    {
        $response = $this->getJson('/v1/models', [
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

    public function test_returns_401_when_api_key_inactive(): void
    {
        $this->token->update(['is_active' => false]);

        $response = $this->getJson('/v1/models', [
            'Authorization' => 'Bearer '.$this->plainToken,
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => [
                'type' => 'authentication_error',
                'code' => 'invalid_api_key',
            ],
        ]);
    }
}
