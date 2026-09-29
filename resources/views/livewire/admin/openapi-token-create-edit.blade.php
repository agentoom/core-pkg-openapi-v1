<div class="space-y-6">
    <flux:heading size="xl">{{ $token && $token->exists ? __('openapi-v1::admin.edit_title') : __('openapi-v1::admin.create_title') }}</flux:heading>

    <form wire:submit="submit" class="space-y-6">
        <flux:card class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <flux:input wire:model="name" label="{{ __('agentoom-core::admin.common.name') }}" placeholder="LibreChat integration" />

                <flux:select wire:model="user_id" label="{{ __('openapi-v1::admin.owner') }}" placeholder="{{ __('openapi-v1::admin.select_owner') }}">
                    @foreach($users as $user)
                        <flux:select.option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:textarea
                wire:model="allowed_domains"
                label="{{ __('openapi-v1::admin.allowed_domains') }}"
                placeholder="example.com, *.example.com, 192.168.1.1, 10.0.0.*"
                description="{{ __('openapi-v1::admin.allowed_domains_help') }}" />

            <div class="flex justify-between items-center pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:switch wire:model="is_active" label="{{ __('agentoom-core::admin.common.active') }}" />
            </div>
        </flux:card>

        <div class="flex justify-end gap-3">
            <flux:button :href="route('admin.openapi-tokens.index')" wire:navigate variant="ghost">
                {{ __('agentoom-core::admin.common.cancel') }}
            </flux:button>
            <flux:button variant="primary" type="submit">
                {{ __('agentoom-core::admin.common.save') }}
            </flux:button>
        </div>
    </form>

    @if ($token && $token->exists)
        <flux:card class="border border-red-200 dark:border-red-900/40">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading>{{ __('openapi-v1::admin.danger_zone') }}</flux:heading>
                    <flux:subheading>{{ __('openapi-v1::admin.regenerate_help') }}</flux:subheading>
                </div>
                <flux:button variant="danger" icon="arrow-path" wire:click="regenerate">
                    {{ __('openapi-v1::admin.regenerate') }}
                </flux:button>
            </div>
        </flux:card>
    @endif

    <flux:modal wire:model="show_token_modal" class="max-w-xl">
        <div class="space-y-4">
            <div class="text-center space-y-2">
                <div class="mx-auto flex items-center justify-center size-12 rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                    <flux:icon.key class="size-6 text-emerald-600 dark:text-emerald-400" />
                </div>
                <flux:heading size="lg">
                    {{ $token && $token->exists && $generated_token ? __('openapi-v1::admin.regenerated_title') : __('openapi-v1::admin.generated_title') }}
                </flux:heading>
                <flux:subheading class="text-zinc-500">
                    {{ __('openapi-v1::admin.generated_help') }}
                </flux:subheading>
            </div>

            <div x-data="{ copied: false }" class="space-y-2">
                <div class="flex items-center gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 px-4 py-3">
                    <code class="flex-1 break-all text-sm font-mono">{{ $generated_token }}</code>
                    <flux:button
                        size="sm"
                        variant="primary"
                        icon="clipboard"
                        x-on:click.prevent="
                            navigator.clipboard.writeText({{ Js::from($generated_token ?? '') }});
                            copied = true;
                            setTimeout(() => copied = false, 2000);
                        ">
                        {{ __('openapi-v1::admin.copy') }}
                    </flux:button>
                </div>
                <p x-show="copied" x-transition.opacity class="text-xs text-emerald-600 text-right">
                    {{ __('openapi-v1::admin.copied') }}
                </p>
            </div>

            <div class="flex justify-end pt-2">
                <flux:button variant="primary" wire:click="finish" wire:navigate>
                    {{ __('openapi-v1::admin.done') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
