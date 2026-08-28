document.addEventListener('alpine:init', () => {
    Alpine.data('mediaDropzone', ({ maxFiles = 5, maxSizeMb = 4, existingCount = 0 }) => ({
        maxFiles,
        maxSizeMb,
        existingCount,
        isDragging: false,
        isProcessing: false,
        error: null,
        previews: [],
        dispatchingSynthetic: false,

        get remainingSlots() {
            return Math.max(0, this.maxFiles - this.existingCount - this.previews.length);
        },

        get isFull() {
            return this.remainingSlots <= 0;
        },

        onDrop(event) {
            this.isDragging = false;
            this.handleFiles(event.dataTransfer.files);
        },

        onPick(event) {
            // Skip the synthetic 'change' we dispatch ourselves after compressing —
            // otherwise handleFiles() would re-run on its own already-processed output.
            if (this.dispatchingSynthetic) {
                this.dispatchingSynthetic = false;
                return;
            }

            this.handleFiles(event.target.files);
        },

        async handleFiles(fileList) {
            this.error = null;
            const incoming = Array.from(fileList ?? []);

            if (incoming.length === 0) {
                return;
            }

            if (this.existingCount + this.previews.length + incoming.length > this.maxFiles) {
                this.error = `You can upload up to ${this.maxFiles} files (${this.remainingSlots} slot(s) left).`;
                return;
            }

            this.isProcessing = true;
            const processed = [];

            for (const file of incoming) {
                try {
                    const finalFile = file.type.startsWith('image/')
                        ? await this.compressImage(file)
                        : file;

                    if (finalFile.size > this.maxSizeMb * 1024 * 1024) {
                        this.error = `"${file.name}" exceeds the ${this.maxSizeMb}MB limit.`;
                        continue;
                    }

                    processed.push(finalFile);
                    this.previews.push({
                        name: finalFile.name,
                        url: URL.createObjectURL(finalFile),
                        size: finalFile.size,
                    });
                } catch (e) {
                    this.error = `Could not process "${file.name}".`;
                }
            }

            this.isProcessing = false;

            if (processed.length === 0) {
                return;
            }

            const dataTransfer = new DataTransfer();
            processed.forEach((file) => dataTransfer.items.add(file));

            this.dispatchingSynthetic = true;
            this.$refs.input.files = dataTransfer.files;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },

        compressImage(file, maxDimension = 1600, quality = 0.82) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                const reader = new FileReader();

                reader.onerror = reject;
                reader.onload = (e) => { img.src = e.target.result; };

                img.onerror = reject;
                img.onload = () => {
                    let { width, height } = img;
                    const withinBounds = width <= maxDimension && height <= maxDimension;
                    const withinSize = file.size <= this.maxSizeMb * 1024 * 1024;

                    if (withinBounds && withinSize) {
                        resolve(file);
                        return;
                    }

                    const scale = Math.min(1, maxDimension / Math.max(width, height));
                    width = Math.round(width * scale);
                    height = Math.round(height * scale);

                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    canvas.getContext('2d').drawImage(img, 0, 0, width, height);

                    canvas.toBlob((blob) => {
                        if (! blob) {
                            resolve(file);
                            return;
                        }

                        resolve(new File([blob], file.name, { type: 'image/jpeg' }));
                    }, 'image/jpeg', quality);
                };

                reader.readAsDataURL(file);
            });
        },

        formatSize(bytes) {
            return bytes > 1024 * 1024
                ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
                : `${Math.round(bytes / 1024)} KB`;
        },
    }));
});
