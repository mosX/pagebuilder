<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    CopyDocument,
    Delete,
    DocumentCopy,
    FolderAdd,
} from '@element-plus/icons-vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { computed } from 'vue';
import {
    blocks,
    createNode,
    devices,
    locate,
    serializeTree,
} from '@/blocks/registry';
import type { Device } from '@/blocks/registry';
import { useBlockEditor } from '@/components/blocks/editor/context';
import PropInput from '@/components/blocks/editor/PropInput.vue';
import { send } from '@/lib/http';
import patternsApi from '@/routes/api/patterns';
import { usePatternsStore } from '@/stores/patterns';

const editor = useBlockEditor();
const patterns = usePatternsStore();

const node = computed(() => editor.selected.value);
const definition = computed(() =>
    node.value ? blocks[node.value.type] : null,
);

function setHidden(value: unknown) {
    const hide = (Array.isArray(value) ? value : []) as Device[];

    if (node.value) {
        node.value.hide = hide.length ? hide : undefined;
    }
}

/**
 * Saves the selected block as a reusable section and puts a reference to it in its place,
 * so later edits of the section show up here too.
 */
async function saveAsPattern() {
    const current = node.value;

    if (!current) {
        return;
    }

    try {
        const { value: name } = await ElMessageBox.prompt(
            'Название секции',
            'Сохранить как секцию',
            {
                inputValue: blocks[current.type]?.label ?? 'Секция',
                confirmButtonText: 'Сохранить',
                cancelButtonText: 'Отмена',
                inputValidator: (value) =>
                    Boolean(value?.trim()) || 'Введите название',
            },
        );

        const result = await send<{ message: string; pattern: { id: number } }>(
            patternsApi.store(),
            { name, tree: serializeTree([current]) },
        );

        const reference = createNode('pattern');
        reference.props.pattern = result.pattern.id;
        const found = locate(editor.tree.value, current.id);
        found?.list.splice(found.index, 1, reference);
        editor.select(reference.id);

        await patterns.load();
        ElMessage.success(result.message);
    } catch {
        // Cancelled or failed (the error is already shown).
    }
}

function copyBlock() {
    if (node.value) {
        editor.copy(node.value.id);
        ElMessage.success(
            'Блок скопирован. Вставьте его на любой странице (Ctrl+V).',
        );
    }
}
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
                <el-tooltip content="Копировать (Ctrl+C)">
                    <el-button :icon="DocumentCopy" @click="copyBlock" />
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

            <el-divider />
            <el-form-item v-if="node.type !== 'pattern'">
                <el-button
                    size="small"
                    :icon="FolderAdd"
                    @click="saveAsPattern"
                >
                    Сохранить как секцию
                </el-button>
            </el-form-item>
            <el-form-item label="Скрыть на устройствах">
                <el-checkbox-group
                    :model-value="node.hide ?? []"
                    @update:model-value="setHidden"
                >
                    <el-checkbox
                        v-for="device in devices"
                        :key="device.key"
                        :value="device.key"
                    >
                        {{ device.label }}
                    </el-checkbox>
                </el-checkbox-group>
            </el-form-item>
        </el-form>
    </div>

    <el-empty
        v-else
        :image-size="80"
        description="Выберите блок на странице, чтобы настроить его"
    />
</template>
