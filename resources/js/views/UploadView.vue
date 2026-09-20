<script setup lang="ts">
import { computed, ref } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import PageHeader from '../components/PageHeader.vue';
import MobilePageShell from '../layouts/MobilePageShell.vue';
import { calculateFileSha256 } from '../services/fileHash';

type HashStatus = 'hashing' | 'ready' | 'error';

interface SelectedPhoto {
    file: File;
    hash: string;
    id: string;
    status: HashStatus;
}

const photoInput = ref<HTMLInputElement | null>(null);
const selectedPhotos = ref<SelectedPhoto[]>([]);
let selectionVersion = 0;

const isHashing = computed(() => selectedPhotos.value.some((photo) => photo.status === 'hashing'));

function choosePhotos(): void {
    photoInput.value?.click();
}

async function handlePhotoSelection(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);
    const currentVersion = ++selectionVersion;

    selectedPhotos.value = files.map((file, index) => ({
        file,
        hash: '',
        id: `${file.name}-${file.size}-${file.lastModified}-${index}`,
        status: 'hashing',
    }));
    input.value = '';

    for (const [index, photo] of selectedPhotos.value.entries()) {
        try {
            const hash = await calculateFileSha256(photo.file);

            if (currentVersion !== selectionVersion) {
                return;
            }

            selectedPhotos.value[index] = { ...photo, hash, status: 'ready' };
        } catch {
            if (currentVersion !== selectionVersion) {
                return;
            }

            selectedPhotos.value[index] = { ...photo, status: 'error' };
        }
    }
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
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
                <BaseButton full-width :disabled="isHashing" @click="choosePhotos">
                    {{ isHashing ? '正在读取照片…' : selectedPhotos.length > 0 ? '重新选择照片' : '选择照片' }}
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
                            photo.status === 'ready'
                                ? 'bg-sage-100 text-sage-600'
                                : photo.status === 'error'
                                  ? 'bg-rose-100 text-rose-700'
                                  : 'bg-gold-100 text-gold-500',
                        ]"
                        role="status"
                        aria-live="polite"
                    >
                        {{ photo.status === 'ready' ? '校验完成' : photo.status === 'error' ? '校验失败' : '正在计算' }}
                    </span>
                </div>

                <p v-if="photo.status === 'ready'" class="break-all rounded-control bg-canvas px-3 py-2 font-mono text-[0.68rem] leading-5 text-ink-500">
                    SHA-256 · {{ photo.hash }}
                </p>
                <p v-else-if="photo.status === 'error'" class="text-xs leading-5 text-rose-700">无法读取这张照片，请重新选择后再试。</p>
            </article>
        </section>

        <p class="text-center text-xs leading-5 text-ink-500">每一张照片，都是送给新人的一份特别礼物。</p>
    </MobilePageShell>
</template>
