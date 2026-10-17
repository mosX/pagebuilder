<script setup lang="ts">
import { srcset } from '@/blocks/images';

defineProps<{
    block: {
        columns: '2' | '3' | '4';
        ratio: 'square' | 'landscape' | 'auto';
        items: { src: string; alt: string; caption: string }[];
    };
}>();

const columns = {
    '2': 'grid-cols-2',
    '3': 'grid-cols-2 @3xl:grid-cols-3',
    '4': 'grid-cols-2 @3xl:grid-cols-4',
};

const sizes = {
    '2': '(min-width: 768px) 50vw, 50vw',
    '3': '(min-width: 768px) 33vw, 50vw',
    '4': '(min-width: 768px) 25vw, 50vw',
};

const ratio = {
    square: 'aspect-square object-cover',
    landscape: 'aspect-[4/3] object-cover',
    auto: 'h-auto',
};
</script>

<template>
    <div>
        <div
            v-if="!block.items.some((item) => item.src)"
            class="rounded-site border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400"
        >
            Добавьте изображения в настройках блока
        </div>
        <div v-else class="grid gap-3" :class="columns[block.columns]">
            <figure
                v-for="(item, i) in block.items.filter((image) => image.src)"
                :key="i"
                class="flex flex-col gap-1"
            >
                <a :href="item.src" target="_blank" rel="noopener">
                    <img
                        :src="item.src"
                        :srcset="srcset(item.src)"
                        :sizes="sizes[block.columns]"
                        :alt="item.alt"
                        loading="lazy"
                        class="w-full rounded-site"
                        :class="ratio[block.ratio]"
                    />
                </a>
                <figcaption
                    v-if="item.caption"
                    class="text-center text-sm text-gray-500"
                >
                    {{ item.caption }}
                </figcaption>
            </figure>
        </div>
    </div>
</template>
