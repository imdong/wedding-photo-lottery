<script setup lang="ts">
import { KeepAlive } from 'vue';
import { RouterView } from 'vue-router';
</script>

<template>
    <div class="min-h-screen bg-canvas text-ink-950 antialiased selection:bg-rose-100 selection:text-rose-700">
        <RouterView v-slot="{ Component, route }">
            <KeepAlive>
                <component :is="Component" v-if="route.meta.keepAlive" :key="route.name" />
            </KeepAlive>
            <component :is="Component" v-if="!route.meta.keepAlive" :key="route.name" />
        </RouterView>
    </div>
</template>
