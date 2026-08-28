<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-[var(--theme-accent,theme(colors.walnut.600))] border border-transparent rounded-md font-semibold text-xs text-[var(--theme-accent-text,white)] uppercase tracking-widest hover:opacity-90 focus:opacity-90 active:opacity-80 focus:outline-none focus:ring-2 focus:ring-[var(--theme-accent,theme(colors.walnut.400))] focus:ring-offset-2 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
