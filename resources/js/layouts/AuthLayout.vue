<script setup lang="ts">
const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center justify-center bg-[var(--el-bg-color-page)] p-4"
    >
        <div class="mb-6 text-2xl font-semibold text-[var(--el-color-primary)]">
            {{ appName }}
        </div>
        <div class="w-full max-w-md">
            <router-view />
        </div>
    </div>
</template>
