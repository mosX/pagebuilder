<script setup lang="ts">
import { Delete, Edit, EditPen, Plus } from '@element-plus/icons-vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { send } from '@/lib/http';
import patternsApi from '@/routes/api/patterns';
import { usePatternsStore } from '@/stores/patterns';
import type { PatternItem } from '@/stores/patterns';
import { useSessionStore } from '@/stores/session';
import type { MessageResponse } from '@/types';

const session = useSessionStore();
const patterns = usePatternsStore();
const router = useRouter();
const loading = ref(true);

onMounted(async () => {
    try {
        await patterns.load();
    } finally {
        loading.value = false;
    }
});

async function askName(title: string, value = '') {
    const { value: name } = await ElMessageBox.prompt(
        'Название секции',
        title,
        {
            inputValue: value,
            confirmButtonText: 'Сохранить',
            cancelButtonText: 'Отмена',
            inputValidator: (input) =>
                Boolean(input?.trim()) || 'Введите название',
        },
    );

    return name;
}

async function create() {
    try {
        const name = await askName('Новая секция');
        const result = await send<{ message: string; pattern: { id: number } }>(
            patternsApi.store(),
            { name, tree: [] },
        );
        await patterns.load();
        await router.push({
            name: 'patterns.edit',
            params: { id: result.pattern.id },
        });
    } catch {
        // Cancelled.
    }
}

async function rename(pattern: PatternItem) {
    try {
        const name = await askName('Переименовать', pattern.name);
        const result = await send<MessageResponse>(
            patternsApi.update(pattern.id),
            { name },
        );
        ElMessage.success(result.message);
        await patterns.load();
    } catch {
        // Cancelled.
    }
}

async function remove(pattern: PatternItem) {
    const result = await send<MessageResponse>(patternsApi.destroy(pattern.id));
    ElMessage.success(result.message);
    await patterns.load();
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">Секции</h1>
                <p class="text-sm text-[var(--el-text-color-secondary)]">
                    Повторяющиеся части страниц: шапка, подвал, призыв к
                    действию. Правка секции меняет её на всех страницах.
                </p>
            </div>
            <el-button
                v-if="session.can('content.manage')"
                type="primary"
                :icon="Plus"
                @click="create"
            >
                Создать секцию
            </el-button>
        </div>

        <el-card shadow="never" body-class="!p-0">
            <el-table
                v-loading="loading"
                :data="patterns.items"
                row-key="id"
                empty-text="Секций пока нет. Их можно создать здесь или сохранить любой блок в конструкторе."
            >
                <el-table-column prop="name" label="Название" min-width="240" />
                <el-table-column label="Изменена" width="180">
                    <template #default="{ row }">
                        {{ new Date(row.updated_at).toLocaleString('ru-RU') }}
                    </template>
                </el-table-column>
                <el-table-column
                    v-if="session.can('content.manage')"
                    width="150"
                    align="right"
                >
                    <template #default="{ row }">
                        <el-tooltip content="Редактировать блоки">
                            <el-button
                                :icon="Edit"
                                circle
                                size="small"
                                @click="
                                    router.push({
                                        name: 'patterns.edit',
                                        params: { id: row.id },
                                    })
                                "
                            />
                        </el-tooltip>
                        <el-tooltip content="Переименовать">
                            <el-button
                                :icon="EditPen"
                                circle
                                size="small"
                                @click="rename(row)"
                            />
                        </el-tooltip>
                        <el-popconfirm
                            :title="`Удалить «${row.name}»? На страницах она перестанет отображаться.`"
                            confirm-button-text="Удалить"
                            cancel-button-text="Отмена"
                            width="300"
                            @confirm="remove(row)"
                        >
                            <template #reference>
                                <el-button
                                    type="danger"
                                    :icon="Delete"
                                    circle
                                    plain
                                    size="small"
                                />
                            </template>
                        </el-popconfirm>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>
    </div>
</template>
