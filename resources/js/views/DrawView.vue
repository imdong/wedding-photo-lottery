<script setup lang="ts">
import { isAxiosError } from 'axios';
import { computed, ref, watch } from 'vue';
import BaseButton from '../components/BaseButton.vue';
import {
    executeDrawRound,
    flattenCandidatePhotos,
    preloadCandidateDrawImages,
    prepareDrawRound,
    type DrawCandidatePhoto,
    type DrawResult,
    type DrawImagePreloadProgress,
    type PreparedDrawRound,
} from '../services/drawRounds';

interface ApiErrorResponse {
    message?: string;
}

type PreparationStatus = 'idle' | 'preparing' | 'prepared' | 'error';
type DrawRequestStatus = 'idle' | 'requesting' | 'ready' | 'error';

const photoTiles = [
    { frame: 'left-[3%] top-[12%] h-[34%] w-[24%] -rotate-6', tone: 'from-rose-100 via-[#f7d8d5] to-gold-100' },
    { frame: 'left-[23%] top-[4%] h-[29%] w-[20%] rotate-3', tone: 'from-gold-100 via-[#f1dfc4] to-[#dac8bd]' },
    { frame: 'right-[29%] top-[8%] h-[36%] w-[22%] -rotate-2', tone: 'from-sage-100 via-[#d9ded3] to-gold-100' },
    { frame: 'right-[4%] top-[16%] h-[30%] w-[24%] rotate-6', tone: 'from-[#e5d4d4] via-rose-100 to-[#f4dfd0]' },
    { frame: 'bottom-[8%] left-[8%] h-[36%] w-[22%] rotate-4', tone: 'from-[#d8d3c8] via-gold-100 to-rose-100' },
    { frame: 'bottom-[2%] left-[32%] h-[32%] w-[24%] -rotate-3', tone: 'from-rose-100 via-[#ecd2d6] to-[#d5c8bd]' },
    { frame: 'right-[18%] bottom-[5%] h-[33%] w-[22%] rotate-2', tone: 'from-gold-100 via-[#eadfc9] to-sage-100' },
    { frame: 'right-[1%] bottom-[14%] h-[28%] w-[18%] -rotate-5', tone: 'from-[#dfc8c8] via-rose-100 to-gold-100' },
];

const candidatePhotos = ref<DrawCandidatePhoto[]>([]);
const preparedRound = ref<PreparedDrawRound | null>(null);
const preparationStatus = ref<PreparationStatus>('idle');
const preparationError = ref('');
const drawResult = ref<DrawResult | null>(null);
const drawRequestStatus = ref<DrawRequestStatus>('idle');
const drawError = ref('');
const failedThumbnailIds = ref(new Set<number>());
const failedDrawImageIds = ref(new Set<number>());
const preloadProgress = ref<DrawImagePreloadProgress>({
    completed: 0,
    failed: 0,
    loaded: 0,
    total: 0,
});
const displayTiles = computed(() => photoTiles.map((tile, index) => ({
    ...tile,
    photo: candidatePhotos.value[index] ?? null,
})));
const preloadStatus = computed(() => {
    if (preloadProgress.value.total === 0) {
        return '等待照片';
    }

    if (preloadProgress.value.completed < preloadProgress.value.total) {
        return `大图加载 ${preloadProgress.value.completed}/${preloadProgress.value.total}`;
    }

    if (preloadProgress.value.failed > 0) {
        return `${preloadProgress.value.loaded} 张已就绪，${preloadProgress.value.failed} 张失败`;
    }

    return `${preloadProgress.value.loaded} 张大图已就绪`;
});
const canStartDraw = computed(() => (
    preparedRound.value !== null
    && drawResult.value === null
    && drawRequestStatus.value !== 'requesting'
    && preloadProgress.value.total > 0
    && preloadProgress.value.completed === preloadProgress.value.total
));
const stageStatus = computed(() => {
    if (drawRequestStatus.value === 'requesting') {
        return '正在确定结果';
    }

    if (drawRequestStatus.value === 'ready') {
        return '结果已确定';
    }

    if (drawRequestStatus.value === 'error') {
        return '抽奖失败';
    }

    if (preparationStatus.value === 'preparing') {
        return '正在准备';
    }

    if (preparationStatus.value === 'prepared') {
        return preloadProgress.value.completed < preloadProgress.value.total
            ? '正在加载照片'
            : '本轮已准备';
    }

    if (preparationStatus.value === 'error') {
        return '准备失败';
    }

    return '等待准备';
});

watch(candidatePhotos, async (photos) => {
    failedThumbnailIds.value = new Set();
    failedDrawImageIds.value = new Set();

    const result = await preloadCandidateDrawImages(photos, (progress) => {
        preloadProgress.value = progress;
    });

    failedDrawImageIds.value = new Set(result.failedPhotoIds);
});

function markThumbnailAsFailed(photoId: number): void {
    failedThumbnailIds.value.add(photoId);
}

