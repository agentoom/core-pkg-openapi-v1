<?php

namespace Agentoom\OpenApiV1\Tests\Feature;

use Agentoom\OpenApiV1\Livewire\Admin\OpenApiTokenCreateEdit;
use Agentoom\OpenApiV1\Livewire\Admin\OpenApiTokenIndex;
use Agentoom\OpenApiV1\Models\OpenApiToken;
use Agentoom\OpenApiV1\Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

class OpenApiTokenCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'superadmin']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('superadmin');
    }

    public function test_index_lists_tokens(): void
    {
        $token = OpenApiToken::factory()->create(['name' => 'LibreChat key']);

        Livewire::actingAs($this->admin)
            ->test(OpenApiTokenIndex::class)
            ->assertSee('LibreChat key')
            ->assertSee($token->token_prefix);
    }

    public function test_create_generates_hashed_token(): void
    {
        $owner = User::factory()->create();

        $component = Livewire::actingAs($this->admin)
            ->test(OpenApiTokenCreateEdit::class)
            ->set('name', 'Integration key')
            ->set('user_id', $owner->id)
            ->set('allowed_domains', 'example.com, 10.0.0.*')
            ->call('submit')
            ->assertSet('show_token_modal', true);

        $plain = $component->get('generated_token');

        $this->assertStringStartsWith('sk-agt-', $plain);

        $token = OpenApiToken::query()->where('name', 'Integration key')->first();

        $this->assertNotNull($token);
        $this->assertSame($owner->id, $token->user_id);
        $this->assertSame('example.com, 10.0.0.*', $token->allowed_domains);
        $this->assertSame(hash('sha256', $plain), $token->token);
        $this->assertSame(substr($plain, 0, 14), $token->token_prefix);
        $this->assertNotSame($plain, $token->token);
    }

    public function test_stored_token_is_hash_not_plain(): void
    {
        $plain = 'sk-agt-plaintext-value';

        $token = OpenApiToken::factory()->create([
            'token' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 14),
        ]);

        $this->assertNotSame($plain, $token->token);
        $this->assertSame($token->id, OpenApiToken::findActiveByPlain($plain)?->id);
        $this->assertNull(OpenApiToken::findActiveByPlain('sk-agt-wrong'));
    }

    public function test_system_user_cannot_own_token(): void
    {
        $systemUser = User::factory()->create(['is_system' => true]);

        Livewire::actingAs($this->admin)
            ->test(OpenApiTokenCreateEdit::class)
            ->set('name', 'Bad key')
            ->set('user_id', $systemUser->id)
            ->call('submit')
            ->assertHasErrors(['user_id']);

        $this->assertDatabaseMissing('open_api_tokens', ['name' => 'Bad key']);
    }

    public function test_update_changes_fields_but_keeps_key(): void
    {
        $token = OpenApiToken::factory()->create(['name' => 'Original']);
        $originalHash = $token->token;

        Livewire::actingAs($this->admin)
            ->test(OpenApiTokenCreateEdit::class, ['token' => $token])
            ->set('name', 'Renamed')
            ->set('allowed_domains', '*.example.com')
            ->set('is_active', false)
            ->call('submit');

        $token->refresh();

        $this->assertSame('Renamed', $token->name);
        $this->assertSame('*.example.com', $token->allowed_domains);
        $this->assertFalse($token->is_active);
        $this->assertSame($originalHash, $token->token);
    }

    public function test_regenerate_invalidates_old_key(): void
    {
        $oldPlain = 'sk-agt-old-plaintext';
        $token = OpenApiToken::factory()->create([
            'token' => hash('sha256', $oldPlain),
            'token_prefix' => substr($oldPlain, 0, 14),
        ]);

        $newPlain = Livewire::actingAs($this->admin)
            ->test(OpenApiTokenCreateEdit::class, ['token' => $token])
            ->call('regenerate')
            ->get('generated_token');

        $this->assertStringStartsWith('sk-agt-', (string) $newPlain);
        $this->assertNotSame($oldPlain, $newPlain);
        $this->assertSame($token->id, OpenApiToken::findActiveByPlain($newPlain)?->id);
        $this->assertNull(OpenApiToken::findActiveByPlain($oldPlain));
    }

    public function test_delete_removes_token(): void
    {
        $token = OpenApiToken::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(OpenApiTokenIndex::class)
            ->call('showDeleteModal', $token->id)
            ->call('delete');

        $this->assertDatabaseMissing('open_api_tokens', ['id' => $token->id]);
    }
}
