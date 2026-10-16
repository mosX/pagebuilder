<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    CopyDocument,
    Delete,
} from '@element-plus/icons-vue';
import { computed } from 'vue';
import { blocks } from '@/blocks/registry';
import { useBlockEditor } from '@/components/blocks/editor/context';
import PropInput from '@/components/blocks/editor/PropInput.vue';

const editor = useBlockEditor();

const node = computed(() => editor.selected.value);
const definition = computed(() =>
    node.value ? blocks[node.value.type] : null,
);
</script>

<template>
    <div v-if="node && definition" class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <div class="font-medium">{{ definition.label }}</div>
                <div
                    v-if="definition.description"
                    class="text-xs text-[var(--el-text-color-secondary)]"
                >
                    {{ definition.description }}
                </div>
            </div>
            <el-button-group size="small">
                <el-tooltip content="Выше">
                    <el-button
                        :icon="ArrowUp"
                        @click="editor.move(node.id, -1)"
                    />
                </el-tooltip>
                <el-tooltip content="Ниже">
                    <el-button
                        :icon="ArrowDown"
                        @click="editor.move(node.id, 1)"
                    />
                </el-tooltip>
                <el-tooltip content="Дублировать">
                    <el-button
                        :icon="CopyDocument"
                        @click="editor.duplicate(node.id)"
                    />
                </el-tooltip>
                <el-tooltip content="Удалить">
                    <el-button
                        type="danger"
                        :icon="Delete"
                        @click="editor.remove(node.id)"
                    />
                </el-tooltip>
            </el-button-group>
        </div>

        <el-form label-position="top" size="small" @submit.prevent>
            <el-form-item
                v-for="(schema, key) in definition.props"
                :key="key"
                :label="schema.label"
            >
                <PropInput
                    v-model="node.props[key]"
                    :schema="schema"
                    :siblings="node.props"
                />
            </el-form-item>
        </el-form>
    </div>

    <el-empty
        v-else
        :image-size="80"
        description="Выберите блок на странице, чтобы настроить его"
    />
</template>
