<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.super-admin')] class extends Component
{
    public Shop $shop;

    /** @var array<int, string> User id => freshly-generated password, visible only for this page load. */
    public array $revealedPasswords = [];

    public function mount(Shop $shop): void
    {
        $this->shop = $shop;
    }

    public function staff()
    {
        return $this->shop->users()->with('roles')->orderBy('name')->get();
    }

    public function resetPassword(int $userId): void
    {
        $user = $this->shop->users()->findOrFail($userId);

        $newPassword = Str::password(12);
        $user->update(['password' => $newPassword]); // hashed via the User model's cast

        $this->revealedPasswords[$userId] = $newPassword;
    }

    public function toggleActive(int $userId): void
    {
        $user = $this->shop->users()->findOrFail($userId);
        $user->update(['is_active' => ! $user->is_active]);
    }
} ?>

@slot('header')
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $shop->name }} — Staff</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">Reset passwords or deactivate accounts for this shop's team</p>
        </div>
        <a href="{{ route('superadmin.shops.index') }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">Back to Shops</a>
    </div>
@endslot

<div>
    <div class="rounded-2xl border border-amber-300 dark:border-amber-900/60 bg-amber-50 dark:bg-amber-950/30 p-4 text-sm text-amber-800 dark:text-amber-200 mb-6">
        A reset password is shown <strong>once</strong>, right here, immediately after you generate it. It is never stored in
        plain text and cannot be recovered after you leave this page — reset again if it's lost.
    </div>

    <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/80">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Name</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Email</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Role</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 dark:text-gray-400">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->staff() as $user)
                        <tr>
                            <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $user->name }}</td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $user->email }}</td>
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $user->roles->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="px-4 py-3"><x-status-badge :status="$user->is_active ? 'active' : 'suspended'" /></td>
                            <td class="px-4 py-3 text-right space-x-3">
                                <button wire:click="resetPassword({{ $user->id }})" wire:confirm="Generate a new password for {{ $user->name }}? Their current password stops working immediately."
                                    class="text-walnut-400 hover:text-walnut-200 font-medium">
                                    Reset Password
                                </button>
                                <button wire:click="toggleActive({{ $user->id }})" wire:confirm="{{ $user->is_active ? 'Deactivate' : 'Reactivate' }} {{ $user->name }}?"
                                    class="{{ $user->is_active ? 'text-rose-400 hover:text-rose-300' : 'text-emerald-400 hover:text-emerald-300' }} font-medium">
                                    {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            </td>
                        </tr>
                        @if (isset($revealedPasswords[$user->id]))
                            <tr>
                                <td colspan="5" class="px-4 pb-4">
                                    <div x-data="{ copied: false }" class="flex items-center gap-3 rounded-xl bg-emerald-950/40 border border-emerald-900/60 px-4 py-3">
                                        <span class="text-xs text-emerald-300">New password for {{ $user->name }}:</span>
                                        <code class="font-mono text-emerald-200 select-all">{{ $revealedPasswords[$user->id] }}</code>
                                        <button type="button"
                                            @click="navigator.clipboard.writeText('{{ $revealedPasswords[$user->id] }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                            class="ml-auto text-xs text-emerald-300 hover:text-emerald-100">
                                            <span x-show="!copied">Copy</span>
                                            <span x-show="copied" x-cloak>Copied!</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No staff accounts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
