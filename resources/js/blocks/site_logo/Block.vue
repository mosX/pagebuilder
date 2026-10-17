<script setup lang="ts">
import { resized } from '@/blocks/images';

defineProps<{
    block: {
        size: 'small' | 'medium' | 'large';
        show_name: boolean;
    };
    data?: { name: string; logo: string | null; url: string };
}>();

const height = { small: 'h-7', medium: 'h-10', large: 'h-14' };
const text = { small: 'text-base', medium: 'text-lg', large: 'text-2xl' };
</script>

<template>
    <a
        :href="data?.url ?? '/'"
        class="inline-flex items-center gap-3 font-heading font-bold text-ink"
        :class="text[block.size]"
    >
        <img
            v-if="data?.logo"
            :src="resized(data.logo, 640)"
            :alt="data.name"
            class="w-auto object-contain"
            :class="height[block.size]"
        />
        <span v-if="!data?.logo || block.show_name">{{
            data?.name ?? 'Компания'
        }}</span>
    </a>
</template>
