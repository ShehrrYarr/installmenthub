// A floating notice, top centre, for when the network is struggling.
//
// Two states, offline winning over slow:
//   slow    — a Livewire action or a wire:navigate page change has been in
//             flight for longer than SLOW_AFTER_MS.
//   offline — the browser reports no connection, or a request failed before
//             it got a reply. "Slow" reads as reassurance; if nothing is
//             coming at all, say so instead.
//
// Built and styled in JS rather than blade so it covers every layout — staff,
// customer portal, super admin and the login pages — without each one having
// to include it, and so its appearance can't be broken by an unbuilt CSS class.
//
// Search boxes keep their own inline hint (see search-input.blade.php); this
// sits alongside it deliberately.

const SLOW_AFTER_MS = 4000;

// Once it's up, keep it up long enough to actually read. Without this a
// request finishing just past the threshold flashes the notice for a
// fraction of a second, which is worse than not showing it at all.
const MIN_VISIBLE_MS = 6000;

const MESSAGES = {
    slow: {
        text: 'Your internet seems slow, please wait…',
        background: '#B45309', // amber-700
    },
    offline: {
        text: 'You appear to be offline. Check your connection.',
        background: '#BE123C', // rose-700
    },
};

let pill = null;
let keyframes = null;
let pending = 0;
let slowTimer = null;
let hideTimer = null;
let shownAt = null;
let state = null;

function element() {
    // wire:navigate swaps the whole body, so a pill built on an earlier page
    // ends up detached. Re-attach rather than rebuild, and do the same for the
    // spinner keyframes in case the head went with it.
    if (pill) {
        if (! pill.isConnected) {
            document.body.appendChild(pill);
        }

        if (keyframes && ! keyframes.isConnected) {
            document.head.appendChild(keyframes);
        }

        return pill;
    }

    pill = document.createElement('div');
    pill.setAttribute('role', 'status');
    pill.setAttribute('aria-live', 'polite');
    Object.assign(pill.style, {
        position: 'fixed',
        top: '1rem',
        left: '50%',
        zIndex: '2147483000',
        display: 'flex',
        alignItems: 'center',
        gap: '0.5rem',
        maxWidth: 'calc(100vw - 2rem)',
        padding: '0.5rem 1rem',
        borderRadius: '9999px',
        color: '#fff',
        font: '500 13px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif',
        boxShadow: '0 10px 25px -5px rgba(0,0,0,.35)',
        pointerEvents: 'none',
        opacity: '0',
        transform: 'translate(-50%, -12px)',
        transition: 'opacity .2s ease, transform .2s ease',
    });

    const spinner = document.createElement('span');
    Object.assign(spinner.style, {
        width: '12px',
        height: '12px',
        flex: '0 0 auto',
        border: '2px solid rgba(255,255,255,.45)',
        borderTopColor: '#fff',
        borderRadius: '50%',
        animation: 'connection-status-spin 0.7s linear infinite',
    });

    const label = document.createElement('span');
    label.dataset.role = 'label';

    pill.append(spinner, label);

    keyframes = document.createElement('style');
    keyframes.textContent = '@keyframes connection-status-spin{to{transform:rotate(360deg)}}';
    document.head.appendChild(keyframes);
    document.body.appendChild(pill);

    return pill;
}

function render() {
    // Offline is the more useful thing to say, so it wins while both apply.
    const next = isOffline ? 'offline' : (slowShowing ? 'slow' : null);

    if (next === state) {
        return;
    }

    state = next;
    const node = element();

    if (! state) {
        node.style.opacity = '0';
        node.style.transform = 'translate(-50%, -12px)';

        return;
    }

    node.querySelector('[data-role="label"]').textContent = MESSAGES[state].text;
    node.style.background = MESSAGES[state].background;
    node.style.opacity = '1';
    node.style.transform = 'translate(-50%, 0)';
}

let isOffline = ! navigator.onLine;
let slowShowing = false;

function startWork() {
    pending += 1;
    clearTimeout(hideTimer);
    hideTimer = null;

    if (! slowTimer) {
        slowTimer = setTimeout(() => {
            slowShowing = true;
            shownAt = Date.now();
            render();
        }, SLOW_AFTER_MS);
    }
}

function endWork() {
    pending = Math.max(0, pending - 1);

    // Only stand down once nothing is still in flight — one quick request
    // finishing shouldn't clear the notice while a slow one is still going.
    if (pending !== 0) {
        return;
    }

    clearTimeout(slowTimer);
    slowTimer = null;

    if (! slowShowing) {
        render();

        return;
    }

    // Serve out the rest of the minimum display time before standing down.
    const remaining = Math.max(0, MIN_VISIBLE_MS - (Date.now() - shownAt));

    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => {
        slowShowing = false;
        shownAt = null;
        hideTimer = null;
        render();
    }, remaining);
}

function goOffline() {
    isOffline = true;
    render();
}

function goOnline() {
    isOffline = false;
    render();
}

window.addEventListener('offline', goOffline);
window.addEventListener('online', goOnline);

// wire:navigate page changes. `navigated` fires on arrival; `pagehide` covers
// a real browser navigation away, so the notice never outlives the page.
document.addEventListener('livewire:navigate', startWork);
document.addEventListener('livewire:navigated', () => {
    element();
    endWork();
});
window.addEventListener('pagehide', () => {
    pending = 0;
    endWork();
});

document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ respond, fail }) => {
        startWork();

        // On an HTTP error Livewire runs both respond and fail, so guard
        // against decrementing the in-flight count twice for one request.
        let settled = false;
        const done = () => {
            if (settled) {
                return;
            }

            settled = true;
            endWork();
        };

        respond(() => done());

        fail(({ status, content }) => {
            // Livewire reports a fetch that never reached the server as
            // status 503 with no content — a real 503 carries a body.
            if (! status || (status === 503 && content === null)) {
                goOffline();
            }

            done();
        });
    });
});

if (isOffline) {
    document.addEventListener('DOMContentLoaded', render);
}
