<?php

use App\Models\User;
use App\Support\ShopPermissions;
use App\Support\Tenant;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.tenant')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingUserId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = 'Salesman';

    /** @var list<string> */
    public array $permissions = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->hasRole('Shop Admin'), 403);
    }

    public function staff()
    {
        return Tenant::current()->users()
            ->with(['roles', 'permissions'])
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Manager', 'Salesman']))
            ->orderBy('name')
            ->get();
    }

    public function updatedRole(): void
    {
        // Only re-apply role defaults while adding — editing an existing
        // staff member shouldn't silently reset permissions they were
        // deliberately given a different value for.
        if (! $this->editingUserId) {
            $this->permissions = ShopPermissions::defaultsForRole($this->role);
        }
    }

    public function startCreate(): void
    {
        $this->reset(['editingUserId', 'name', 'email', 'password']);
        $this->role = 'Salesman';
        $this->permissions = ShopPermissions::defaultsForRole('Salesman');
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $userId): void
    {
        $user = Tenant::current()->users()->with('roles', 'permissions')->findOrFail($userId);

        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->role = $user->roles->first()?->name ?? 'Salesman';
        $this->permissions = $user->permissions->pluck('name')->intersect(ShopPermissions::TOGGLEABLE)->values()->toArray();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->reset(['editingUserId', 'name', 'email', 'password', 'role', 'permissions']);
    }

    public function save(): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingUserId)],
            'role' => 'required|in:Manager,Salesman',
            'permissions' => 'array',
            'permissions.*' => Rule::in(ShopPermissions::TOGGLEABLE),
        ];

        $rules['password'] = $this->editingUserId ? 'nullable|string|min:8' : 'required|string|min:8';

        $this->validate($rules);

        $shop = Tenant::current();

        if ($this->editingUserId) {
            $user = $shop->users()->findOrFail($this->editingUserId);
            $user->update(array_filter([
                'name' => $this->name,
                'email' => $this->email,
                'password' => $this->password !== '' ? $this->password : null,
            ], fn ($value) => $value !== null));
        } else {
            $user = User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => $this->password,
                'shop_id' => $shop->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        }

        $user->syncRoles([$this->role]);
        $user->syncPermissions($this->permissions);

        session()->flash('status', $this->editingUserId ? 'Staff member updated.' : 'Staff member added.');

        $this->showForm = false;
        $this->reset(['editingUserId', 'name', 'email', 'password', 'role', 'permissions']);
    }

    public function toggleActive(int $userId): void
    {
        $user = Tenant::current()->users()->findOrFail($userId);
        $user->update(['is_active' => ! $user->is_active]);
    }
} ?>

@slot('header')
    <h1 class="text-xl font-semibold" style="color: var(--theme-bg-text)">Settings</h1>
    <p class="text-sm opacity-70" style="color: var(--theme-bg-text)">Manage this shop's team and their access</p>
@endslot

<div>
    {{-- Settings sub-nav --}}
    <div class="flex gap-1 border-b border-black/10 mb-6">
        <a href="{{ route('tenant.settings.appearance') }}" wire:navigate
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">
            <x-tenant-icon name="swatch" class="h-4 w-4" />
            Appearance
        </a>
        <div class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2"
             style="color: var(--theme-accent); border-color: var(--theme-accent)">
            <x-tenant-icon name="users" class="h-4 w-4" />
            Staff
        </div>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex items-center justify-between mb-4">
        <div>
            <h3 class="font-semibold text-gray-900">Managers & Salesmen</h3>
            <p class="text-sm text-gray-500">Add staff and control what each of them can access.</p>
        </div>
        @unless ($showForm)
            <button type="button" wire:click="startCreate"
                    class="inline-flex items-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
                + Add Staff
            </button>
        @endunless
    </div>

    @if ($showForm)
        <form wire:submit="save" class="rounded-2xl border border-white/40 bg-white/70 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-5 space-y-4 mb-6">
            <h4 class="font-semibold text-gray-900">{{ $editingUserId ? 'Edit Staff Member' : 'New Staff Member' }}</h4>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="name" value="Full Name" />
                    <x-text-input id="name" wire:model="name" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" type="email" wire:model="email" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="role" value="Role" />
                    <select id="role" wire:model.live="role" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-walnut-400 focus:ring-walnut-400">
                        <option value="Salesman">Salesman</option>
                        <option value="Manager">Manager</option>
                    </select>
                    <x-input-error :messages="$errors->get('role')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="password" :value="$editingUserId ? 'New Password (optional)' : 'Password'" />
                    <x-text-input id="password" type="password" wire:model="password" class="mt-1 block w-full" placeholder="{{ $editingUserId ? 'Leave blank to keep current password' : '' }}" />
                    <x-input-error :messages="$errors->get('password')" class="mt-1" />
                </div>
            </div>

            <div>
                <x-input-label value="Access" />
                <p class="text-xs text-gray-500 mb-2">These areas aren't included by default for a Salesman — check any this person should also have.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach (ShopPermissions::definitions() as $key => $permission)
                        <label class="flex items-start gap-2 rounded-lg border border-gray-200 p-3 cursor-pointer">
                            <input type="checkbox" wire:model="permissions" value="{{ $key }}" class="mt-0.5 rounded border-gray-300 text-walnut-600 focus:ring-walnut-400">
                            <span>
                                <span class="block text-sm font-medium text-gray-900">{{ $permission['label'] }}</span>
                                <span class="block text-xs text-gray-500">{{ $permission['description'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400">
                    {{ $editingUserId ? 'Save Changes' : 'Add Staff' }}
                </button>
                <button type="button" wire:click="cancelForm" class="text-sm text-gray-500 hover:text-gray-700">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    <div class="rounded-2xl border border-white/40 bg-white/70 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Name</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Email</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Role</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Access</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($this->staff() as $user)
                        <tr>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $user->name }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $user->email }}</td>
                            <td class="px-4 py-3 text-gray-500">{{ $user->roles->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="px-4 py-3 text-gray-500">
                                {{ $user->permissions->pluck('name')->intersect(\App\Support\ShopPermissions::TOGGLEABLE)->map(fn ($p) => \App\Support\ShopPermissions::definitions()[$p]['label'])->join(', ') ?: '—' }}
                            </td>
                            <td class="px-4 py-3"><x-status-badge :status="$user->is_active ? 'active' : 'suspended'" /></td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                <button wire:click="edit({{ $user->id }})" class="text-walnut-400 hover:text-walnut-600 font-medium">
                                    Edit
                                </button>
                                <button wire:click="toggleActive({{ $user->id }})" wire:confirm="{{ $user->is_active ? 'Deactivate' : 'Reactivate' }} {{ $user->name }}?"
                                    class="{{ $user->is_active ? 'text-rose-500 hover:text-rose-700' : 'text-emerald-500 hover:text-emerald-700' }} font-medium">
                                    {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No Managers or Salesmen yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
