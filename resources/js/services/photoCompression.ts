const MAX_UPLOAD_SIZE_BYTES = 20 * 1024 * 1024;
const MAX_LONG_EDGE = 4096;
const MAX_WEBP_QUALITY = 0.98;
const MIN_WEBP_QUALITY = 0.82;
const QUALITY_SEARCH_STEPS = 7;
const MIN_LONG_EDGE = 1920;

interface DecodedPhoto {
    height: number;
    source: CanvasImageSource;
    width: number;
    close: () => void;
}

export class PhotoCompressionError extends Error {
    public constructor(message: string) {
        super(message);
        this.name = 'PhotoCompressionError';
    }
}

export function requiresClientCompression(file: Blob): boolean {
    return file.size > MAX_UPLOAD_SIZE_BYTES;
}

export async function compressPhotoForUpload(file: File): Promise<Blob> {
    if (!requiresClientCompression(file)) {
        return file;
    }

    const decodedPhoto = await decodePhoto(file);

    try {
        let { width, height } = fitWithinLongEdge(decodedPhoto.width, decodedPhoto.height, MAX_LONG_EDGE);

        while (true) {
            const canvas = drawPhoto(decodedPhoto.source, width, height);
            const compressedPhoto = await encodeAtHighestFittingQuality(canvas);

            canvas.width = 1;
            canvas.height = 1;

            if (compressedPhoto !== null) {
                return compressedPhoto;
            }

            const nextDimensions = reduceDimensions(width, height);

            if (nextDimensions.width === width && nextDimensions.height === height) {
                throw new PhotoCompressionError('照片内容过于复杂，无法在保持清晰度的情况下压缩到 20 MB 以内。');
            }

            width = nextDimensions.width;
            height = nextDimensions.height;
        }
    } finally {
        decodedPhoto.close();
    }
}

async function decodePhoto(file: File): Promise<DecodedPhoto> {
    if (typeof createImageBitmap === 'function') {
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });

            return {
                width: bitmap.width,
                height: bitmap.height,
                source: bitmap,
                close: () => bitmap.close(),
            };
        } catch {
            // Safari may decode formats through an image element that createImageBitmap cannot handle.
        }
    }

    const objectUrl = URL.createObjectURL(file);

    try {
        const image = await loadImage(objectUrl);

        return {
            width: image.naturalWidth,
            height: image.naturalHeight,
            source: image,
            close: () => URL.revokeObjectURL(objectUrl),
        };
    } catch {
        URL.revokeObjectURL(objectUrl);

        throw new PhotoCompressionError('当前浏览器无法压缩这张超大照片，请先在相册中导出为 JPEG 后重试。');
    }
}

function loadImage(source: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();

        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('Unable to decode photo.'));
        image.src = source;
    });
}

function fitWithinLongEdge(width: number, height: number, maximumLongEdge: number): { width: number; height: number } {
    const longEdge = Math.max(width, height);

    if (longEdge <= maximumLongEdge) {
        return { width, height };
    }

    const scale = maximumLongEdge / longEdge;

    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}

function drawPhoto(source: CanvasImageSource, width: number, height: number): HTMLCanvasElement {
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { alpha: true });

    if (context === null) {
        throw new PhotoCompressionError('当前浏览器无法处理照片，请更换浏览器后重试。');
    }

    canvas.width = width;
    canvas.height = height;
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    context.drawImage(source, 0, 0, width, height);

    return canvas;
}

async function encodeAtHighestFittingQuality(canvas: HTMLCanvasElement): Promise<Blob | null> {
    const maximumQualityResult = await encodeWebp(canvas, MAX_WEBP_QUALITY);

    if (maximumQualityResult.size <= MAX_UPLOAD_SIZE_BYTES) {
        return maximumQualityResult;
    }

    const minimumQualityResult = await encodeWebp(canvas, MIN_WEBP_QUALITY);

    if (minimumQualityResult.size > MAX_UPLOAD_SIZE_BYTES) {
        return null;
    }

    let bestResult = minimumQualityResult;
    let minimumQuality = MIN_WEBP_QUALITY;
    let maximumQuality = MAX_WEBP_QUALITY;

    for (let step = 0; step < QUALITY_SEARCH_STEPS; step += 1) {
        const quality = (minimumQuality + maximumQuality) / 2;
        const candidate = await encodeWebp(canvas, quality);

        if (candidate.size <= MAX_UPLOAD_SIZE_BYTES) {
            bestResult = candidate;
            minimumQuality = quality;
        } else {
            maximumQuality = quality;
        }
    }

    return bestResult;
}

function encodeWebp(canvas: HTMLCanvasElement, quality: number): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (blob === null || blob.type !== 'image/webp') {
                reject(new PhotoCompressionError('当前浏览器不支持高质量 WebP 压缩，请升级浏览器后重试。'));

                return;
            }

            resolve(blob);
        }, 'image/webp', quality);
    });
}

function reduceDimensions(width: number, height: number): { width: number; height: number } {
    const longEdge = Math.max(width, height);

    if (longEdge <= MIN_LONG_EDGE) {
        return { width, height };
    }

    return fitWithinLongEdge(width, height, Math.max(MIN_LONG_EDGE, Math.floor(longEdge * 0.85)));
}
