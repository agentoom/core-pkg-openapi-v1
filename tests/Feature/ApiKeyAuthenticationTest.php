<?php

namespace Agentoom\OpenApiV1\Tests\Feature;

use Agentoom\OpenApiV1\Models\OpenApiToken;
use Agentoom\OpenApiV1\Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class ApiKeyAuthenticationTest extends TestCase
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

    public function test_valid_key_authenticates_as_owner(): void
    {
        $response = $this->getJson('/v1/models', [
            'Authorization' => 'Bearer '.$this->plainToken,
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($this->token->refresh()->last_used_at);
    }

    public function test_missing_key_returns_401(): void
    {
        $response = $this->getJson('/v1/models');

        $response->assertStatus(401);
        $response->assertJsonPath('error.type', 'authentication_error');
        $response->assertJsonPath('error.code', 'invalid_api_key');
    }

    public function test_unknown_key_returns_401(): void
    {
        $response = $this->getJson('/v1/models', [
            'Authorization' => 'Bearer sk-agt-unknown',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'invalid_api_key');
    }

    public function test_inactive_key_returns_401(): void
    {
        $this->token->update(['is_active' => false]);

        $response = $this->getJson('/v1/models', [
            'Authorization' => 'Bearer '.$this->plainToken,
        ]);

        $response->assertStatus(401);
    }

    public function test_empty_allowed_list_allows_all_origins(): void
    {
        $this->token->update(['allowed_domains' => null]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->getJson('/v1/models', [
                'Authorization' => 'Bearer '.$this->plainToken,
            ]);

        $response->assertStatus(200);
    }

    public function test_exact_ip_match_is_allowed(): void
    {
        $this->token->update(['allowed_domains' => "example.com\n192.168.1.1"]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->getJson('/v1/models', [
                'Authorization' => 'Bearer '.$this->plainToken,
            ]);

        $response->assertStatus(200);
    }

    public function test_wildcard_ip_match_is_allowed(): void
    {
        $this->token->update(['allowed_domains' => '10.0.0.*']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.42'])
            ->getJson('/v1/models', [
                'Authorization' => 'Bearer '.$this->plainToken,
            ]);

        $response->assertStatus(200);
    }

    public function test_non_matching_ip_is_rejected(): void
    {
        $this->token->update(['allowed_domains' => '192.168.1.1, example.com']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->getJson('/v1/models', [
                'Authorization' => 'Bearer '.$this->plainToken,
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.type', 'authorization_error');
        $response->assertJsonPath('error.code', 'access_denied');
    }

    public function test_origin_host_match_is_allowed(): void
    {
        $this->token->update(['allowed_domains' => 'example.com']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->getJson('/v1/models', [
                'Authorization' => 'Bearer '.$this->plainToken,
                'Origin' => 'https://example.com',
            ]);

        $response->assertStatus(200);
    }

    public function test_wildcard_origin_matches_subdomain_and_apex(): void
    {
        $this->token->update(['allowed_domains' => '*.example.com']);

        $subdomain = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->getJson('/v1/models', [
                'Authorization' => 'Bearer '.$this->plainToken,
                'Origin' => 'https://app.example.com',
            ]);
        $subdomain->assertStatus(200);

        $apex = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->getJson('/v1/models', [
                'Authorization' => 'Bearer '.$this->plainToken,
                'Origin' => 'https://example.com',
            ]);
        $apex->assertStatus(200);
    }

    public function test_non_matching_origin_is_rejected(): void
    {
        $this->token->update(['allowed_domains' => 'example.com']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->getJson('/v1/models', [
                'Authorization' => 'Bearer '.$this->plainToken,
                'Origin' => 'https://evil.com',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'access_denied');
    }

    public function test_referer_host_match_is_allowed(): void
    {
        $this->token->update(['allowed_domains' => 'librechat.local']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->getJson('/v1/models', [
                'Authorization' => 'Bearer '.$this->plainToken,
                'Referer' => 'https://librechat.local/chat',
            ]);

        $response->assertStatus(200);
    }

    public function test_comma_separated_entries_are_supported(): void
    {
        $this->token->update(['allowed_domains' => 'one.example.com, 10.0.0.*']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->getJson('/v1/models', [
                'Authorization' => 'Bearer '.$this->plainToken,
            ]);

        $response->assertStatus(200);
    }
}
