<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    block: {
        columns: '2' | '3' | '4';
        gap: 'small' | 'medium' | 'large';
    };
}>();

const columns = {
    '2': '@3xl:grid-cols-2',
    '3': '@3xl:grid-cols-3',
    '4': '@3xl:grid-cols-2 @5xl:grid-cols-4',
};

const gap = { small: 'gap-3', medium: 'gap-6', large: 'gap-10' };

const containerClass = computed(
    () =>
        `grid grid-cols-1 ${columns[props.block.columns]} ${gap[props.block.gap]}`,
);
</script>

<template>
    <div>
        <slot :container-class="containerClass" />
    </div>
</template>
