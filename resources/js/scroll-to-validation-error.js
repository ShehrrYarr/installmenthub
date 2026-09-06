// After any Livewire update that leaves a validation error on the page (a
// failed `$this->validate()` or `$this->addError(...)`), scroll to the first
// one — the user may be scrolled somewhere else in a long form (or the
// mobile bottom nav may be covering it) and have no idea why nothing
// happened. Generic and app-wide: every <x-input-error> in the app renders
// its `data-validation-error` marker only when it actually has a message, so
// this needs no per-form wiring.
function registerHook() {
    Livewire.hook('morphed', ({ el }) => {
        const error = el.querySelector('[data-validation-error]');

        if (! error) return;

        // Wait a tick: `morphed` fires the instant the DOM is patched, before
        // the browser has laid out/painted the change. Scrolling immediately
        // either measures stale geometry or gets fought by the browser's own
        // scroll-anchoring once it does lay out — both leave the page right
        // where it was.
        setTimeout(() => {
            error.scrollIntoView({ behavior: 'smooth', block: 'center' });

            const target = error.closest('div') ?? error;

            target.classList.add('validation-error-flash');
            setTimeout(() => target.classList.remove('validation-error-flash'), 1600);
        }, 0);
    });
}

// app.js is a deferred ES module, so by the time it runs, window.Livewire
// already exists (Livewire's own script is classic/non-deferred and runs
// earlier) — register straight away. The event listener is just a fallback
// for any load order where that isn't true yet.
if (window.Livewire) {
    registerHook();
} else {
    document.addEventListener('livewire:init', registerHook);
}
