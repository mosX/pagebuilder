<script setup lang="ts">
defineProps<{
    block: {
        background: string;
        padding: 'none' | 'small' | 'medium' | 'large';
        width: 'narrow' | 'normal' | 'wide' | 'full';
    };
}>();

const padding = {
    none: 'py-0',
    small: 'py-6',
    medium: 'py-12',
    large: 'py-20',
};

const width = {
    narrow: 'max-w-2xl',
    normal: 'max-w-5xl',
    wide: 'max-w-7xl',
    full: 'max-w-none',
};
</script>

<template>
    <section
        :class="padding[block.padding]"
        :style="{ backgroundColor: block.background || undefined }"
    >
        <div class="mx-auto px-4" :class="width[block.width]">
            <!-- Children are laid out by the renderer inside an element with this class. -->
            <slot container-class="flex flex-col gap-6" />
        </div>
    </section>
</template>
