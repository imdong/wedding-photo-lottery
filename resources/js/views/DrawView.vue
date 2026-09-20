<script setup lang="ts">
import BaseButton from '../components/BaseButton.vue';
import PageHeader from '../components/PageHeader.vue';
import DesktopPageShell from '../layouts/DesktopPageShell.vue';
</script>

<template>
    <DesktopPageShell section-label="现场抽奖">
        <PageHeader
            align="center"
            eyebrow="Lucky Moment"
            title="让今晚的幸运，在照片里发生"
            description="所有审核通过的照片都将在这里参与抽奖，现场点赞会轻微提升照片被选中的机会。"
        />

        <section class="grid min-h-[30rem] place-items-center rounded-card border border-white/85 bg-white/68 p-8 shadow-shell backdrop-blur-sm">
            <div class="flex max-w-md flex-col items-center gap-6 text-center">
                <div class="relative grid size-28 place-items-center rounded-full border border-gold-300/80 bg-paper shadow-card">
                    <span class="font-display text-5xl text-rose-600" aria-hidden="true">囍</span>
                    <span class="absolute -inset-3 rounded-full border border-dashed border-rose-300/70"></span>
                </div>
                <div class="flex flex-col gap-2">
                    <h2 class="font-display text-3xl font-semibold text-ink-950">等待照片入场</h2>
                    <p class="leading-7 text-ink-500">审核通过的照片会铺满舞台，准备好后即可开始抽奖。</p>
                </div>
                <BaseButton disabled>开始抽奖</BaseButton>
            </div>
        </section>
    </DesktopPageShell>
</template>
