<script setup lang="ts">
import MediaGrid from '@/components/media/MediaGrid.vue';
import type { MediaItem } from '@/types';

defineProps<{ selectedUrl?: string | null }>();

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
        title="Медиатека"
        width="min(1000px, 95vw)"
        append-to-body
        destroy-on-close
    >
        <MediaGrid selectable :selected-url="selectedUrl" @open="pick" />
    </el-dialog>
</template>
