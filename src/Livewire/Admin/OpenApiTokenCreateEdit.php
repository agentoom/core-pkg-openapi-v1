<?php

namespace Agentoom\OpenApiV1\Livewire\Admin;

use Agentoom\OpenApiV1\Models\OpenApiToken;
use Illuminate\Validation\Rule;
use Livewire\Component;

class OpenApiTokenCreateEdit extends Component
{
    public ?OpenApiToken $token = null;

    public string $name = '';

    public ?int $user_id = null;

    public string $allowed_domains = '';

    public bool $is_active = true;

    public ?string $generated_token = null;

    public bool $show_token_modal = false;

    public function mount(?OpenApiToken $token = null): void
    {
        $this->token = $token;

        if ($token && $token->exists) {
            $this->name = $token->name;
            $this->user_id = $token->user_id;
            $this->allowed_domains = (string) $token->allowed_domains;
            $this->is_active = $token->is_active;
        } else {
            $this->user_id = auth()->id();
        }
    }

    public function submit(): mixed
    {
        $assignableUserIds = auth()->user()->getAssignableUsers()->pluck('id')->all();

        $this->validate([
            'name' => 'required|string|max:255',
            'user_id' => ['required', 'integer', Rule::in($assignableUserIds)],
            'allowed_domains' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($this->token && $this->token->exists) {
            $this->token->update([
                'name' => $this->name,
                'user_id' => $this->user_id,
                'allowed_domains' => $this->allowed_domains ?: null,
                'is_active' => $this->is_active,
            ]);

            \Flux::toast(__('openapi-v1::admin.updated_success'));

            return $this->redirect(route('admin.openapi-tokens.index'), navigate: true);
        }

        [$token, $plain] = OpenApiToken::issue(
            $this->user_id,
            $this->name,
            $this->allowed_domains ?: null,
            $this->is_active,
        );

        $this->token = $token;
        $this->generated_token = $plain;
        $this->show_token_modal = true;

        \Flux::toast(__('openapi-v1::admin.created_success'));

        return null;
    }

    public function regenerate(): void
    {
        if (! ($this->token && $this->token->exists)) {
            return;
        }

        $this->generated_token = $this->token->regenerate();
        $this->show_token_modal = true;
    }

    public function finish(): mixed
    {
        return $this->redirect(route('admin.openapi-tokens.index'), navigate: true);
    }

    public function render()
    {
        return view('openapi-v1::livewire.admin.openapi-token-create-edit', [
            'users' => auth()->user()->getAssignableUsers(),
        ])->layout('agentoom-core::layouts.admin');
    }
}
