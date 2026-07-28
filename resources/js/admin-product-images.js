import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

const makeId = () => `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;

const registerProductImageAlpineData = () => {
    Alpine.data('productImageUploader', () => ({
        queue: [],
        editorOpen: false,
        editorIndex: null,
        cropper: null,
        uploading: false,
        uploadError: null,

        addFiles(event) {
            const files = Array.from(event.target.files ?? []);

            for (const file of files) {
                if (!file.type.startsWith('image/')) {
                    continue;
                }

                this.queue.push({
                    id: makeId(),
                    name: file.name,
                    mime: file.type,
                    alt: '',
                    previewUrl: URL.createObjectURL(file),
                    file,
                });
            }

            event.target.value = '';
        },

        removeFromQueue(index) {
            const item = this.queue[index];

            if (item?.previewUrl) {
                URL.revokeObjectURL(item.previewUrl);
            }

            this.queue.splice(index, 1);

            if (this.editorOpen && this.editorIndex === index) {
                this.closeEditor();
            }
        },

        openEditor(index) {
            this.editorIndex = index;
            this.editorOpen = true;
            this.uploadError = null;

            this.$nextTick(() => {
                const image = this.$refs.cropImage;

                if (!image) {
                    return;
                }

                if (image.complete) {
                    this.initCropper();
                } else {
                    image.onload = () => this.initCropper();
                }
            });
        },

        initCropper() {
            this.destroyCropper();

            const image = this.$refs.cropImage;

            if (!image) {
                return;
            }

            this.cropper = new Cropper(image, {
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 1,
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

        closeEditor() {
            this.destroyCropper();
            this.editorOpen = false;
            this.editorIndex = null;
        },

        rotate(degrees) {
            this.cropper?.rotate(degrees);
        },

        resetCrop() {
            this.cropper?.reset();
        },

        async applyCrop() {
            if (!this.cropper || this.editorIndex === null) {
                return;
            }

            const item = this.queue[this.editorIndex];

            if (!item) {
                return;
            }

            const canvas = this.cropper.getCroppedCanvas({
                maxWidth: 2400,
                maxHeight: 2400,
                fillColor: '#ffffff',
            });

            if (!canvas) {
                return;
            }

            const blob = await new Promise((resolve) => {
                canvas.toBlob(resolve, 'image/jpeg', 0.92);
            });

            if (!blob) {
                return;
            }

            if (item.previewUrl) {
                URL.revokeObjectURL(item.previewUrl);
            }

            item.previewUrl = URL.createObjectURL(blob);
            item.file = new File([blob], item.name.replace(/\.\w+$/, '.jpg'), {
                type: 'image/jpeg',
            });
            item.mime = 'image/jpeg';
            item.edited = true;

            this.closeEditor();
        },

        async submitProduct() {
            if (this.queue.length > 0) {
                await this.uploadAll();
            } else {
                await this.$wire.save();
            }
        },

        async uploadAll() {
            if (this.queue.length === 0 || this.uploading) {
                return;
            }

            this.uploading = true;
            this.uploadError = null;

            const files = this.queue.map((item) => item.file);
            const alts = this.queue.map((item) => item.alt);

            try {
                // Persist product first so create does not race with temp uploads.
                await this.$wire.ensureProductSaved();

                await this.$wire.set('pendingAlts', alts);

                await new Promise((resolve, reject) => {
                    this.$wire.uploadMultiple(
                        'newImages',
                        files,
                        () => resolve(),
                        (error) => reject(error),
                    );
                });

                await this.$wire.uploadImages();

                for (const item of this.queue) {
                    if (item.previewUrl) {
                        URL.revokeObjectURL(item.previewUrl);
                    }
                }

                this.queue = [];
            } catch (error) {
                console.error(error);
                this.uploadError = 'Upload failed. Check product details (name, price, slug) and try again.';
            } finally {
                this.uploading = false;
            }
        },
    }));

    Alpine.data('aiImageCandidates', () => ({
        aiEditorOpen: false,
        aiEditorId: null,
        aiEditorSrc: '',
        aiCropper: null,

        syncFromWire() {
            // Livewire re-renders keep Alpine state for the open editor only.
        },

        openAiEditor(id) {
            const candidates = this.$wire.aiCandidates ?? [];
            const candidate = candidates.find((item) => item.id === id);

            if (!candidate) {
                return;
            }

            this.aiEditorId = id;
            this.aiEditorSrc = `data:${candidate.mime};base64,${candidate.base64}`;
            this.aiEditorOpen = true;

            this.$nextTick(() => {
                const image = this.$refs.aiCropImage;

                if (!image) {
                    return;
                }

                const start = () => this.initAiCropper();

                if (image.complete) {
                    start();
                } else {
                    image.onload = start;
                }
            });
        },

        initAiCropper() {
            this.destroyAiCropper();

            const image = this.$refs.aiCropImage;

            if (!image) {
                return;
            }

            this.aiCropper = new Cropper(image, {
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 1,
                responsive: true,
                background: false,
            });
        },

        destroyAiCropper() {
            if (this.aiCropper) {
                this.aiCropper.destroy();
                this.aiCropper = null;
            }
        },

        closeAiEditor() {
            this.destroyAiCropper();
            this.aiEditorOpen = false;
            this.aiEditorId = null;
            this.aiEditorSrc = '';
        },

        rotateAi(degrees) {
            this.aiCropper?.rotate(degrees);
        },

        resetAiCrop() {
            this.aiCropper?.reset();
        },

        async applyAiCrop() {
            if (!this.aiCropper || !this.aiEditorId) {
                return;
            }

            const canvas = this.aiCropper.getCroppedCanvas({
                maxWidth: 2400,
                maxHeight: 2400,
                fillColor: '#ffffff',
            });

            if (!canvas) {
                return;
            }

            const blob = await new Promise((resolve) => {
                canvas.toBlob(resolve, 'image/jpeg', 0.92);
            });

            if (!blob) {
                return;
            }

            const base64 = await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => {
                    const result = String(reader.result || '');
                    const parts = result.split(',');
                    resolve(parts[1] || '');
                };
                reader.onerror = reject;
                reader.readAsDataURL(blob);
            });

            await this.$wire.updateAiCandidate(this.aiEditorId, 'image/jpeg', base64);
            this.closeAiEditor();
        },
    }));
};

if (window.Alpine) {
    registerProductImageAlpineData();
} else {
    document.addEventListener('alpine:init', registerProductImageAlpineData);
}
