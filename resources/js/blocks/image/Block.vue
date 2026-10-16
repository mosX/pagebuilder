<script setup lang="ts">
defineProps<{
    block: {
        src: string;
        alt: string;
        caption: string;
        rounded: boolean;
    };
}>();
</script>

<template>
    <figure class="flex flex-col gap-2">
        <img
            v-if="block.src"
            :src="block.src"
            :alt="block.alt"
            class="h-auto w-full object-cover"
            :class="{ 'rounded-lg': block.rounded }"
            loading="lazy"
        />
        <div
            v-else
            class="flex aspect-video w-full items-center justify-center bg-gray-100 text-sm text-gray-400"
            :class="{ 'rounded-lg': block.rounded }"
        >
            Укажите адрес изображения
        </div>
        <figcaption
            v-if="block.caption"
            class="text-center text-sm text-gray-500"
        >
            {{ block.caption }}
        </figcaption>
    </figure>
</template>