async function prepareRound(): Promise<void> {
    if (preparationStatus.value === 'preparing' || preparedRound.value !== null) {
        return;
    }

    preparationStatus.value = 'preparing';
    preparationError.value = '';

    try {
        const round = await prepareDrawRound();

        preparedRound.value = round;
        candidatePhotos.value = flattenCandidatePhotos(round);
        preparationStatus.value = 'prepared';
    } catch (error: unknown) {
        preparationError.value = isAxiosError<ApiErrorResponse>(error)
            ? error.response?.data.message ?? '准备失败，请稍后重试。'
            : '准备失败，请稍后重试。';
        preparationStatus.value = 'error';
    }
}

async function startDraw(): Promise<void> {
    if (! canStartDraw.value || preparedRound.value === null) {
        return;
    }

    drawRequestStatus.value = 'requesting';
    drawError.value = '';

    try {
        drawResult.value = await executeDrawRound(preparedRound.value.id);
        drawRequestStatus.value = 'ready';
    } catch (error: unknown) {
        drawError.value = isAxiosError<ApiErrorResponse>(error)
            ? error.response?.data.message ?? '抽奖失败，请稍后重试。'
            : '抽奖失败，请稍后重试。';
        drawRequestStatus.value = 'error';
    }
}
</script>

<template>
    <div class="relative flex h-dvh min-h-[720px] overflow-hidden bg-[#21191c] text-white">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_50%_-10%,rgb(190_120_131_/_0.3),transparent_42%),radial-gradient(circle_at_8%_88%,rgb(185_140_76_/_0.2),transparent_34%)]"></div>
        <div class="pointer-events-none absolute inset-0 opacity-[0.035] [background-image:linear-gradient(rgb(255_255_255)_1px,transparent_1px),linear-gradient(90deg,rgb(255_255_255)_1px,transparent_1px)] [background-size:4rem_4rem]"></div>

        <div class="relative z-10 flex min-w-0 flex-1 flex-col">
            <header class="flex h-20 shrink-0 items-center justify-between border-b border-white/10 px-8 2xl:h-24 2xl:px-12">
                <div class="flex items-center gap-4">
                    <span class="grid size-11 place-items-center rounded-full border border-gold-300/60 bg-white/8 font-display text-2xl text-gold-100 2xl:size-12">囍</span>
                    <div>
                        <p class="font-display text-lg font-semibold tracking-[0.12em] text-white 2xl:text-xl">我们的婚礼相册</p>
                        <p class="mt-0.5 text-[0.62rem] tracking-[0.34em] text-white/45 uppercase">Wedding Lucky Moment</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 rounded-pill border border-white/10 bg-white/6 px-4 py-2 text-sm text-white/65 backdrop-blur-xl">
                    <span class="size-2 rounded-full bg-gold-300 shadow-[0_0_0_5px_rgb(223_194_143_/_0.12)]"></span>
                    {{ stageStatus }}
                </div>
            </header>

            <main class="grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_22rem] gap-5 p-5 2xl:grid-cols-[minmax(0,1fr)_27rem] 2xl:gap-7 2xl:p-7">
                <section class="grid min-h-0 grid-rows-[auto_minmax(0,1fr)_auto] overflow-hidden rounded-[2rem] border border-white/10 bg-white/5 shadow-[0_30px_90px_rgb(0_0_0_/_0.28)] backdrop-blur-sm">
                    <div class="flex items-start justify-between gap-8 px-8 pt-7 2xl:px-11 2xl:pt-9">
                        <div class="max-w-3xl">
                            <p class="text-xs font-semibold tracking-[0.32em] text-gold-300 uppercase">Tonight's Memories</p>
                            <h1 class="mt-3 font-display text-[clamp(2rem,3vw,3.75rem)] leading-tight font-semibold tracking-[0.04em] text-white">
                                让今晚的幸运，在照片里发生
                            </h1>
                            <p class="mt-2 text-sm leading-6 text-white/50 2xl:text-base">每一张被珍藏的瞬间，都有机会成为今晚的幸运主角。</p>
                        </div>

                        <div class="hidden shrink-0 gap-8 pt-2 text-right xl:flex">
                            <div>
                                <p class="text-2xl font-semibold text-white">{{ candidatePhotos.length || '—' }}</p>
                                <p class="mt-1 text-xs tracking-wider text-white/40">候选照片</p>
                            </div>
                            <div class="h-11 w-px bg-white/10"></div>
                            <div>
                                <p class="text-2xl font-semibold text-white">{{ preparedRound?.draw_count ?? '—' }}</p>
                                <p class="mt-1 text-xs tracking-wider text-white/40">本轮名额</p>
                            </div>
                        </div>
                    </div>

                    <div class="relative min-h-0 overflow-hidden px-6 py-4 2xl:px-9 2xl:py-6" aria-label="候选照片展示区域">
                        <div class="absolute inset-x-[4%] inset-y-[7%] rounded-[45%] bg-rose-500/10 blur-3xl"></div>

                        <div
                            v-for="(tile, index) in displayTiles"
                            :key="index"
                            :class="[
                                'absolute overflow-hidden rounded-2xl border border-white/15 bg-linear-to-br p-1.5 opacity-35 shadow-2xl',
                                tile.frame,
                                tile.tone,
                            ]"
                            aria-hidden="true"
                        >
                            <img
                                v-if="tile.photo !== null && !failedThumbnailIds.has(tile.photo.photo_id)"
                                :src="tile.photo.thumbnail_url"
                                :alt="`候选照片 ${index + 1}`"
                                class="h-full w-full rounded-[0.7rem] object-cover"
                                loading="eager"
                                decoding="async"
                                @error="markThumbnailAsFailed(tile.photo.photo_id)"
                            />
                            <div
                                v-else
                                class="flex h-full items-end rounded-[0.7rem] border border-white/20 bg-black/5 p-3"
                                :aria-label="tile.photo === null ? undefined : '照片暂时无法显示'"
                            >
                                <span class="h-1.5 w-12 rounded-full bg-white/35"></span>
                            </div>
                        </div>

                        <div v-if="candidatePhotos.length === 0" class="absolute inset-0 grid place-items-center">
                            <div class="flex max-w-sm flex-col items-center gap-4 text-center">
                                <div class="grid size-20 place-items-center rounded-full border border-gold-300/35 bg-[#2a2023]/85 shadow-[0_18px_60px_rgb(0_0_0_/_0.35)] backdrop-blur-xl 2xl:size-24">
                                    <span class="font-display text-4xl text-gold-100 2xl:text-5xl">囍</span>
                                </div>
                                <div>
                                    <h2 class="font-display text-xl font-semibold tracking-wider text-white 2xl:text-2xl">照片正在候场</h2>
                                    <p class="mt-2 text-sm text-white/45">准备后将载入本轮候选照片</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-6 border-t border-white/10 bg-black/10 px-8 py-5 2xl:px-11 2xl:py-6">
                        <div class="flex items-center gap-3 text-sm text-white/45">
                            <span class="grid size-8 place-items-center rounded-full border border-white/10 bg-white/5 text-gold-300">✦</span>
                            <span>{{ preloadStatus }}</span>
                            <span v-if="failedDrawImageIds.size > 0" class="text-rose-300">失败照片将使用占位图</span>
                            <span v-if="preparationError !== ''" class="text-rose-300">{{ preparationError }}</span>
                            <span v-if="drawError !== ''" class="text-rose-300">{{ drawError }}</span>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <BaseButton
                                class="min-w-36 2xl:min-w-40"
                                variant="secondary"
                                :disabled="preparationStatus === 'preparing' || preparedRound !== null"
                                @click="prepareRound"
                            >
                                {{ preparationStatus === 'preparing' ? '准备中…' : preparedRound === null ? '准备抽奖' : `第 ${preparedRound.round_number} 轮已准备` }}
                            </BaseButton>
                            <BaseButton
                                class="min-w-40 2xl:min-w-48"
                                :disabled="!canStartDraw"
                                @click="startDraw"
                            >
                                {{ drawRequestStatus === 'requesting' ? '正在确定…' : drawResult === null ? '开始抽奖' : '结果已确定' }}
                            </BaseButton>
                        </div>
                    </div>
                </section>

                <aside class="flex min-h-0 flex-col overflow-hidden rounded-[2rem] border border-white/12 bg-paper text-ink-950 shadow-[0_30px_90px_rgb(0_0_0_/_0.32)]">
                    <div class="border-b border-rose-100 px-6 py-6 2xl:px-8 2xl:py-8">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-[0.68rem] font-semibold tracking-[0.26em] text-rose-600 uppercase">Lucky List</p>
                                <h2 class="mt-2 font-display text-2xl font-semibold 2xl:text-3xl">本轮幸运时刻</h2>
                            </div>
                            <span class="rounded-pill bg-rose-100 px-3 py-1.5 text-xs font-semibold text-rose-700">
                                {{ drawResult === null ? '尚未开始' : '结果待揭晓' }}
                            </span>
                        </div>
                    </div>

                    <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden p-5 2xl:gap-4 2xl:p-7">
                        <div
                            v-for="position in preparedRound?.draw_count ?? 3"
                            :key="position"
                            class="flex min-h-0 flex-1 items-center gap-4 rounded-2xl border border-dashed border-rose-300/55 bg-white/70 p-3.5 2xl:p-4"
                        >
                            <div class="grid size-14 shrink-0 place-items-center rounded-xl bg-rose-50 text-sm font-semibold text-rose-500 2xl:size-16">
                                {{ position }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="h-2.5 w-24 rounded-full bg-rose-100"></div>
                                <div class="mt-2.5 h-2 w-16 rounded-full bg-gold-100"></div>
                            </div>
                            <span class="text-xs text-ink-500">待揭晓</span>
                        </div>
                    </div>

                    <div class="border-t border-rose-100 bg-white/55 px-6 py-5 2xl:px-8">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-semibold text-ink-700">历史中奖</span>
                            <span class="text-ink-500">暂无记录</span>
                        </div>
                    </div>
                </aside>
            </main>
        </div>
    </div>
</template>
