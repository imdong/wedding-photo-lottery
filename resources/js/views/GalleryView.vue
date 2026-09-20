<script setup lang="ts">
import PageHeader from '../components/PageHeader.vue';
import MobilePageShell from '../layouts/MobilePageShell.vue';
</script>

<template>
    <MobilePageShell>
        <PageHeader eyebrow="共享回忆" title="亲友照片墙" description="审核通过的婚礼瞬间会出现在这里，大家可以为喜欢的照片送上心意。" />

        <section class="rounded-card border border-white/80 bg-white/72 p-5 shadow-card backdrop-blur-sm">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-ink-950">照片正在汇集</p>
                    <p class="mt-1 text-xs text-ink-500">婚礼当天，这里会热闹起来</p>
                </div>
                <span class="rounded-pill bg-gold-100 px-3 py-1.5 text-xs font-semibold text-gold-500">0 张</span>
            </div>
        </section>

        <section class="grid grid-cols-2 gap-3" aria-label="照片墙占位">
            <div
                v-for="index in 4"
                :key="index"
                :class="[
                    'relative overflow-hidden rounded-card border border-white/75 bg-white/55 shadow-card',
                    index % 3 === 0 ? 'aspect-[4/5]' : 'aspect-square',
                ]"
            >
                <div class="absolute inset-0 bg-linear-to-br from-rose-50 via-paper to-gold-100/80"></div>
                <div class="absolute inset-x-4 bottom-4 h-2 rounded-pill bg-white/80"></div>
            </div>
        </section>
    </MobilePageShell>
</template>
