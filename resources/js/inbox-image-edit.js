import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

const registerInboxImageEdit = () => {
    Alpine.data('inboxImageEdit', (imageUrl, wireId = null) => ({
        cropper: null,
        busy: false,
        error: null,
        imageUrl,
        wireId,
        overlayText: '',
        overlayTextSize: 48,
        overlayTextPosition: 'bottom-left',
        previewUrl: '',
        previewTimer: null,

        init() {
            this.$nextTick(() => this.boot());
            this.$watch('overlayText', () => this.queuePreview());
            this.$watch('overlayTextSize', () => this.queuePreview());
            this.$watch('overlayTextPosition', () => this.queuePreview());
        },

        wire() {
            if (this.wireId && window.Livewire?.find) {
                const component = window.Livewire.find(this.wireId);
                if (component) {
                    return component;
                }
            }

            return this.$wire;
        },

        boot() {
            const image = this.$refs.editImage;
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
            const image = this.$refs.editImage;
            if (! image) {
                return;
            }

            this.cropper = new Cropper(image, {
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 0.92,
                responsive: true,
                background: false,
                checkOrientation: false,
                cropend: () => this.queuePreview(),
                zoom: () => this.queuePreview(),
            });

            this.$nextTick(() => {
                this.cropper?.resize();
                this.queuePreview();
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
            this.queuePreview();
        },

        resetCrop() {
            this.cropper?.reset();
            this.queuePreview();
        },

        queuePreview() {
            clearTimeout(this.previewTimer);
            this.previewTimer = setTimeout(() => {
                this.refreshPreview().catch(() => {});
            }, 120);
        },

        cornerOrigin(position, canvasWidth, canvasHeight, boxWidth, boxHeight, pad) {
            const key = String(position || 'bottom-left');

            if (key === 'top-right') {
                return { x: canvasWidth - boxWidth - pad, y: pad };
            }
            if (key === 'bottom-right') {
                return { x: canvasWidth - boxWidth - pad, y: canvasHeight - boxHeight - pad };
            }
            if (key === 'top-left') {
                return { x: pad, y: pad };
            }

            return { x: pad, y: canvasHeight - boxHeight - pad };
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

        async composeCanvas(maxEdge = 1600) {
            if (! this.cropper) {
                return null;
            }

            const canvas = this.cropper.getCroppedCanvas({
                maxWidth: maxEdge,
                maxHeight: maxEdge,
                fillColor: '#ffffff',
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high',
            });

            if (! canvas) {
                return null;
            }

            const output = document.createElement('canvas');
            output.width = canvas.width;
            output.height = canvas.height;
            const context = output.getContext('2d');
            if (! context) {
                return null;
            }

            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, output.width, output.height);
            context.drawImage(canvas, 0, 0);
            this.drawTextOverlay(context, output);

            return output;
        },

        async refreshPreview() {
            const output = await this.composeCanvas(720);
            if (! output) {
                return;
            }

            if (this.previewUrl) {
                URL.revokeObjectURL(this.previewUrl);
            }

            this.previewUrl = output.toDataURL('image/jpeg', 0.9);
        },

        async sendEdited() {
            if (! this.cropper || this.busy) {
                return;
            }

            this.busy = true;
            this.error = null;

            try {
                const output = await this.composeCanvas(1600);
                if (! output) {
                    throw new Error('Could not compose edited image.');
                }

                const blob = await new Promise((resolve) => {
                    output.toBlob(resolve, 'image/jpeg', 0.92);
                });

                if (! blob) {
                    throw new Error('Could not encode edited image.');
                }

                const file = new File([blob], 'inbox-edited.jpg', { type: 'image/jpeg' });
                const component = this.wire();

                await new Promise((resolve, reject) => {
                    component.upload(
                        'editedReplyImage',
                        file,
                        () => resolve(),
                        () => reject(new Error('Upload failed.')),
                        () => {},
                    );
                });

                await component.call('sendEditedImageReply');
            } catch (e) {
                this.error = e?.message || 'Could not send edited image.';
            } finally {
                this.busy = false;
            }
        },

        destroy() {
            clearTimeout(this.previewTimer);
            if (this.previewUrl) {
                URL.revokeObjectURL(this.previewUrl);
            }
            this.destroyCropper();
        },
    }));
};

if (window.Alpine) {
    registerInboxImageEdit();
} else {
    document.addEventListener('alpine:init', registerInboxImageEdit);
}
