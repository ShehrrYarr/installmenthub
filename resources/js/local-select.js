document.addEventListener('alpine:init', () => {
    // A browsable + searchable dropdown over a small list already loaded on
    // the page (e.g. one product's in-stock serial numbers) — unlike
    // search-select.js, this never round-trips to the server: the full
    // option list shows immediately on focus, and typing just filters it
    // client-side. See resources/views/components/local-select.blade.php.
    Alpine.data('localSelect', ({ options, model, initialLabel = '' }) => ({
        query: initialLabel,
        options,
        open: false,
        highlighted: -1,

        get filtered() {
            if (!this.query) return this.options;

            const needle = this.query.toLowerCase();

            return this.options.filter((item) => item.label.toLowerCase().includes(needle));
        },

        openList() {
            this.open = this.options.length > 0;
            this.highlighted = -1;
        },

        select(item) {
            this.$wire.set(model, item.id);
            this.query = item.label;
            this.open = false;
            this.highlighted = -1;
        },

        clear() {
            this.$wire.set(model, null);
            this.query = '';
            this.open = false;
            this.highlighted = -1;
        },

        moveDown() {
            if (!this.open) {
                this.openList();
                return;
            }
            this.highlighted = Math.min(this.highlighted + 1, this.filtered.length - 1);
        },

        moveUp() {
            if (!this.open) return;
            this.highlighted = Math.max(this.highlighted - 1, 0);
        },

        chooseHighlighted() {
            if (this.highlighted >= 0 && this.filtered[this.highlighted]) {
                this.select(this.filtered[this.highlighted]);
            }
        },
    }));
});
