// Powers the "your internet seems slow" hint on wire:model.live search boxes
// (see resources/views/components/search-input.blade.php). Livewire's own
// wire:loading.delay only offers presets up to 1000ms, so getting a precise
// 4-second threshold needs a plain timer around Livewire's request hook.
// updatedProps lets each search box only react to *its own* property changing,
// so an unrelated filter/pagination request on the same page doesn't light up
// the search box's spinner or slow-network message.
document.addEventListener('livewire:init', () => {
    let slowTimer = null;

    Livewire.hook('request', ({ payload, respond }) => {
        let updatedProps = [];

        try {
            const body = JSON.parse(payload);
            (body.components || []).forEach((component) => {
                updatedProps.push(...Object.keys(component.updates || {}));
            });
        } catch (e) {
            // Malformed payload — fall through with no props; the slow message just won't fire.
        }

        clearTimeout(slowTimer);

        slowTimer = setTimeout(() => {
            window.dispatchEvent(new CustomEvent('search-loading-slow', { detail: { props: updatedProps } }));
        }, 4000);

        respond(() => {
            clearTimeout(slowTimer);
            window.dispatchEvent(new CustomEvent('search-loading-end', { detail: { props: updatedProps } }));
        });
    });
});
