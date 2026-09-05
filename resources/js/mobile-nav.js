// Drives the mobile bottom nav bar, which lives inside a Livewire @persist
// block so the same DOM node survives every wire:navigate page swap instead
// of being rebuilt. That solves most of the "state carries over" problem for
// free, but two things still need manual handling on every navigation:
//
// 1. The "active" tab highlight can't be computed server-side inside a
//    persisted block (Livewire never re-renders its contents after the first
//    paint), so it's recalculated here from the URL instead.
// 2. Browsers reset a scrollable element's scrollLeft when it's detached and
//    reattached to the document during the page swap, even though it's the
//    same node — so the position is captured right before the swap
//    (livewire:navigating) and reapplied right after (livewire:navigated).
//    A passive 'scroll' listener isn't enough on its own: a programmatic
//    scrollLeft change doesn't reliably fire a 'scroll' event, so capturing
//    at navigation time is what actually makes this robust.
function highlightActiveLink() {
    const nav = document.querySelector('[data-mobile-nav]');

    if (! nav) return;

    const path = window.location.pathname;

    nav.querySelectorAll('a[data-href]').forEach((link) => {
        const href = new URL(link.dataset.href, window.location.origin).pathname;
        const activePrefix = link.dataset.activePrefix
            ? new URL(link.dataset.activePrefix, window.location.origin).pathname
            : null;
        const isActive = link.dataset.exact === '1'
            ? path === href || (activePrefix && path.startsWith(activePrefix))
            : path === href || path.startsWith(href + '/');

        link.classList.toggle('text-[var(--theme-accent)]', isActive);
        link.classList.toggle('text-gray-500', ! isActive);
    });
}

function saveScrollPosition() {
    const scroller = document.querySelector('[data-mobile-nav-scroller]');

    if (scroller) {
        scroller.dataset.savedScroll = String(scroller.scrollLeft);
    }
}

function restoreScrollPosition() {
    const scroller = document.querySelector('[data-mobile-nav-scroller]');

    if (scroller && scroller.dataset.savedScroll) {
        scroller.scrollLeft = parseFloat(scroller.dataset.savedScroll);
    }
}

document.addEventListener('DOMContentLoaded', highlightActiveLink);
document.addEventListener('livewire:navigating', saveScrollPosition);
document.addEventListener('livewire:navigated', () => {
    highlightActiveLink();
    restoreScrollPosition();
});
