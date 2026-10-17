<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    block: {
        url: string;
        caption: string;
    };
}>();

/**
 * Only YouTube and Vimeo are embedded, through their privacy-friendly players;
 * any other link is shown as a plain link instead of an arbitrary iframe.
 */
const embed = computed(() => {
    const url = props.block.url;
    const youtube = url.match(
        /(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/)|youtu\.be\/)([\w-]{11})/,
    );

    if (youtube) {
        return `https://www.youtube-nocookie.com/embed/${youtube[1]}`;
    }

    const vimeo = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);

    return vimeo ? `https://player.vimeo.com/video/${vimeo[1]}?dnt=1` : null;
});
</script>

<template>
    <figure class="flex flex-col gap-2">
        <div
            v-if="embed"
            class="aspect-video w-full overflow-hidden rounded-site bg-black"
        >
            <iframe
                :src="embed"
                class="h-full w-full"
                title="Видео"
                loading="lazy"
                allow="
                    accelerometer;
                    autoplay;
                    clipboard-write;
                    encrypted-media;
                    gyroscope;
                    picture-in-picture;
                "
                allowfullscreen
                referrerpolicy="strict-origin-when-cross-origin"
            />
        </div>
        <div
            v-else-if="!block.url"
            class="flex aspect-video w-full items-center justify-center rounded-site bg-gray-100 text-sm text-gray-400"
        >
            Вставьте ссылку на YouTube или Vimeo
        </div>
        <a v-else :href="block.url" class="text-brand underline" rel="noopener">
            {{ block.url }}
        </a>
        <figcaption
            v-if="block.caption"
            class="text-center text-sm text-gray-500"
        >
            {{ block.caption }}
        </figcaption>
    </figure>
</template>
