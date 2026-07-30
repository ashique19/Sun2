import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

const makeId = () => `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;

const afterPaint = (callback) => {
    requestAnimationFrame(() => {
        requestAnimationFrame(callback);
    });
};

const registerProductImageAlpineData = () => {
    Alpine.data('productImageUploader', () => ({
        queue: [],
        editorOpen: false,
        editorIndex: null,
        allowOutsideClose: false,
        cropper: null,
        uploading: false,
        uploadProgress: 0,
        uploadStatus: '',
        uploadError: null,

        addFiles(event) {
            const files = Array.from(event.target.files ?? []);
            let skipped = 0;

            for (const file of files) {
                // Some browsers leave type empty for HEIC/odd files — still allow common image extensions.
                const looksLikeImage = file.type.startsWith('image/')
                    || /\.(jpe?g|png|webp|gif)$/i.test(file.name);

                if (!looksLikeImage) {
                    skipped += 1;
                    continue;
                }

                this.queue.push({
                    id: makeId(),
                    name: file.name,
                    mime: file.type || 'image/jpeg',
                    alt: '',
                    previewUrl: URL.createObjectURL(file),
                    file,
                });
            }

            if (skipped > 0 && this.queue.length === 0) {
                this.uploadError = 'No supported images were selected. Use JPG, PNG, or WebP.';
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
            } else if (this.editorOpen && this.editorIndex !== null && this.editorIndex > index) {
                this.editorIndex -= 1;
            }
        },

        openEditor(index) {
            this.allowOutsideClose = false;
            this.editorIndex = index;
            this.editorOpen = true;
            this.uploadError = null;

            // Teleport + x-if need a paint cycle before $refs.cropImage exists.
            this.$nextTick(() => {
                afterPaint(() => {
                    this.allowOutsideClose = true;
                    this.bootCropper();
                });
            });
        },

        bootCropper() {
            const image = this.$refs.cropImage;

            if (!image || !this.editorOpen) {
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

            if (!image || !this.editorOpen) {
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
            this.allowOutsideClose = false;
        },

        onEditorOutside() {
            if (this.allowOutsideClose) {
                this.closeEditor();
            }
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
            this.uploadProgress = 0;
            this.uploadStatus = 'Preparing…';
            this.uploadError = null;

            const files = this.queue.map((item) => item.file);
            const alts = this.queue.map((item) => item.alt);
            const total = files.length;

            try {
                // Persist product first so create does not race with temp uploads.
                await this.$wire.ensureProductSaved();

                await this.$wire.set('pendingAlts', alts);

                this.uploadStatus = total === 1
                    ? 'Uploading image…'
                    : `Uploading ${total} images…`;

                await new Promise((resolve, reject) => {
                    this.$wire.uploadMultiple(
                        'newImages',
                        files,
                        () => resolve(),
                        (error) => reject(error instanceof Error ? error : new Error(String(error || 'Upload failed'))),
                        (event) => {
                            const progress = Number(event?.detail?.progress ?? 0);
                            this.uploadProgress = Math.max(0, Math.min(100, Number.isFinite(progress) ? progress : 0));
                        },
                        () => reject(new Error('Upload cancelled')),
                        false, // replace property — do not append stale temp files
                    );
                });

                this.uploadProgress = 100;
                this.uploadStatus = total === 1
                    ? 'Saving image…'
                    : `Saving ${total} images…`;

                await this.$wire.uploadImages();

                for (const item of this.queue) {
                    if (item.previewUrl) {
                        URL.revokeObjectURL(item.previewUrl);
                    }
                }

                this.queue = [];
            } catch (error) {
                console.error(error);
                const livewireMessage = this.firstLivewireError();
                this.uploadError = livewireMessage
                    || (error?.message && error.message !== 'Upload failed'
                        ? error.message
                        : 'Upload failed. Check product details (name, price, slug) and try again.');
            } finally {
                this.uploading = false;
                this.uploadProgress = 0;
                this.uploadStatus = '';
            }
        },

        firstLivewireError() {
            try {
                const errors = this.$wire.$errors;
                if (!errors || typeof errors.first !== 'function') {
                    return null;
                }

                return errors.first('newImages')
                    || errors.first('newImages.0')
                    || errors.first('name')
                    || errors.first('slug')
                    || errors.first('price')
                    || null;
            } catch {
                return null;
            }
        },
    }));

    Alpine.data('aiImageCandidates', () => ({
        aiEditorOpen: false,
        aiEditorId: null,
        aiEditorSrc: '',
        aiAllowOutsideClose: false,
        aiCropper: null,
        geminiConfigured: false,
        hasRawImage: false,
        rawUploading: false,
        rawUploadProgress: 0,
        rawUploadError: null,
        rawImageBase64: '',
        rawImageMime: 'image/jpeg',
        rawImageName: '',
        generating: false,
        generateProgress: 0,
        generateStatus: '',
        generateError: null,
        generateTicker: null,

        canGenerate() {
            return this.geminiConfigured
                && this.hasRawImage
                && ! this.rawUploading
                && ! this.generating
                && this.rawImageBase64 !== '';
        },

        clearRawImage() {
            this.hasRawImage = false;
            this.rawUploading = false;
            this.rawUploadProgress = 0;
            this.rawUploadError = null;
            this.rawImageBase64 = '';
            this.rawImageMime = 'image/jpeg';
            this.rawImageName = '';
        },

        clearGenerateState() {
            this.generating = false;
            this.generateProgress = 0;
            this.generateStatus = '';
            this.generateError = null;

            if (this.generateTicker) {
                clearInterval(this.generateTicker);
                this.generateTicker = null;
            }
        },

        firstGenerateError() {
            try {
                const errors = this.$wire.$errors;

                if (errors && typeof errors.first === 'function') {
                    return errors.first('aiRawImage')
                        || errors.first('aiPrompt')
                        || null;
                }
            } catch {
                // ignore
            }

            return this.$wire.aiGenerateError || null;
        },

        startGenerateProgress() {
            const startedAt = Date.now();
            this.generateProgress = 4;
            this.generateStatus = 'Sending photo to Gemini…';

            if (this.generateTicker) {
                clearInterval(this.generateTicker);
            }

            this.generateTicker = setInterval(() => {
                const elapsed = Math.floor((Date.now() - startedAt) / 1000);
                this.generateProgress = Math.min(92, 4 + Math.floor(elapsed * 1.6));

                if (elapsed < 8) {
                    this.generateStatus = `Sending photo to Gemini… (${elapsed}s)`;
                } else if (elapsed < 45) {
                    this.generateStatus = `Gemini is generating the image… (${elapsed}s)`;
                } else {
                    this.generateStatus = `Still waiting on Gemini… (${elapsed}s). This can take up to about 90 seconds.`;
                }
            }, 500);
        },

        arrayBufferToBase64(buffer) {
            const bytes = new Uint8Array(buffer);
            const chunkSize = 0x8000;
            let binary = '';

            for (let i = 0; i < bytes.length; i += chunkSize) {
                binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
            }

            return btoa(binary);
        },

        // Keep base64 under Livewire payload budget (config/livewire.php payload.max_size).
        maxRawBase64Chars() {
            return 900000;
        },

        async blobToBase64(blob) {
            const buffer = await blob.arrayBuffer();

            return this.arrayBufferToBase64(buffer);
        },

        async canvasToConstrainedJpeg(sourceCanvas, onProgress) {
            let width = sourceCanvas.width;
            let height = sourceCanvas.height;
            let quality = 0.8;
            const maxChars = this.maxRawBase64Chars();

            for (let attempt = 0; attempt < 8; attempt += 1) {
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;

                const context = canvas.getContext('2d', { alpha: false });

                if (! context) {
                    throw new Error('Could not compress the raw photo in this browser.');
                }

                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, width, height);
                context.drawImage(sourceCanvas, 0, 0, width, height);

                const blob = await new Promise((resolve, reject) => {
                    canvas.toBlob(
                        (result) => (result ? resolve(result) : reject(new Error('Could not compress the raw photo.'))),
                        'image/jpeg',
                        quality,
                    );
                });

                const base64 = await this.blobToBase64(blob);
                onProgress(70 + Math.min(25, attempt * 4));

                if (base64.length <= maxChars) {
                    return base64;
                }

                quality = Math.max(0.45, quality - 0.12);

                if (attempt >= 2) {
                    width = Math.max(640, Math.round(width * 0.75));
                    height = Math.max(640, Math.round(height * 0.75));
                }
            }

            throw new Error('Photo is still too large after compression. Try a smaller image.');
        },

        async fileToPreparedImage(file, onProgress) {
            onProgress(8);

            if (typeof createImageBitmap !== 'function') {
                const buffer = await file.arrayBuffer();
                const base64 = this.arrayBufferToBase64(buffer);
                onProgress(90);

                if (base64.length > this.maxRawBase64Chars()) {
                    throw new Error('Photo is too large for AI generate in this browser. Try a smaller JPG.');
                }

                return {
                    base64,
                    mime: file.type || 'image/jpeg',
                    name: file.name || 'raw-photo.jpg',
                };
            }

            const bitmap = await createImageBitmap(file);
            onProgress(30);

            const maxDim = 1600;
            const scale = Math.min(1, maxDim / Math.max(bitmap.width, bitmap.height));
            const width = Math.max(1, Math.round(bitmap.width * scale));
            const height = Math.max(1, Math.round(bitmap.height * scale));
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;

            const context = canvas.getContext('2d', { alpha: false });

            if (! context) {
                bitmap.close();
                throw new Error('Could not prepare the raw photo in this browser.');
            }

            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, width, height);
            context.drawImage(bitmap, 0, 0, width, height);
            bitmap.close();
            onProgress(55);

            const base64 = await this.canvasToConstrainedJpeg(canvas, onProgress);
            onProgress(100);

            return {
                base64,
                mime: 'image/jpeg',
                name: (file.name || 'raw-photo').replace(/\.\w+$/, '') + '.jpg',
            };
        },

        async uploadRawPhoto(event) {
            const file = event.target.files?.[0] ?? null;
            event.target.value = '';
            this.rawUploadError = null;

            if (! file) {
                return;
            }

            const looksLikeImage = (file.type || '').startsWith('image/')
                || /\.(jpe?g|png|webp|gif)$/i.test(file.name);

            if (! looksLikeImage) {
                this.rawUploadError = 'Use a JPG, PNG, or WebP photo.';

                return;
            }

            if (file.size > 20 * 1024 * 1024) {
                this.rawUploadError = 'Choose a photo smaller than 20 MB.';

                return;
            }

            this.rawUploading = true;
            this.hasRawImage = false;
            this.rawImageBase64 = '';
            this.rawUploadProgress = 0;

            try {
                const prepared = await this.fileToPreparedImage(file, (progress) => {
                    this.rawUploadProgress = Math.max(0, Math.min(100, Number(progress) || 0));
                });

                this.rawImageBase64 = prepared.base64;
                this.rawImageMime = prepared.mime;
                this.rawImageName = prepared.name;
                this.hasRawImage = true;
                this.rawUploadProgress = 100;
                this.rawUploadError = null;
            } catch (error) {
                console.error(error);
                this.clearRawImage();
                this.rawUploadError = error?.message || 'Could not prepare the raw photo. Try again.';
            } finally {
                this.rawUploading = false;
            }
        },

        async generateWithRaw() {
            if (! this.canGenerate()) {
                return;
            }

            this.rawUploadError = null;
            this.generateError = null;
            this.generating = true;
            this.startGenerateProgress();

            const timeoutMs = 120000;

            try {
                await Promise.race([
                    this.$wire.generateAiImage(this.rawImageBase64, this.rawImageMime),
                    new Promise((_, reject) => {
                        setTimeout(() => {
                            reject(new Error('Generation timed out after 120 seconds. Try a smaller photo or try again.'));
                        }, timeoutMs);
                    }),
                ]);

                this.generateProgress = 100;
                this.generateStatus = 'Finishing…';

                await this.$nextTick();

                const serverError = this.firstGenerateError();

                if (serverError) {
                    this.generateError = serverError;
                    this.generateStatus = 'Generation failed';
                } else {
                    this.generateStatus = 'Image ready';
                }
            } catch (error) {
                console.error(error);
                this.generateError = error?.message || 'Generation failed. Try again.';
                this.generateStatus = 'Generation failed';
                this.generateProgress = 0;
            } finally {
                if (this.generateTicker) {
                    clearInterval(this.generateTicker);
                    this.generateTicker = null;
                }

                this.generating = false;
            }
        },

        openAiEditor(id) {
            const candidates = this.$wire.aiCandidates ?? [];
            const candidate = candidates.find((item) => item.id === id);

            if (!candidate) {
                return;
            }

            this.aiAllowOutsideClose = false;
            this.aiEditorId = id;
            this.aiEditorSrc = `data:${candidate.mime};base64,${candidate.base64}`;
            this.aiEditorOpen = true;

            this.$nextTick(() => {
                afterPaint(() => {
                    this.aiAllowOutsideClose = true;
                    this.bootAiCropper();
                });
            });
        },

        bootAiCropper() {
            const image = this.$refs.aiCropImage;

            if (!image || !this.aiEditorOpen) {
                return;
            }

            const start = () => this.initAiCropper();

            if (image.complete && image.naturalWidth > 0) {
                start();
            } else {
                image.onload = start;
            }
        },

        initAiCropper() {
            this.destroyAiCropper();

            const image = this.$refs.aiCropImage;

            if (!image || !this.aiEditorOpen) {
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
            this.aiAllowOutsideClose = false;
        },

        onAiEditorOutside() {
            if (this.aiAllowOutsideClose) {
                this.closeAiEditor();
            }
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
