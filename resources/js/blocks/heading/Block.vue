<script setup lang="ts">
defineProps<{
    block: {
        text: string;
        level: 'h1' | 'h2' | 'h3' | 'h4';
        align: 'left' | 'center' | 'right';
    };
}>();

const size = {
    h1: 'text-4xl font-bold',
    h2: 'text-3xl font-bold',
    h3: 'text-2xl font-semibold',
    h4: 'text-xl font-semibold',
};

const align = { left: 'text-left', center: 'text-center', right: 'text-right' };
</script>

<template>
    <component
        :is="block.level"
        class="font-heading text-ink"
        :class="[size[block.level], align[block.align]]"
    >
        {{ block.text }}
    </component>
</template>
