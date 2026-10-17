<script setup lang="ts">
import { Delete, Edit, Plus, Search } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { onMounted, ref } from 'vue';
import { useForm } from '@/composables/useForm';
import { send } from '@/lib/http';
import redirectsApi from '@/routes/api/redirects';
import type { MessageResponse, Paginated } from '@/types';

type Redirect = {
    id: number;
    from_path: string;
    to_path: string;
    status: number;
    is_auto: boolean;
    hits: number;
    last_hit_at: string | null;
};

/**
 * Redirects of the site: created automatically when an address changes, or by hand
 * for old links (a previous site, a renamed section).
 */
defineProps<{ canEdit: boolean }>();

const page = ref<Paginated<Redirect> | null>(null);
const loading = ref(true);
const search = ref('');
const dialog = ref(false);
const editing = ref<Redirect | null>(null);
const form = useForm({ from_path: '', to_path: '', status: 301 });

async function load(pageNumber = 1) {
    loading.value = true;

    try {
        page.value = await send<Paginated<Redirect>>(redirectsApi.index(), {
            search: search.value || undefined,
            page: pageNumber,
        });
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());

function open(redirect: Redirect | null) {
    editing.value = redirect;
    form.from_path = redirect?.from_path ?? '';
    form.to_path = redirect?.to_path ?? '';
    form.status = redirect?.status ?? 301;
    form.clearErrors();
    dialog.value = true;
}

async function save() {
    const result = await form.submit<MessageResponse>(
        editing.value
            ? redirectsApi.update(editing.value.id)
            : redirectsApi.store(),
    );

    if (result) {
        dialog.value = false;
        ElMessage.success(result.message);
        await load(page.value?.current_page ?? 1);
    }
}

async function remove(redirect: Redirect) {
    const result = await send<MessageResponse>(
        redirectsApi.destroy(redirect.id),
    );
    ElMessage.success(result.message);
    await load(page.value?.current_page ?? 1);
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <el-card shadow="never">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p
                    class="max-w-2xl text-sm text-[var(--el-text-color-secondary)]"
                >
                    Когда меняется адрес страницы, записи или раздела, старый
                    адрес сам перенаправляет на новый. Здесь можно добавить свои
                    перенаправления — например, со старых адресов прежнего
                    сайта.
                    <code>/blog/*</code> перенаправляет весь раздел.
                </p>
                <div class="flex gap-2">
                    <el-input
                        v-model="search"
                        placeholder="Поиск по адресу"
                        :prefix-icon="Search"
                        clearable
                        class="!w-56"
                        @change="load()"
                    />
                    <el-button
                        v-if="canEdit"
                        type="primary"
                        :icon="Plus"
                        @click="open(null)"
                    >
                        Редирект
                    </el-button>
                </div>
            </div>
        </el-card>

        <el-card shadow="never" body-class="!p-0">
            <el-table
                v-loading="loading"
                :data="page?.data ?? []"
                empty-text="Редиректов пока нет"
            >
                <el-table-column label="Откуда" min-width="220">
                    <template #default="{ row }">
                        <code>{{ row.from_path }}</code>
                        <el-tag
                            v-if="row.is_auto"
                            size="small"
                            type="info"
                            class="ml-2"
                        >
                            авто
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="Куда" min-width="220">
                    <template #default="{ row }">
                        <code class="break-all">{{ row.to_path }}</code>
                    </template>
                </el-table-column>
                <el-table-column prop="status" label="Код" width="80" />
                <el-table-column label="Переходы" width="110" align="right">
                    <template #default="{ row }">{{ row.hits }}</template>
                </el-table-column>
                <el-table-column v-if="canEdit" width="110" align="right">
                    <template #default="{ row }">
                        <el-button
                            :icon="Edit"
                            circle
                            plain
                            size="small"
                            @click="open(row)"
                        />
                        <el-popconfirm
                            :title="`Удалить редирект с ${row.from_path}?`"
                            confirm-button-text="Удалить"
                            cancel-button-text="Отмена"
                            width="260"
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

        <div v-if="page && page.last_page > 1" class="flex justify-end">
            <el-pagination
                layout="prev, pager, next"
                :total="page.total"
                :page-size="page.per_page"
                :current-page="page.current_page"
                @current-change="load"
            />
        </div>

        <el-dialog
            v-model="dialog"
            :title="editing ? 'Редирект' : 'Новый редирект'"
            width="520"
        >
            <el-form label-position="top" @submit.prevent="save">
                <el-form-item
                    label="Старый адрес"
                    :error="form.errors.from_path"
                >
                    <el-input
                        v-model="form.from_path"
                        placeholder="/old-page или /blog/*"
                    />
                </el-form-item>
                <el-form-item label="Новый адрес" :error="form.errors.to_path">
                    <el-input
                        v-model="form.to_path"
                        placeholder="/new-page, /news/* или https://…"
                    />
                </el-form-item>
                <el-form-item label="Тип">
                    <el-radio-group v-model="form.status">
                        <el-radio-button :value="301"
                            >Навсегда (301)</el-radio-button
                        >
                        <el-radio-button :value="302"
                            >Временно (302)</el-radio-button
                        >
                    </el-radio-group>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialog = false">Отмена</el-button>
                <el-button
                    type="primary"
                    :loading="form.processing"
                    @click="save"
                >
                    Сохранить
                </el-button>
            </template>
        </el-dialog>
    </div>
</template>
