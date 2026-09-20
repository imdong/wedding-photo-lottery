<script setup lang="ts">
import BaseButton from '../components/BaseButton.vue';
import PageHeader from '../components/PageHeader.vue';
import DesktopPageShell from '../layouts/DesktopPageShell.vue';
</script>

<template>
    <DesktopPageShell section-label="照片审核">
        <PageHeader eyebrow="Review Desk" title="快速审核现场照片" description="逐张确认照片是否适合进入照片墙，并为通过的照片设置抽奖等级。" />

        <section class="grid gap-6 lg:grid-cols-[minmax(0,1.45fr)_minmax(20rem,0.55fr)]">
            <div class="grid min-h-[32rem] place-items-center rounded-card border border-white/85 bg-white/68 p-8 shadow-card backdrop-blur-sm">
                <div class="flex max-w-sm flex-col items-center gap-4 text-center">
                    <span class="grid size-16 place-items-center rounded-full bg-rose-50 text-2xl text-rose-600" aria-hidden="true">✓</span>
                    <h2 class="font-display text-3xl font-semibold text-ink-950">暂无待审核照片</h2>
                    <p class="leading-7 text-ink-500">宾客上传后，照片会按时间顺序出现在这里。</p>
                </div>
            </div>

            <aside class="flex flex-col gap-5 rounded-card border border-white/85 bg-paper/85 p-6 shadow-card backdrop-blur-sm">
                <div class="flex items-center justify-between gap-4 border-b border-rose-100 pb-5">
                    <div>
                        <p class="text-xs font-semibold tracking-[0.2em] text-rose-600 uppercase">待处理</p>
                        <p class="mt-1 font-display text-4xl font-semibold text-ink-950">0</p>
                    </div>
                    <span class="rounded-pill bg-sage-100 px-3 py-1.5 text-xs font-semibold text-sage-600">队列已清空</span>
                </div>

                <div class="flex flex-col gap-3">
                    <p class="text-sm font-semibold text-ink-700">审核操作</p>
                    <BaseButton full-width disabled>好 · 2 倍权重</BaseButton>
                    <BaseButton full-width disabled variant="secondary">普通 · 1 倍权重</BaseButton>
                    <BaseButton full-width disabled variant="quiet">不要 · 不参与抽奖</BaseButton>
                </div>
            </aside>
        </section>
    </DesktopPageShell>
</template>
