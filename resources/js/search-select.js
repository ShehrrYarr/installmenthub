document.addEventListener('alpine:init', () => {
    // A searchable replacement for a plain <select> that looks up matches
    // server-side (via the given Livewire method) as the user types, instead
    // of rendering every possible option up front. See
    // resources/views/components/search-select.blade.php for the markup.
    Alpine.data('searchSelect', ({ searchMethod, model, minChars = 2 }) => ({
        query: '',
        results: [],
        open: false,
        loading: false,
        highlighted: -1,
        debounceTimer: null,
        minChars,

        runSearch() {
            clearTimeout(this.debounceTimer);
            this.highlighted = -1;

            if (this.query.length < this.minChars) {
                this.results = [];
                this.open = false;
                this.loading = false;
                return;
            }

            this.debounceTimer = setTimeout(() => {
                this.loading = true;
                this.$wire.call(searchMethod, this.query).then((results) => {
                    this.results = results;
                    this.open = true;
                    this.loading = false;
                });
            }, 250);
        },

        select(item) {
            this.$wire.set(model, item.id);
            this.query = item.label;
            this.open = false;
            this.results = [];
            this.highlighted = -1;
        },

        clear() {
            this.$wire.set(model, null);
            this.query = '';
            this.results = [];
            this.open = false;
            this.highlighted = -1;
        },

        moveDown() {
            if (!this.open || !this.results.length) return;
            this.highlighted = Math.min(this.highlighted + 1, this.results.length - 1);
        },

        moveUp() {
            if (!this.open || !this.results.length) return;
            this.highlighted = Math.max(this.highlighted - 1, 0);
        },

        chooseHighlighted() {
            if (this.highlighted >= 0 && this.results[this.highlighted]) {
                this.select(this.results[this.highlighted]);
            }
        },
    }));
});
