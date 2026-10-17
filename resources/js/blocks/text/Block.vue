<script setup lang="ts">
import RichText from '@/components/blocks/RichText';
import type { RichNode } from '@/components/blocks/RichText';

defineProps<{
    block: {
        text: RichNode | string;
        size: 'small' | 'normal' | 'large';
        align: 'left' | 'center' | 'right';
    };
}>();

const size = { small: 'text-sm', normal: 'text-base', large: 'text-lg' };
const align = { left: 'text-left', center: 'text-center', right: 'text-right' };
</script>

<template>
    <RichText
        :doc="block.text"
        class="leading-relaxed text-gray-700"
        :class="[size[block.size], align[block.align]]"
    />
</template>
