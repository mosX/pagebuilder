<script setup lang="ts">
defineProps<{
    block: {
        title: string;
        subtitle: string;
        button_label: string;
        button_url: string;
        background: string;
        image: string;
        align: 'left' | 'center';
    };
}>();
</script>

<template>
    <section
        class="relative overflow-hidden bg-cover bg-center px-4 py-24 text-white"
        :style="{
            backgroundColor: block.background || '#1f2937',
            backgroundImage: block.image
                ? `url(${JSON.stringify(block.image)})`
                : undefined,
        }"
    >
        <div v-if="block.image" class="absolute inset-0 bg-black/50" />
        <div
            class="relative mx-auto flex max-w-4xl flex-col gap-6"
            :class="block.align === 'center' ? 'items-center text-center' : ''"
        >
            <h1 class="text-4xl font-bold md:text-5xl">{{ block.title }}</h1>
            <p
                v-if="block.subtitle"
                class="max-w-2xl text-lg whitespace-pre-line opacity-90"
            >
                {{ block.subtitle }}
            </p>
            <a
                v-if="block.button_label"
                :href="block.button_url || '#'"
                class="rounded-md bg-white px-6 py-3 font-medium text-gray-900 transition hover:bg-gray-100"
            >
                {{ block.button_label }}
            </a>
        </div>
    </section>
</template>
