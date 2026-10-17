<script setup lang="ts">
import { resized } from '@/blocks/images';

defineProps<{
    block: {
        title: string;
        columns: '2' | '3' | '4';
        items: { quote: string; author: string; role: string; photo: string }[];
    };
}>();

const columns = {
    '2': '@2xl:grid-cols-2',
    '3': '@2xl:grid-cols-2 @5xl:grid-cols-3',
    '4': '@2xl:grid-cols-2 @5xl:grid-cols-4',
};
</script>

<template>
    <div class="flex flex-col gap-8">
        <h2
            v-if="block.title"
            class="text-center font-heading text-3xl font-bold text-ink"
        >
            {{ block.title }}
        </h2>
        <div class="grid grid-cols-1 gap-6" :class="columns[block.columns]">
            <figure
                v-for="(item, i) in block.items"
                :key="i"
                class="flex flex-col gap-4 rounded-site bg-gray-50 p-6"
            >
                <blockquote class="whitespace-pre-line text-ink">
                    «{{ item.quote }}»
                </blockquote>
                <figcaption class="mt-auto flex items-center gap-3">
                    <img
                        v-if="item.photo"
                        :src="resized(item.photo, 320)"
                        alt=""
                        loading="lazy"
                        class="h-10 w-10 rounded-full object-cover"
                    />
                    <div class="text-sm">
                        <div class="font-semibold text-ink">
                            {{ item.author }}
                        </div>
                        <div v-if="item.role" class="text-gray-500">
                            {{ item.role }}
                        </div>
                    </div>
                </figcaption>
            </figure>
        </div>
    </div>
</template>
