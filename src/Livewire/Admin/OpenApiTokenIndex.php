<?php

namespace Agentoom\OpenApiV1\Livewire\Admin;

use Agentoom\OpenApiV1\Models\OpenApiToken;
use Livewire\Component;
use Livewire\WithPagination;

class OpenApiTokenIndex extends Component
{
    use WithPagination;

    public bool $show_delete_modal = false;

    public ?int $token_id_to_delete = null;

    public function showDeleteModal(int $tokenId): void
    {
        $this->token_id_to_delete = $tokenId;
        $this->show_delete_modal = true;
    }

    public function delete(): void
    {
        if ($this->token_id_to_delete) {
            OpenApiToken::find($this->token_id_to_delete)?->delete();
            \Flux::toast(__('openapi-v1::admin.deleted_success'));
        }

        $this->show_delete_modal = false;
        $this->token_id_to_delete = null;
    }

    public function render()
    {
        return view('openapi-v1::livewire.admin.openapi-token-index', [
            'tokens' => OpenApiToken::query()->with('user')->orderBy('id', 'desc')->paginate(15),
        ])->layout('agentoom-core::layouts.admin');
    }
}
