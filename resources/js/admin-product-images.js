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
        savedEditorOpen: false,
        savedEditorId: null,
        savedEditorSrc: '',
        savedAllowOutsideClose: false,
        savedCropper: null,
        savedSaving: false,
        savedError: null,
        savedPreviewUrl: '',
        savedPreviewPending: false,
        savedPreviewTimer: null,
        savedAspect: 'free',
        overlayText: '',
        overlayTextSize: 48,
        overlayTextPosition: 'bottom-left',
        overlayLogoEnabled: false,
        overlayLogoSize: 22,
        overlayLogoPosition: 'top-right',
        logoUrl: '/img/settings/logo.png',
        logoImage: null,
        _savedOverlayUnwatch: null,

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
                    || errors.first('editedImage')
                    || errors.first('name')
                    || errors.first('slug')
                    || errors.first('price')
                    || null;
            } catch {
                return null;
            }
        },

        openSavedEditor(id, src) {
            this.savedError = null;
            this.savedSaving = false;
            this.savedAllowOutsideClose = false;
            this.savedEditorId = id;
            this.savedEditorSrc = src;
            this.savedPreviewUrl = '';
            this.savedPreviewPending = false;
            this.savedAspect = 'free';
            this.overlayText = '';
            this.overlayTextSize = 48;
            this.overlayTextPosition = 'bottom-left';
            this.overlayLogoEnabled = false;
            this.overlayLogoSize = 22;
            this.overlayLogoPosition = 'top-right';
            this.savedEditorOpen = true;
            this.preloadLogo();
            this.bindSavedOverlayWatchers();

            this.$nextTick(() => {
                afterPaint(() => {
                    this.savedAllowOutsideClose = true;
                    this.bootSavedCropper(0);
                });
            });
        },

        bindSavedOverlayWatchers() {
            if (typeof this._savedOverlayUnwatch === 'function') {
                this._savedOverlayUnwatch();
                this._savedOverlayUnwatch = null;
            }

            this._savedOverlayUnwatch = this.$watch(
                () => [
                    this.overlayText,
                    this.overlayTextSize,
                    this.overlayTextPosition,
                    this.overlayLogoEnabled,
                    this.overlayLogoSize,
                    this.overlayLogoPosition,
                ].join('|'),
                () => {
                    if (this.savedCropper) {
                        this.schedulePreview();
                    }
                },
            );
        },

        preloadLogo() {
            if (this.logoImage && this.logoImage.complete) {
                return;
            }

            const image = new Image();
            image.decoding = 'async';
            image.crossOrigin = 'anonymous';
            image.src = this.logoUrl;
            this.logoImage = image;
        },

        savedCropImageEl() {
            return document.querySelector('[data-saved-editor] [data-saved-crop-image]');
        },

        bootSavedCropper(attempt = 0) {
            if (! this.savedEditorOpen) {
                return;
            }

            const image = this.savedCropImageEl();

            if (! image) {
                if (attempt < 20) {
                    setTimeout(() => this.bootSavedCropper(attempt + 1), 50);

                    return;
                }

                this.savedError = 'Could not open the image editor. Refresh and try again.';
                this.savedPreviewPending = false;

                return;
            }

            const start = () => this.initSavedCropper();

            if (image.complete && image.naturalWidth > 0) {
                start();

                return;
            }

            image.onload = () => start();
            image.onerror = () => {
                this.savedError = 'Could not load this product image for editing.';
                this.savedPreviewPending = false;
            };

            // Force reload if src is set but the browser cached a failure.
            if (image.src && ! image.complete) {
                const current = image.src;
                image.src = '';
                image.src = current;
            }
        },

        initSavedCropper() {
            this.destroySavedCropper();

            const image = this.savedCropImageEl();

            if (! image || ! this.savedEditorOpen) {
                this.savedPreviewPending = false;

                return;
            }

            if (! image.naturalWidth) {
                this.savedError = 'Could not load this product image for editing.';
                this.savedPreviewPending = false;

                return;
            }

            try {
                this.savedCropper = new Cropper(image, {
                    viewMode: 1,
                    dragMode: 'crop',
                    autoCropArea: 0.75,
                    responsive: true,
                    background: true,
                    guides: true,
                    center: true,
                    highlight: true,
                    movable: true,
                    rotatable: true,
                    scalable: true,
                    zoomable: true,
                    zoomOnWheel: true,
                    cropBoxMovable: true,
                    cropBoxResizable: true,
                    toggleDragModeOnDblclick: true,
                    ready: () => {
                        try {
                            this.savedCropper?.resize();
                        } catch {
                            // ignore resize probe failures
                        }
                        this.schedulePreview();
                    },
                    crop: () => {
                        this.schedulePreview();
                    },
                });
            } catch (error) {
                console.error(error);
                this.savedError = 'Could not start the crop tool. Refresh and try again.';
                this.savedPreviewPending = false;
            }
        },

        destroySavedCropper() {
            if (this.savedPreviewTimer) {
                clearTimeout(this.savedPreviewTimer);
                this.savedPreviewTimer = null;
            }

            if (this.savedCropper) {
                this.savedCropper.destroy();
                this.savedCropper = null;
            }
        },

        closeSavedEditor() {
            if (typeof this._savedOverlayUnwatch === 'function') {
                this._savedOverlayUnwatch();
                this._savedOverlayUnwatch = null;
            }

            this.destroySavedCropper();
            this.savedEditorOpen = false;
            this.savedEditorId = null;
            this.savedEditorSrc = '';
            this.savedPreviewUrl = '';
            this.savedPreviewPending = false;
            this.savedAllowOutsideClose = false;
            this.savedSaving = false;
            this.savedError = null;
        },

        onSavedEditorOutside() {
            if (this.savedAllowOutsideClose && ! this.savedSaving) {
                this.closeSavedEditor();
            }
        },

        rotateSaved(degrees) {
            this.savedCropper?.rotate(degrees);
            this.schedulePreview();
        },

        resetSavedCrop() {
            this.savedAspect = 'free';
            this.savedCropper?.reset();
            this.savedCropper?.setAspectRatio(NaN);
            this.schedulePreview();
        },

        setSavedAspect(ratio) {
            this.savedAspect = ratio;
            this.savedCropper?.setAspectRatio(ratio === 'free' ? NaN : ratio);
            this.schedulePreview();
        },

        schedulePreview() {
            if (! this.savedEditorOpen || ! this.savedCropper) {
                return;
            }

            this.savedPreviewPending = true;

            if (this.savedPreviewTimer) {
                clearTimeout(this.savedPreviewTimer);
            }

            this.savedPreviewTimer = setTimeout(() => {
                this.savedPreviewTimer = null;
                this.refreshPreview();
            }, 120);
        },

        async refreshPreview() {
            if (! this.savedEditorOpen) {
                this.savedPreviewPending = false;

                return;
            }

            if (! this.savedCropper) {
                this.savedPreviewPending = false;

                return;
            }

            try {
                const output = await this.composeEditedCanvas(720);

                if (! output || ! this.savedEditorOpen) {
                    if (this.savedEditorOpen && ! this.savedPreviewUrl) {
                        this.savedError = this.savedError || 'Could not build a preview from this crop.';
                    }

                    return;
                }

                this.savedPreviewUrl = output.toDataURL('image/jpeg', 0.86);
                this.savedError = null;
            } catch (error) {
                console.error(error);
                this.savedError = error?.message || 'Could not build a preview from this crop.';
            } finally {
                this.savedPreviewPending = false;
            }
        },

        overlayPositions() {
            return [
                { value: 'top-left', label: 'Top left' },
                { value: 'top-right', label: 'Top right' },
                { value: 'bottom-left', label: 'Bottom left' },
                { value: 'bottom-right', label: 'Bottom right' },
            ];
        },

        cornerOrigin(position, width, height, boxWidth, boxHeight, pad) {
            switch (position) {
                case 'top-right':
                    return { x: width - boxWidth - pad, y: pad };
                case 'bottom-left':
                    return { x: pad, y: height - boxHeight - pad };
                case 'bottom-right':
                    return { x: width - boxWidth - pad, y: height - boxHeight - pad };
                case 'top-left':
                default:
                    return { x: pad, y: pad };
            }
        },

        scaledTextSize(canvasWidth) {
            const base = Math.max(16, Math.min(120, Number(this.overlayTextSize) || 48));

            return Math.max(12, Math.round(base * (Math.max(1, canvasWidth) / 1600)));
        },

        drawTextOverlay(context, canvas) {
            const text = String(this.overlayText || '').trim();

            if (text === '') {
                return;
            }

            const fontSize = this.scaledTextSize(canvas.width);
            context.font = `700 ${fontSize}px "DejaVu Sans", "Segoe UI", sans-serif`;
            context.textBaseline = 'top';

            const metrics = context.measureText(text);
            const textWidth = Math.ceil(metrics.width);
            const textHeight = Math.ceil(fontSize * 1.25);
            const padX = Math.round(fontSize * 0.45);
            const padY = Math.round(fontSize * 0.3);
            const boxWidth = textWidth + padX * 2;
            const boxHeight = textHeight + padY * 2;
            const pad = Math.round(Math.min(canvas.width, canvas.height) * 0.03);
            const origin = this.cornerOrigin(
                this.overlayTextPosition,
                canvas.width,
                canvas.height,
                boxWidth,
                boxHeight,
                pad,
            );

            context.fillStyle = 'rgba(255, 255, 255, 0.82)';
            context.fillRect(origin.x, origin.y, boxWidth, boxHeight);
            context.fillStyle = '#1E1E1E';
            context.fillText(text, origin.x + padX, origin.y + padY);
        },

        async drawLogoOverlay(context, canvas) {
            if (! this.overlayLogoEnabled) {
                return;
            }

            await this.ensureLogoLoaded();

            const logo = this.logoImage;

            if (! logo || ! logo.naturalWidth) {
                throw new Error('Could not load the brand logo for overlay.');
            }

            const targetWidth = Math.max(
                24,
                Math.round(canvas.width * (Math.max(8, Math.min(60, Number(this.overlayLogoSize) || 22)) / 100)),
            );
            const scale = targetWidth / logo.naturalWidth;
            const targetHeight = Math.max(12, Math.round(logo.naturalHeight * scale));
            const pad = Math.round(Math.min(canvas.width, canvas.height) * 0.03);
            const origin = this.cornerOrigin(
                this.overlayLogoPosition,
                canvas.width,
                canvas.height,
                targetWidth,
                targetHeight,
                pad,
            );

            context.drawImage(logo, origin.x, origin.y, targetWidth, targetHeight);
        },

        ensureLogoLoaded() {
            return new Promise((resolve, reject) => {
                this.preloadLogo();
                const logo = this.logoImage;

                if (! logo) {
                    reject(new Error('Logo is not available.'));

                    return;
                }

                if (logo.complete && logo.naturalWidth > 0) {
                    resolve();

                    return;
                }

                logo.onload = () => resolve();
                logo.onerror = () => reject(new Error('Could not load the brand logo.'));
            });
        },

        async composeEditedCanvas(maxEdge) {
            if (! this.savedCropper) {
                return null;
            }

            let canvas;

            try {
                canvas = this.savedCropper.getCroppedCanvas({
                    maxWidth: maxEdge,
                    maxHeight: maxEdge,
                    fillColor: '#ffffff',
                });
            } catch (error) {
                throw new Error(error?.message || 'Crop preview failed (image may be blocked by the browser).');
            }

            if (! canvas) {
                return null;
            }

            const output = document.createElement('canvas');
            output.width = canvas.width;
            output.height = canvas.height;

            const context = output.getContext('2d', { alpha: false });

            if (! context) {
                return null;
            }

            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, output.width, output.height);
            context.drawImage(canvas, 0, 0);
            this.drawTextOverlay(context, output);
            await this.drawLogoOverlay(context, output);

            return output;
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

        async blobToBase64(blob) {
            const buffer = await blob.arrayBuffer();

            return this.arrayBufferToBase64(buffer);
        },

        async canvasToSaveJpeg(sourceCanvas) {
            let width = sourceCanvas.width;
            let height = sourceCanvas.height;
            let quality = 0.88;
            // Keep under Livewire payload.max_size (2MB) with headroom for the request envelope.
            const maxChars = 1400000;

            for (let attempt = 0; attempt < 8; attempt += 1) {
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;

                const context = canvas.getContext('2d', { alpha: false });

                if (! context) {
                    throw new Error('Could not compress the edited image in this browser.');
                }

                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, width, height);
                context.drawImage(sourceCanvas, 0, 0, width, height);

                const blob = await new Promise((resolve, reject) => {
                    canvas.toBlob(
                        (result) => (result ? resolve(result) : reject(new Error('Could not encode the edited image.'))),
                        'image/jpeg',
                        quality,
                    );
                });

                const base64 = await this.blobToBase64(blob);

                if (base64.length <= maxChars) {
                    return base64;
                }

                quality = Math.max(0.55, quality - 0.1);

                if (attempt >= 2) {
                    width = Math.max(720, Math.round(width * 0.8));
                    height = Math.max(720, Math.round(height * 0.8));
                }
            }

            throw new Error('Edited image is still too large to save. Try a tighter crop.');
        },

        async saveSavedEdit() {
            if (! this.savedCropper || ! this.savedEditorId || this.savedSaving) {
                return;
            }

            this.savedSaving = true;
            this.savedError = null;

            try {
                const output = await this.composeEditedCanvas(1600);

                if (! output) {
                    throw new Error('Could not crop the image.');
                }

                const base64 = await this.canvasToSaveJpeg(output);
                const imageId = this.savedEditorId;

                // Avoid Livewire temp uploads from the teleported modal — they can hang forever.
                const result = await Promise.race([
                    this.$wire.replaceEditedImage(imageId, base64, 'image/jpeg'),
                    new Promise((_, reject) => {
                        setTimeout(() => {
                            reject(new Error('Save timed out after 60 seconds. Try again.'));
                        }, 60000);
                    }),
                ]);

                const livewireMessage = this.firstLivewireError();

                if (livewireMessage) {
                    throw new Error(livewireMessage);
                }

                const updatedMessage = String(this.$wire.message ?? this.$wire.get?.('message') ?? '').trim();
                const returnedUrl = typeof result === 'object' && result !== null ? String(result.url || '') : '';
                const returnedPath = typeof result === 'object' && result !== null ? String(result.path || '') : '';

                if (! returnedUrl || ! returnedPath || updatedMessage !== 'Image updated.') {
                    throw new Error(updatedMessage || 'Image was not updated. Please try again.');
                }

                this.closeSavedEditor();
            } catch (error) {
                console.error(error);
                const livewireMessage = this.firstLivewireError();
                this.savedError = livewireMessage
                    || (error?.message ? error.message : 'Could not save the edited image.');
                this.savedSaving = false;
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
