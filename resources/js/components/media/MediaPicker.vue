<script setup lang="ts">
import MediaGrid from '@/components/media/MediaGrid.vue';
import type { MediaItem } from '@/types';

withDefaults(
    defineProps<{
        selectedUrl?: string | null;
        /** Pictures by default; documents for download links. */
        kind?: 'image' | 'document';
    }>(),
    { selectedUrl: null, kind: 'image' },
);

const open = defineModel<boolean>({ required: true });
const emit = defineEmits<{ pick: [item: MediaItem] }>();

function pick(item: MediaItem) {
    emit('pick', item);
    open.value = false;
}
</script>

<template>
    <el-dialog
        v-model="open"
        :title="kind === 'document' ? 'Документы' : 'Медиатека'"
        width="min(1000px, 95vw)"
        append-to-body
        destroy-on-close
    >
        <MediaGrid
            selectable
            :kind="kind"
            :selected-url="selectedUrl"
            @open="pick"
        />
    </el-dialog>
</template>
