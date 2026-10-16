<script setup lang="ts">
import { computed } from 'vue';
import { blocks } from '@/blocks/registry';
import type { BlockNode } from '@/blocks/registry';

const props = defineProps<{ node: BlockNode }>();

const definition = computed(() => blocks[props.node.type]);
</script>

<template>
    <component
        :is="definition.component"
        v-if="definition"
        :block="node.props"
        :data="node.data"
    >
        <template v-if="definition.children" #default="{ containerClass }">
            <div :class="containerClass">
                <BlockNode
                    v-for="child in node.children ?? []"
                    :key="child.id"
                    :node="child"
                />
            </div>
        </template>
    </component>
</template>
