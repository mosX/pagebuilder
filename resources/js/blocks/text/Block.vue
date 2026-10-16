<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    block: {
        text: string;
        size: 'small' | 'normal' | 'large';
        align: 'left' | 'center' | 'right';
    };
}>();

// Plain text only (rendered escaped): a blank line starts a new paragraph.
const paragraphs = computed(() =>
    props.block.text
        .split(/\n\s*\n/)
        .map((p) => p.trim())
        .filter(Boolean),
);

const size = { small: 'text-sm', normal: 'text-base', large: 'text-lg' };
const align = { left: 'text-left', center: 'text-center', right: 'text-right' };
</script>

<template>
    <div
        class="flex flex-col gap-4 leading-relaxed text-gray-700"
        :class="[size[block.size], align[block.align]]"
    >
        <p
            v-for="(paragraph, i) in paragraphs"
            :key="i"
            class="whitespace-pre-line"
        >
            {{ paragraph }}
        </p>
    </div>
</template>
