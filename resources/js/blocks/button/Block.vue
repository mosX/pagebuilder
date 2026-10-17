<script setup lang="ts">
defineProps<{
    block: {
        label: string;
        url: string;
        variant: 'primary' | 'outline' | 'link';
        align: 'left' | 'center' | 'right';
    };
}>();

const variant = {
    primary:
        'rounded-site bg-brand px-5 py-2.5 text-brand-contrast hover:bg-brand/90',
    outline:
        'rounded-site border border-brand px-5 py-2.5 text-brand hover:bg-brand/5',
    link: 'text-brand underline hover:opacity-80',
};

const align = {
    left: 'justify-start',
    center: 'justify-center',
    right: 'justify-end',
};
</script>

<template>
    <div class="flex" :class="align[block.align]">
        <a
            :href="block.url || '#'"
            class="inline-block font-medium transition"
            :class="variant[block.variant]"
        >
            {{ block.label }}
        </a>
    </div>
</template>
