<script setup lang="ts">
import { isAxiosError } from 'axios';
import { computed, ref, watch } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import LoadingState from '../components/LoadingState.vue';
import PageHeader from '../components/PageHeader.vue';
import MobilePageShell from '../layouts/MobilePageShell.vue';
import { calculateFileSha256 } from '../services/fileHash';
import { uploadPhotoToOss } from '../services/ossUpload';
import {
    completePhotoUpload,
    fetchCurrentUserPhotos,
    preparePhotoUploads,
    type CurrentUserPhoto,
    type OssTemporaryCredentials,
} from '../services/photoUploads';
import { useUserIdentityStore } from '../stores/userIdentity';

type PhotoStatus = 'hashing' | 'checking' | 'ready' | 'uploading' | 'confirming' | 'uploaded' | 'duplicate' | 'error';

interface SelectedPhoto {
    credentials: OssTemporaryCredentials | null;
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

interface ApiErrorResponse {
    errors?: Record<string, string[]>;
    message?: string;
}

const userIdentity = useUserIdentityStore();
const photoInput = ref<HTMLInputElement | null>(null);
const selectedPhotos = ref<SelectedPhoto[]>([]);
const currentUserPhotos = ref<CurrentUserPhoto[]>([]);
const photoListStatus = ref<'idle' | 'loading' | 'ready' | 'error'>('idle');
const failedThumbnailIds = ref<Set<number>>(new Set());
let selectionVersion = 0;

const isProcessing = computed(() =>
    selectedPhotos.value.some((photo) => ['hashing', 'checking', 'uploading', 'confirming'].includes(photo.status)),
);

watch(
    () => userIdentity.isAuthenticated,
    (isAuthenticated) => {
        if (isAuthenticated) {
            void loadCurrentUserPhotos();
        }
    },
    { immediate: true },
);

function choosePhotos(): void {
    photoInput.value?.click();
}

async function handlePhotoSelection(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    const currentVersion = ++selectionVersion;

    selectedPhotos.value = files.map((file, index) => ({
        credentials: null,
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

    for (const { selectedIndex } of candidates) {
        if (currentVersion !== selectionVersion) {
            return;
        }

        const photo = selectedPhotos.value[selectedIndex];

        if (photo === undefined || photo.status !== 'checking') {
            continue;
        }

        try {
            const preparation = await preparePhotoUploads([{
                file_size: photo.uploadFile.size,
                filename: photo.file.name,
                mime_type: photo.uploadFile.type,
                original_file_hash: photo.originalHash,
                upload_file_hash: photo.uploadHash,
            }]);
            const result = preparation.photos[0];

            if (currentVersion !== selectionVersion || result === undefined) {
                return;
            }

            if (result.status === 'duplicate') {
                selectedPhotos.value[selectedIndex] = {
                    ...photo,
                    status: 'duplicate',
                };

                continue;
            }

            selectedPhotos.value[selectedIndex] = {
                ...photo,
                credentials: preparation.credentials,
                objectKey: result.object_key,
                photoId: result.photo_id,
                status: 'ready',
            };

            await uploadSelectedPhoto(selectedIndex, currentVersion);
        } catch (error: unknown) {
            if (currentVersion !== selectionVersion) {
                return;
            }

            const failedPhoto = selectedPhotos.value[selectedIndex];

            if (failedPhoto === undefined) {
                continue;
            }

            selectedPhotos.value[selectedIndex] = {
                ...failedPhoto,
                errorMessage: getPreparationErrorMessage(error),
                status: 'error',
            };
        }
    }
}

async function uploadSelectedPhoto(selectedIndex: number, currentVersion: number): Promise<void> {
    const photo = selectedPhotos.value[selectedIndex];

    if (
        photo === undefined
        || !['ready', 'error'].includes(photo.status)
        || photo.credentials === null
        || photo.objectKey === null
        || photo.photoId === null
    ) {
        return;
    }

    selectedPhotos.value[selectedIndex] = { ...photo, errorMessage: '', progress: 0, status: 'uploading' };

    try {
        await uploadPhotoToOss(photo.uploadFile, photo.objectKey, photo.credentials, (progress) => {
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
        await loadCurrentUserPhotos();
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

function getPreparationErrorMessage(error: unknown): string {
    if (!isAxiosError<ApiErrorResponse>(error)) {
        return '照片处理失败，请稍后重试。';
    }

    const validationMessage = Object.values(error.response?.data.errors ?? {})
        .flat()
        .find((message) => message.trim() !== '');

    return validationMessage ?? error.response?.data.message ?? '照片处理失败，请稍后重试。';
}

async function retryPhoto(selectedIndex: number): Promise<void> {
    await uploadSelectedPhoto(selectedIndex, selectionVersion);
}

async function loadCurrentUserPhotos(): Promise<void> {
    photoListStatus.value = 'loading';

    try {
        currentUserPhotos.value = await fetchCurrentUserPhotos();
        failedThumbnailIds.value = new Set();
        photoListStatus.value = 'ready';
    } catch {
        photoListStatus.value = 'error';
    }
}

function markThumbnailFailed(photoId: number): void {
    failedThumbnailIds.value = new Set([...failedThumbnailIds.value, photoId]);
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
        error: '无法上传',
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

function uploadStatusLabel(status: CurrentUserPhoto['upload_status']): string {
    return {
        preparing: '处理中',
        uploaded: '上传成功',
        failed: '上传失败',
    }[status];
}

function reviewStatusLabel(status: CurrentUserPhoto['review_status']): string {
    return {
        pending: '待审核',
        normal: '已通过',
        good: '精选',
        rejected: '未通过',
    }[status];
}

function reviewStatusClasses(status: CurrentUserPhoto['review_status']): string {
    if (status === 'normal') {
        return 'bg-sage-100 text-sage-600';
    }

    if (status === 'good') {
        return 'bg-rose-100 text-rose-700';
    }

    if (status === 'rejected') {
        return 'bg-canvas text-ink-500';
    }

    return 'bg-gold-100 text-gold-500';
}
</script>

<template>
    <MobilePageShell>
        <PageHeader eyebrow="定格此刻" title="分享你眼中的幸福" description="上传婚礼现场的照片，把每一个珍贵瞬间留在我们的共同相册里。" />

        <section class="rounded-card border border-dashed border-rose-300/80 bg-rose-50/55 px-6 py-10 text-center shadow-card">
            <div class="flex flex-col gap-2">
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
                v-for="(photo, selectedIndex) in selectedPhotos"
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
                <BaseButton
                    v-if="photo.status === 'error' && photo.credentials !== null"
                    variant="quiet"
                    :disabled="isProcessing"
                    @click="retryPhoto(selectedIndex)"
                >
                    重新上传
                </BaseButton>
            </article>
        </section>

        <section class="flex flex-col gap-4" aria-labelledby="my-photos-title">
            <div class="flex items-center justify-between gap-4">
                <h2 id="my-photos-title" class="font-display text-xl font-semibold text-ink-950">我的照片</h2>
                <span v-if="photoListStatus === 'ready'" class="rounded-pill bg-gold-100 px-3 py-1.5 text-xs font-semibold text-gold-500">
                    {{ currentUserPhotos.length }} 张
                </span>
            </div>

            <div v-if="photoListStatus === 'idle' || photoListStatus === 'loading'" class="grid min-h-28 place-items-center rounded-card bg-white/55">
                <LoadingState label="正在加载照片" />
            </div>

            <div v-else-if="photoListStatus === 'error'" class="flex flex-col items-center gap-4 rounded-card bg-white/65 p-5 text-center shadow-card">
                <p class="text-sm text-ink-500">照片暂时加载失败</p>
                <BaseButton variant="secondary" @click="loadCurrentUserPhotos">重新加载</BaseButton>
            </div>

            <div v-else-if="currentUserPhotos.length === 0" class="rounded-card bg-white/55 px-5 py-8 text-center text-sm text-ink-500">
                上传完成的照片会出现在这里
            </div>

            <div v-else class="grid grid-cols-2 gap-3">
                <article
                    v-for="photo in currentUserPhotos"
                    :key="photo.id"
                    class="overflow-hidden rounded-card border border-white/80 bg-white/75 shadow-card"
                >
                    <div class="aspect-square bg-rose-50">
                        <img
                            v-if="photo.thumbnail_url !== undefined && !failedThumbnailIds.has(photo.id)"
                            :src="photo.thumbnail_url"
                            :alt="photo.original_filename"
                            class="size-full object-cover"
                            loading="lazy"
                            @error="markThumbnailFailed(photo.id)"
                        />
                        <div v-else class="grid size-full place-items-center px-4 text-center text-xs text-ink-500">照片处理中</div>
                    </div>
                    <div class="flex flex-col gap-3 p-3">
                        <p class="truncate text-xs font-semibold text-ink-950">{{ photo.original_filename }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            <span class="rounded-pill bg-sage-100 px-2.5 py-1 text-[0.68rem] font-semibold text-sage-600">
                                {{ uploadStatusLabel(photo.upload_status) }}
                            </span>
                            <span :class="['rounded-pill px-2.5 py-1 text-[0.68rem] font-semibold', reviewStatusClasses(photo.review_status)]">
                                {{ reviewStatusLabel(photo.review_status) }}
                            </span>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <p class="text-center text-xs leading-5 text-ink-500">每一张照片，都是送给新人的一份特别礼物。</p>
    </MobilePageShell>
</template>
