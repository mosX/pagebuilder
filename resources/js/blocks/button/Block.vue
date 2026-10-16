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
    primary: 'rounded-md bg-blue-600 px-5 py-2.5 text-white hover:bg-blue-700',
    outline:
        'rounded-md border border-blue-600 px-5 py-2.5 text-blue-600 hover:bg-blue-50',
    link: 'text-blue-600 underline hover:text-blue-800',
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
