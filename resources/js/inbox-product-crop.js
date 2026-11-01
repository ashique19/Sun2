import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

const registerInboxProductCrop = () => {
    Alpine.data('inboxProductCrop', (imageUrl, suggestionUrl = null) => ({
        cropper: null,
        busy: false,
        error: null,
        imageUrl,
        suggestionUrl,
        suggestion: null,
        suggestionApplied: false,

        init() {
            this.$nextTick(() => this.boot());
        },

        boot() {
            const image = this.$refs.cropImage;
            if (! image) {
                return;
            }

            const start = () => {
                this.initCropper();
                this.loadSuggestion();
            };

            if (image.complete && image.naturalWidth > 0) {
                start();
            } else {
                image.onload = start;
            }
        },

        initCropper() {
            this.destroyCropper();
            const image = this.$refs.cropImage;
            if (! image) {
                return;
            }

            this.cropper = new Cropper(image, {
                viewMode: 1,
                dragMode: 'crop',
                cropBoxMovable: true,
                cropBoxResizable: true,
                autoCropArea: 0.85,
                responsive: true,
                background: false,
                // Keep Cropper inside the fixed-height wrapper so tall chat
                // images cannot blow the product modal past the viewport.
                checkOrientation: false,
                cropstart: (event) => this.holdPointer(event),
                ready: () => {
                    this.safeResize();
                    this.applySuggestionIfReady();
                },
            });

            this.$nextTick(() => {
                this.safeResize();
                this.applySuggestionIfReady();
            });
        },

        async loadSuggestion() {
            if (! this.suggestionUrl) {
                return;
            }

            try {
                const response = await fetch(this.suggestionUrl, {
                    headers: {
                        Accept: 'application/json',
                    },
                    credentials: 'same-origin',
                });

                if (! response.ok) {
                    return;
                }

                const payload = await response.json();
                this.suggestion = payload?.suggestion ?? null;
                this.applySuggestionIfReady();
            } catch {
                // Staff can still crop manually when auto-detect is unavailable.
            }
        },

        applySuggestionIfReady() {
            if (! this.cropper || ! this.suggestion || this.suggestionApplied) {
                return;
            }

            const image = this.$refs.cropImage;
            if (! image?.naturalWidth || ! image?.naturalHeight) {
                return;
            }

            // A near-full-frame box sits on the overflow:hidden edge, so drag/resize
            // handles are unusable (viewMode 1 also refuses to move it).
            if (Number(this.suggestion.width) >= 0.92 && Number(this.suggestion.height) >= 0.92) {
                this.suggestionApplied = true;

                return;
            }

            this.cropper.setData({
                x: this.suggestion.left * image.naturalWidth,
                y: this.suggestion.top * image.naturalHeight,
                width: this.suggestion.width * image.naturalWidth,
                height: this.suggestion.height * image.naturalHeight,
            });

            this.suggestionApplied = true;
        },

        applyAutoCrop() {
            if (! this.suggestion) {
                return;
            }

            this.suggestionApplied = false;
            this.applySuggestionIfReady();
        },

        destroyCropper() {
            if (this.cropper) {
                this.cropper.destroy();
                this.cropper = null;
            }
        },

        safeResize() {
            try {
                this.cropper?.resize();
            } catch {
                // Cropper.ready can fire while Livewire is remorphing the modal.
            }
        },

        holdPointer(event) {
            const original = event?.originalEvent;
            if (! original?.target?.setPointerCapture || original.pointerId == null) {
                return;
            }

            try {
                original.target.setPointerCapture(original.pointerId);
            } catch {
                // Capture is best-effort; Cropper still listens on document.
            }
        },

        rotate(degrees) {
            this.cropper?.rotate(degrees);
        },

        resetCrop() {
            this.cropper?.reset();
            this.suggestionApplied = false;
        },

        async findMatch() {
            if (! this.cropper || this.busy) {
                return;
            }

            this.busy = true;
            this.error = null;

            try {
                const data = this.cropper.getData(true);
                const image = this.$refs.cropImage;
                const rotated = Math.round(((data.rotate ?? 0) % 360 + 360) % 360) !== 0;

                // Unrotated: match the box on the original server-side (no upload; the
                // region is also learned when staff tag the result).
                if (! rotated && image?.naturalWidth && image?.naturalHeight) {
                    await this.$wire.matchProductFromCropRegion({
                        left: data.x / image.naturalWidth,
                        top: data.y / image.naturalHeight,
                        width: data.width / image.naturalWidth,
                        height: data.height / image.naturalHeight,
                    });

                    return;
                }

                const canvas = this.cropper.getCroppedCanvas({
                    maxWidth: 1600,
                    maxHeight: 1600,
                    fillColor: '#ffffff',
                });

                if (! canvas) {
                    throw new Error('Could not crop image.');
                }

                const blob = await new Promise((resolve) => {
                    canvas.toBlob(resolve, 'image/jpeg', 0.92);
                });

                if (! blob) {
                    throw new Error('Could not encode cropped image.');
                }

                const file = new File([blob], 'inbox-crop.jpg', { type: 'image/jpeg' });

                await new Promise((resolve, reject) => {
                    this.$wire.upload(
                        'mappingCroppedImage',
                        file,
                        () => resolve(),
                        () => reject(new Error('Upload failed.')),
                        () => {},
                    );
                });

                await this.$wire.matchProductFromCroppedImage();
            } catch (e) {
                this.error = e?.message || 'Crop match failed.';
            } finally {
                this.busy = false;
            }
        },

        destroy() {
            this.destroyCropper();
        },
    }));
};

if (window.Alpine) {
    registerInboxProductCrop();
} else {
    document.addEventListener('alpine:init', registerInboxProductCrop);
}
