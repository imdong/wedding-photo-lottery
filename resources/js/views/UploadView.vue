<script setup lang="ts">
import { computed, ref } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import PageHeader from '../components/PageHeader.vue';
import MobilePageShell from '../layouts/MobilePageShell.vue';
import { calculateFileSha256 } from '../services/fileHash';
import { preparePhotoUploads } from '../services/photoUploads';

type PhotoStatus = 'hashing' | 'checking' | 'ready' | 'duplicate' | 'error';

interface SelectedPhoto {
    errorMessage: string;
    file: File;
    id: string;
    objectKey: string | null;
    originalHash: string;
    photoId: number | null;
    status: PhotoStatus;
    uploadHash: string;
}

const photoInput = ref<HTMLInputElement | null>(null);
const selectedPhotos = ref<SelectedPhoto[]>([]);
let selectionVersion = 0;

const isProcessing = computed(() => selectedPhotos.value.some((photo) => photo.status === 'hashing' || photo.status === 'checking'));

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
        status: 'hashing',
        uploadHash: '',
    }));
    input.value = '';

    for (const [index, photo] of selectedPhotos.value.entries()) {
        try {
            const hash = await calculateFileSha256(photo.file);

            if (currentVersion !== selectionVersion) {
                return;
            }

            selectedPhotos.value[index] = {
                ...photo,
                originalHash: hash,
                status: 'checking',
                uploadHash: hash,
            };
        } catch {
            if (currentVersion !== selectionVersion) {
                return;
            }

            selectedPhotos.value[index] = {
                ...photo,
                errorMessage: '无法读取这张照片，请重新选择后再试。',
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
                file_size: photo.file.size,
                filename: photo.file.name,
                mime_type: photo.file.type,
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
                errorMessage: '暂时无法检查照片，请稍后重新选择。',
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

function statusLabel(status: PhotoStatus): string {
    const labels: Record<PhotoStatus, string> = {
        hashing: '正在计算',
        checking: '正在查重',
        ready: '等待上传',
        duplicate: '已存在 · 已跳过',
        error: '处理失败',
    };

    return labels[status];
}

function statusClasses(status: PhotoStatus): string {
    if (status === 'ready') {
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
                        {{ statusLabel(photo.status) }}
                    </span>
                </div>

                <p
                    v-if="photo.originalHash"
                    class="break-all rounded-control bg-canvas px-3 py-2 font-mono text-[0.68rem] leading-5 text-ink-500"
                >
                    原始 SHA-256 · {{ photo.originalHash }}
                </p>
                <p v-if="photo.status === 'duplicate'" class="text-xs leading-5 text-gold-500">服务器中已有相同文件，本次上传将自动跳过。</p>
                <p v-else-if="photo.status === 'error'" class="text-xs leading-5 text-rose-700">{{ photo.errorMessage }}</p>
            </article>
        </section>

        <p class="text-center text-xs leading-5 text-ink-500">每一张照片，都是送给新人的一份特别礼物。</p>
    </MobilePageShell>
</template>
