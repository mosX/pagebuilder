<script setup lang="ts">
import { computed } from 'vue';
import { normalizeTree } from '@/blocks/registry';
import BlockNode from '@/components/blocks/BlockNode.vue';

const props = defineProps<{
    block: { pattern: number | null };
    /** The section's blocks, resolved by the server. */
    data?: { name: string; blocks: unknown } | null;
}>();

const nodes = computed(() => normalizeTree(props.data?.blocks));
</script>

<template>
    <div>
        <BlockNode v-for="node in nodes" :key="node.id" :node="node" />
        <!-- Only reachable in the editor: on the site an empty section renders nothing. -->
        <div
            v-if="!block.pattern"
            class="rounded border border-dashed border-gray-300 p-4 text-center text-sm text-gray-400"
        >
            Выберите секцию в настройках блока
        </div>
    </div>
</template>
