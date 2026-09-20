<script setup lang="ts">
import { computed, ref } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import PageHeader from '../components/PageHeader.vue';
import MobilePageShell from '../layouts/MobilePageShell.vue';
import { calculateFileSha256 } from '../services/fileHash';
import { uploadPhotoToOss } from '../services/ossUpload';
import { completePhotoUpload, preparePhotoUploads, type OssTemporaryCredentials } from '../services/photoUploads';

type PhotoStatus = 'hashing' | 'checking' | 'ready' | 'uploading' | 'confirming' | 'uploaded' | 'duplicate' | 'error';

interface SelectedPhoto {
    errorMessage: string;
    file: File;
    id: string;
    objectKey: string | null;
    originalHash: string;
    photoId: number | null;
    progress: number;
    status: PhotoStatus;
    uploadFile: Blob;
    uploadHash: string;
}

const photoInput = ref<HTMLInputElement | null>(null);
const selectedPhotos = ref<SelectedPhoto[]>([]);
let selectionVersion = 0;

const isProcessing = computed(() =>
    selectedPhotos.value.some((photo) => ['hashing', 'checking', 'uploading', 'confirming'].includes(photo.status)),
);

function choosePhotos(): void {
    photoInput.value?.click();
}

async function handlePhotoSelection(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    const currentVersion = ++selectionVersion;

    selectedPhotos.value = files.map((file, index) => ({
        errorMessage: '',
        file,
        id: `${file.name}-${file.size}-${file.lastModified}-${index}`,
        objectKey: null,
        originalHash: '',
        photoId: null,
        progress: 0,
        status: 'hashing',
        uploadFile: file,
        uploadHash: '',
    }));
    input.value = '';

    for (const [index, photo] of selectedPhotos.value.entries()) {
        try {
            const originalHash = await calculateFileSha256(photo.file);
            const uploadHash = photo.uploadFile === photo.file
                ? originalHash
                : await calculateFileSha256(photo.uploadFile);

            if (currentVersion !== selectionVersion) {
                return;
            }

            selectedPhotos.value[index] = {
                ...photo,
                originalHash,
                status: 'checking',
                uploadHash,
            };
        } catch {
            if (currentVersion !== selectionVersion) {
                return;
            }

            selectedPhotos.value[index] = {
                ...photo,
                errorMessage: '照片读取失败，请重新选择。',
                status: 'error',
            };
        }
    }

    await prepareSelectedPhotos(currentVersion);
}

async function prepareSelectedPhotos(currentVersion: number): Promise<void> {
    const candidates = selectedPhotos.value
        .map((photo, selectedIndex) => ({ photo, selectedIndex }))
        .filter(({ photo }) => photo.status === 'checking');

    if (candidates.length === 0) {
        return;
    }

    try {
        const preparation = await preparePhotoUploads(
            candidates.map(({ photo }) => ({
                file_size: photo.uploadFile.size,
                filename: photo.file.name,
                mime_type: photo.uploadFile.type,
                original_file_hash: photo.originalHash,
                upload_file_hash: photo.uploadHash,
            })),
        );

        if (currentVersion !== selectionVersion) {
            return;
        }

        for (const result of preparation.photos) {
            const candidate = candidates[result.index];

            if (candidate === undefined) {
                continue;
            }

            const photo = selectedPhotos.value[candidate.selectedIndex];

            if (photo === undefined) {
                continue;
            }

            if (result.status === 'duplicate') {
                selectedPhotos.value[candidate.selectedIndex] = {
                    ...photo,
                    status: 'duplicate',
                };

                continue;
            }

            selectedPhotos.value[candidate.selectedIndex] = {
                ...photo,
                objectKey: result.object_key,
                photoId: result.photo_id,
                status: 'ready',
            };
        }

        await uploadReadyPhotos(candidates, preparation.credentials, currentVersion);
    } catch {
        if (currentVersion !== selectionVersion) {
            return;
        }

        for (const { selectedIndex } of candidates) {
            const photo = selectedPhotos.value[selectedIndex];

            if (photo === undefined) {
                continue;
            }

            selectedPhotos.value[selectedIndex] = {
                ...photo,
                errorMessage: '处理失败，请稍后重试。',
                status: 'error',
            };
        }
    }
}

