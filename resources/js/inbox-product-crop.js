import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

const registerInboxProductCrop = () => {
    Alpine.data('inboxProductCrop', (imageUrl) => ({
        cropper: null,
        busy: false,
        error: null,
        imageUrl,

        init() {
            this.$nextTick(() => this.boot());
        },

        boot() {
            const image = this.$refs.cropImage;
            if (! image) {
                return;
            }

            const start = () => this.initCropper();
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
                dragMode: 'move',
                autoCropArea: 0.85,
                responsive: true,
                background: false,
            });
        },

        destroyCropper() {
            if (this.cropper) {
                this.cropper.destroy();
                this.cropper = null;
            }
        },

        rotate(degrees) {
            this.cropper?.rotate(degrees);
        },

        resetCrop() {
            this.cropper?.reset();
        },

        async findMatch() {
            if (! this.cropper || this.busy) {
                return;
            }

            this.busy = true;
            this.error = null;

            try {
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
