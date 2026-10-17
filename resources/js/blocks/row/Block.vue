<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    block: {
        justify: 'start' | 'center' | 'end' | 'between';
        gap: 'small' | 'medium' | 'large';
        stack_on_mobile: boolean;
    };
}>();

const justify = {
    start: 'justify-start',
    center: 'justify-center',
    end: 'justify-end',
    between: 'justify-between',
};

const gap = { small: 'gap-2', medium: 'gap-4', large: 'gap-8' };

const containerClass = computed(() =>
    [
        'flex flex-wrap items-center',
        justify[props.block.justify],
        gap[props.block.gap],
        props.block.stack_on_mobile
            ? '@max-3xl:flex-col @max-3xl:items-start'
            : '',
    ].join(' '),
);
</script>

<template>
    <div>
        <slot :container-class="containerClass" />
    </div>
</template>
