<script setup lang="ts">
import { onMounted, ref } from 'vue';
import api from '../services/api';

interface HealthCheckResponse {
    status: 'ok' | 'degraded';
    application: 'ok';
    database: 'ok' | 'unavailable';
}

type ApiStatus = 'checking' | 'available' | 'unavailable';

const apiStatus = ref<ApiStatus>('checking');

onMounted(async () => {
    try {
        const response = await api.get<HealthCheckResponse>('/health');

        apiStatus.value = response.data.status === 'ok' ? 'available' : 'unavailable';
    } catch {
        apiStatus.value = 'unavailable';
    }
});
</script>

<template>
    <main>
        <h1>婚礼照片上传</h1>
        <p v-if="apiStatus === 'checking'" data-testid="api-status">正在连接服务…</p>
        <p v-else-if="apiStatus === 'available'" data-testid="api-status">服务连接正常</p>
        <p v-else data-testid="api-status">服务暂时不可用</p>
    </main>
</template>
