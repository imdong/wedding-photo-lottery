<script setup lang="ts">
import BaseButton from '../components/BaseButton.vue';
import PageHeader from '../components/PageHeader.vue';
import MobilePageShell from '../layouts/MobilePageShell.vue';
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
            <div class="mt-6">
                <BaseButton full-width disabled>照片通道准备中</BaseButton>
            </div>
        </section>

        <p class="text-center text-xs leading-5 text-ink-500">每一张照片，都是送给新人的一份特别礼物。</p>
    </MobilePageShell>
</template>