async function uploadReadyPhotos(
    candidates: Array<{ photo: SelectedPhoto; selectedIndex: number }>,
    credentials: OssTemporaryCredentials,
    currentVersion: number,
): Promise<void> {
    for (const { selectedIndex } of candidates) {
        if (currentVersion !== selectionVersion) {
            return;
        }

        const photo = selectedPhotos.value[selectedIndex];

        if (photo === undefined || photo.status !== 'ready' || photo.objectKey === null || photo.photoId === null) {
            continue;
        }

        selectedPhotos.value[selectedIndex] = { ...photo, progress: 0, status: 'uploading' };

        try {
            await uploadPhotoToOss(photo.uploadFile, photo.objectKey, credentials, (progress) => {
                const currentPhoto = selectedPhotos.value[selectedIndex];

                if (currentVersion === selectionVersion && currentPhoto?.status === 'uploading') {
                    selectedPhotos.value[selectedIndex] = { ...currentPhoto, progress };
                }
            });

            const uploadedPhoto = selectedPhotos.value[selectedIndex];

            if (currentVersion !== selectionVersion || uploadedPhoto === undefined) {
                return;
            }

            selectedPhotos.value[selectedIndex] = { ...uploadedPhoto, status: 'confirming' };
            await completePhotoUpload(photo.photoId);

            const confirmedPhoto = selectedPhotos.value[selectedIndex];

            if (currentVersion !== selectionVersion || confirmedPhoto === undefined) {
                return;
            }

            selectedPhotos.value[selectedIndex] = { ...confirmedPhoto, progress: 100, status: 'uploaded' };
        } catch {
            const failedPhoto = selectedPhotos.value[selectedIndex];

            if (currentVersion !== selectionVersion || failedPhoto === undefined) {
                return;
            }

            selectedPhotos.value[selectedIndex] = {
                ...failedPhoto,
                errorMessage: '上传失败，请稍后重试。',
                status: 'error',
            };
        }
    }
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function statusLabel(photo: SelectedPhoto): string {
    const labels: Record<Exclude<PhotoStatus, 'uploading'>, string> = {
        hashing: '处理中',
        checking: '处理中',
        ready: '处理中',
        confirming: '处理中',
        uploaded: '上传成功',
        duplicate: '已存在，无需上传',
        error: '上传失败',
    };

    if (photo.status === 'uploading') {
        return `上传中 ${photo.progress}%`;
    }

    return labels[photo.status];
}

function statusClasses(status: PhotoStatus): string {
    if (status === 'ready' || status === 'uploaded') {
        return 'bg-sage-100 text-sage-600';
    }

    if (status === 'duplicate') {
        return 'bg-gold-100 text-gold-500';
    }

    if (status === 'error') {
        return 'bg-rose-100 text-rose-700';
    }

    return 'bg-gold-100 text-gold-500';
}
</script>

<template>
    <MobilePageShell>
        <PageHeader eyebrow="定格此刻" title="分享你眼中的幸福" description="上传婚礼现场的照片，把每一个珍贵瞬间留在我们的共同相册里。" />

        <section class="rounded-card border border-dashed border-rose-300/80 bg-rose-50/55 px-6 py-10 text-center shadow-card">
            <div class="mx-auto grid size-16 place-items-center rounded-full bg-white text-2xl text-rose-600 shadow-sm" aria-hidden="true">＋</div>
            <div class="mt-5 flex flex-col gap-2">
                <h2 class="font-display text-2xl font-semibold text-ink-950">上传现场照片</h2>
                <p class="text-sm leading-6 text-ink-500">支持上传原图，展示时会自动使用清晰的缩略图。</p>
            </div>
            <input
                ref="photoInput"
                class="sr-only"
                type="file"
                accept="image/jpeg,image/png,image/webp,image/heic"
                multiple
                @change="handlePhotoSelection"
            />
            <div class="mt-6">
                <BaseButton full-width :disabled="isProcessing" @click="choosePhotos">
                    {{ isProcessing ? '正在处理照片…' : selectedPhotos.length > 0 ? '重新选择照片' : '选择照片' }}
                </BaseButton>
            </div>
        </section>

        <section v-if="selectedPhotos.length > 0" class="flex flex-col gap-3" aria-labelledby="selected-photos-title">
            <div class="flex items-center justify-between gap-4">
                <h2 id="selected-photos-title" class="font-display text-xl font-semibold text-ink-950">已选择照片</h2>
                <span class="rounded-pill bg-gold-100 px-3 py-1.5 text-xs font-semibold text-gold-500">{{ selectedPhotos.length }} 张</span>
            </div>

            <article
                v-for="photo in selectedPhotos"
                :key="photo.id"
                class="flex flex-col gap-3 rounded-card border border-white/80 bg-white/72 p-4 shadow-card backdrop-blur-sm"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-ink-950">{{ photo.file.name }}</p>
                        <p class="mt-1 text-xs text-ink-500">{{ formatFileSize(photo.file.size) }}</p>
                    </div>
                    <span
                        :class="[
                            'shrink-0 rounded-pill px-3 py-1.5 text-xs font-semibold',
                            statusClasses(photo.status),
                        ]"
                        role="status"
                        aria-live="polite"
                    >
                        {{ statusLabel(photo) }}
                    </span>
                </div>

                <div v-if="photo.status === 'uploading'" class="h-1.5 overflow-hidden rounded-pill bg-rose-100" aria-hidden="true">
                    <div class="h-full rounded-pill bg-rose-600 transition-[width] duration-200" :style="{ width: `${photo.progress}%` }"></div>
                </div>
                <p v-if="photo.status === 'error'" class="text-xs leading-5 text-rose-700">{{ photo.errorMessage }}</p>
            </article>
        </section>

        <p class="text-center text-xs leading-5 text-ink-500">每一张照片，都是送给新人的一份特别礼物。</p>
    </MobilePageShell>
</template>
