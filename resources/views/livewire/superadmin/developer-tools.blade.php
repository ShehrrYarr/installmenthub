<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Symfony\Component\Process\Process;

new #[Layout('layouts.super-admin')] class extends Component
{
    public ?string $lastKey = null;

    public ?string $lastOutput = null;

    public ?bool $lastSuccess = null;

    public ?string $lastMeta = null;

    /** @return array<string, array{label: string, description: string, confirm: ?string}> */
    #[Computed]
    public function commands(): array
    {
        return [
            'deploy' => [
                'label' => 'Full Deploy',
                'description' => 'git pull → composer install → npm run build → clear caches',
                'confirm' => 'Pull the latest code, reinstall dependencies, rebuild assets, and clear all caches?',
            ],
            'git-pull' => [
                'label' => 'Git Pull',
                'description' => 'git pull origin master',
                'confirm' => 'Pull the latest commits from origin/master?',
            ],
            'composer-install' => [
                'label' => 'Composer Install',
                'description' => 'composer install --no-dev --optimize-autoloader',
                'confirm' => null,
            ],
            'npm-build' => [
                'label' => 'NPM Build',
                'description' => 'npm run build',
                'confirm' => null,
            ],
            'migrate' => [
                'label' => 'Run Migrations',
                'description' => 'php artisan migrate --force',
                'confirm' => 'Run pending database migrations? This can be irreversible — make sure you have a backup.',
            ],
            'migrate-status' => [
                'label' => 'Migration Status',
                'description' => 'php artisan migrate:status',
                'confirm' => null,
            ],
            'optimize' => [
                'label' => 'Optimize',
                'description' => 'php artisan optimize',
                'confirm' => null,
            ],
            'optimize-clear' => [
                'label' => 'Clear Caches',
                'description' => 'php artisan optimize:clear',
                'confirm' => null,
            ],
        ];
    }

    public function run(string $key): void
    {
        abort_unless(array_key_exists($key, $this->commands()), 404);

        set_time_limit(300);

        $start = microtime(true);

        [$success, $output] = match ($key) {
            'deploy' => $this->runDeploy(),
            'git-pull' => $this->runProcess(['git', 'pull', 'origin', 'master']),
            'composer-install' => $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader'], 300),
            'npm-build' => $this->runProcess(['npm', 'run', 'build'], 300),
            'migrate' => $this->runArtisan('migrate', ['--force' => true]),
            'migrate-status' => $this->runArtisan('migrate:status'),
            'optimize' => $this->runArtisan('optimize'),
            'optimize-clear' => $this->runArtisan('optimize:clear'),
        };

        $duration = round(microtime(true) - $start, 1);

        $this->lastKey = $key;
        $this->lastOutput = trim($output) !== '' ? trim($output) : '(no output)';
        $this->lastSuccess = $success;
        $this->lastMeta = now()->format('Y-m-d H:i:s')." · {$duration}s";

        Log::info('Developer tool executed', [
            'user' => auth()->user()->email,
            'command' => $key,
            'success' => $success,
            'duration_seconds' => $duration,
        ]);
    }

    /** @return array{0: bool, 1: string} */
    private function runDeploy(): array
    {
        $steps = [
            fn () => $this->runProcess(['git', 'pull', 'origin', 'master']),
            fn () => $this->runProcess(['composer', 'install', '--no-dev', '--optimize-autoloader'], 300),
            fn () => $this->runProcess(['npm', 'run', 'build'], 300),
            fn () => $this->runArtisan('optimize:clear'),
        ];

        $combinedOutput = '';

        foreach ($steps as $step) {
            [$stepSuccess, $stepOutput] = $step();
            $combinedOutput .= $stepOutput."\n";

            if (! $stepSuccess) {
                return [false, $combinedOutput];
            }
        }

        return [true, $combinedOutput];
    }

    /** @return array{0: bool, 1: string} */
    private function runProcess(array $command, int $timeout = 120): array
    {
        $process = new Process($command, base_path(), null, null, $timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }

        return [$process->isSuccessful(), $process->getOutput().$process->getErrorOutput()];
    }

    /** @return array{0: bool, 1: string} */
    private function runArtisan(string $command, array $params = []): array
    {
        try {
            $exitCode = Artisan::call($command, $params);

            return [$exitCode === 0, Artisan::output()];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
} ?>

@slot('header')
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Developer Tools</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">Deploy and maintenance commands, run directly on this server</p>
    </div>
@endslot

<div class="space-y-6">
    <div class="rounded-2xl border border-amber-300/60 dark:border-amber-700/40 bg-amber-50/80 dark:bg-amber-950/30 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
        These run for real, right now, on this server. Every run is logged with your account.
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" wire:loading.attr="disabled" wire:target="run">
        @foreach ($this->commands as $key => $command)
            <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 p-4 flex flex-col justify-between gap-3">
                <div>
                    <p class="font-medium text-gray-900 dark:text-white">{{ $command['label'] }}</p>
                    <p class="mt-1 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $command['description'] }}</p>
                </div>

                <button
                    wire:click="run('{{ $key }}')"
                    @if ($command['confirm']) wire:confirm="{{ $command['confirm'] }}" @endif
                    wire:loading.attr="disabled"
                    wire:target="run('{{ $key }}')"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-walnut-600 px-4 py-2 text-sm font-medium text-white hover:bg-walnut-400 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <svg wire:loading wire:target="run('{{ $key }}')" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span wire:loading.remove wire:target="run('{{ $key }}')">Run</span>
                    <span wire:loading wire:target="run('{{ $key }}')">Running…</span>
                </button>
            </div>
        @endforeach
    </div>

    @if ($lastKey !== null)
        <div class="rounded-2xl border border-white/40 dark:border-gray-700/60 bg-white/70 dark:bg-gray-800/60 backdrop-blur-xl shadow-lg shadow-gray-900/5 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 bg-gray-50/80 dark:bg-gray-900/40 border-b border-gray-100 dark:border-gray-800">
                <div class="flex items-center gap-2">
                    @if ($lastSuccess)
                        <span class="inline-flex items-center rounded-full bg-emerald-100 dark:bg-emerald-900/40 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">Success</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-rose-100 dark:bg-rose-900/40 px-2.5 py-0.5 text-xs font-medium text-rose-700 dark:text-rose-300">Failed</span>
                    @endif
                    <span class="font-medium text-gray-900 dark:text-white">{{ $this->commands[$lastKey]['label'] }}</span>
                </div>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $lastMeta }}</span>
            </div>
            <pre class="p-4 text-xs text-gray-100 bg-gray-950 overflow-x-auto max-h-96 overflow-y-auto whitespace-pre-wrap">{{ $lastOutput }}</pre>
        </div>
    @endif
</div>
