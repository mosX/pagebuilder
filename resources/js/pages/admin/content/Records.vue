<script setup lang="ts">
import {
    Delete,
    Edit,
    Plus,
    Search,
    SetUp,
    View,
} from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import RecordDrawer from '@/components/records/RecordDrawer.vue';
import RecordValue from '@/components/records/RecordValue.vue';
import { send } from '@/lib/http';
import collectionsApi from '@/routes/api/collections';
import records from '@/routes/api/records';
import { useSessionStore } from '@/stores/session';
import type {
    CollectionField,
    CollectionRecord,
    CollectionSchema,
    MessageResponse,
    Paginated,
    RelationValue,
} from '@/types';

const MAX_COLUMNS = 6;

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const name = String(route.params.name);

const collection = ref<CollectionSchema | null>(null);
const page = ref<Paginated<CollectionRecord> | null>(null);
const loading = ref(true);
const canManage = computed(() => session.can('content.manage'));

const query = reactive({
    search: '',
    sort: 'id',
    direction: 'desc' as 'asc' | 'desc',
    page: 1,
});

const columns = computed(() =>
    (collection.value?.fields ?? []).slice(0, MAX_COLUMNS),
);

/** Sorting by the column of a field; many-to-many fields have none. */
function sortKey(field: CollectionField): string | undefined {
    if (['belongs_to_many', 'text', 'blocks'].includes(field.type)) {
        return undefined;
    }

    return field.type === 'belongs_to' ? `${field.name}_id` : field.name;
}

async function loadRecords() {
    loading.value = true;

    try {
        page.value = await send<Paginated<CollectionRecord>>(
            records.index(name),
            {
                search: query.search || undefined,
                sort: query.sort,
                direction: query.direction,
                page: query.page,
            },
        );
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    const result = await send<{ collection: CollectionSchema }>(
        collectionsApi.show(name),
    );
    collection.value = result.collection;
    document.title = `${result.collection.label} - ${import.meta.env.VITE_APP_NAME}`;
    await loadRecords();

    // Opened from a related record: show that record right away.
    const linked = Number(route.query.record);

    if (linked) {
        openRecord(
            await send<CollectionRecord>(
                records.show({ collection: name, record: linked }),
            ),
        );
    }

    // "+ Добавить" in a related record: a new record linked to it.
    if (route.query.new && route.query.link_field && route.query.link_id) {
        link.value = {
            field: String(route.query.link_field),
            record: {
                id: Number(route.query.link_id),
                label: String(
                    route.query.link_label ?? `#${route.query.link_id}`,
                ),
            },
        };
        editing.value = null;
        drawerOpen.value = true;
    } else if (route.query.new) {
        // "New page" and similar shortcuts: an empty record form.
        editing.value = null;
        drawerOpen.value = true;
    }
});

// Collections that reference themselves (category → parent category) can be shown as a tree.
const treeField = computed(() =>
    collection.value?.fields.find(
        (f) => f.type === 'belongs_to' && f.related_collection?.name === name,
    ),
);
const treeMode = ref(false);
const tree = ref<TreeRow[]>([]);

type TreeRow = CollectionRecord & { children?: TreeRow[] };

async function loadTree() {
    const field = treeField.value;

    if (!field) {
        return;
    }

    loading.value = true;

    try {
        // All records (up to 1000) are needed to build the tree.
        const all: CollectionRecord[] = [];

        for (let p = 1; p <= 10; p++) {
            const result = await send<Paginated<CollectionRecord>>(
                records.index(name),
                {
                    per_page: 100,
                    page: p,
                    sort: 'id',
                    direction: 'asc',
                },
            );
            all.push(...result.data);

            if (p >= result.last_page) {
                break;
            }
        }

        const rows = new Map<number, TreeRow>(all.map((r) => [r.id, { ...r }]));
        const roots: TreeRow[] = [];

        for (const row of rows.values()) {
            const parent = row[field.name] as { id: number } | null;
            const target = parent ? rows.get(parent.id) : undefined;

            if (target && target !== row) {
                (target.children ??= []).push(row);
            } else {
                roots.push(row);
            }
        }

        tree.value = roots;
    } finally {
        loading.value = false;
    }
}

function toggleTree(value: boolean) {
    treeMode.value = value;
    void (value ? loadTree() : loadRecords());
}

function reload() {
    return treeMode.value ? loadTree() : loadRecords();
}

function onSort({
    prop,
    order,
}: {
    prop: string | null;
    order: string | null;
}) {
    query.sort = order && prop ? prop : 'id';
    query.direction = order === 'ascending' ? 'asc' : 'desc';
    query.page = 1;
    void loadRecords();
}

function onSearch() {
    query.page = 1;
    void loadRecords();
}

const drawerOpen = ref(false);
const editing = ref<CollectionRecord | null>(null);
const link = ref<{ field: string; record: RelationValue } | null>(null);

function openRecord(record: CollectionRecord | null) {
    link.value = null;
    editing.value = record;
    drawerOpen.value = true;
}

async function remove(record: CollectionRecord) {
    const result = await send<MessageResponse>(
        records.destroy({ collection: name, record: record.id }),
    );
    ElMessage.success(result.message);
    await reload();
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ collection?.label }}</h1>
                <p
                    v-if="collection?.description"
                    class="text-sm text-[var(--el-text-color-secondary)]"
                >
                    {{ collection.description }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <el-switch
                    v-if="treeField"
                    :model-value="treeMode"
                    active-text="Деревом"
                    @update:model-value="
                        (value: unknown) => toggleTree(Boolean(value))
                    "
                />
                <el-input
                    v-model="query.search"
                    placeholder="Поиск"
                    :prefix-icon="Search"
                    clearable
                    class="!w-64"
                    @change="onSearch"
                />
                <el-button
                    v-if="session.can('schema.manage')"
                    :icon="SetUp"
                    @click="
                        router.push({
                            name: 'collections.schema',
                            params: { name },
                        })
                    "
                >
                    Структура
                </el-button>
                <el-button
                    v-if="canManage"
                    type="primary"
                    :icon="Plus"
                    :disabled="!collection?.fields.length"
                    @click="openRecord(null)"
                >
                    Добавить
                </el-button>
            </div>
        </div>

        <el-alert
            v-if="collection && !collection.fields.length"
            type="info"
            :closable="false"
            class="mb-4"
            title="В коллекции пока нет полей. Добавьте их в разделе «Структура»."
        />

        <el-card shadow="never" body-class="!p-0">
            <el-table
                v-loading="loading"
                :data="treeMode ? tree : (page?.data ?? [])"
                row-key="id"
                :default-expand-all="treeMode"
                empty-text="Записей пока нет"
                @sort-change="onSort"
                @row-dblclick="(row: CollectionRecord) => openRecord(row)"
            >
                <el-table-column
                    prop="id"
                    label="ID"
                    width="80"
                    sortable="custom"
                />
                <el-table-column
                    v-for="field in columns"
                    :key="field.id"
                    :prop="sortKey(field)"
                    :label="field.label"
                    :sortable="sortKey(field) ? 'custom' : false"
                    min-width="160"
                >
                    <template #default="{ row }">
                        <RecordValue
                            :field="field"
                            :value="row[field.name]"
                            :download-url="
                                field.type === 'file'
                                    ? records.file({
                                          collection: name,
                                          record: row.id,
                                          field: field.name,
                                      }).url
                                    : null
                            "
                        />
                    </template>
                </el-table-column>
                <el-table-column
                    prop="updated_at"
                    label="Изменено"
                    width="170"
                    sortable="custom"
                />
                <el-table-column width="100" align="right" fixed="right">
                    <template #default="{ row }">
                        <el-button
                            :icon="canManage ? Edit : View"
                            circle
                            size="small"
                            @click="openRecord(row)"
                        />
                        <el-popconfirm
                            v-if="canManage"
                            :title="`Удалить запись #${row.id}?`"
                            confirm-button-text="Удалить"
                            cancel-button-text="Отмена"
                            width="220"
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

        <div
            v-if="!treeMode && page && page.last_page > 1"
            class="mt-4 flex justify-end"
        >
            <el-pagination
                layout="total, prev, pager, next"
                :total="page.total"
                :page-size="page.per_page"
                :current-page="page.current_page"
                @current-change="
                    (p: number) => {
                        query.page = p;
                        loadRecords();
                    }
                "
            />
        </div>

        <RecordDrawer
            v-if="collection"
            v-model="drawerOpen"
            :collection="collection"
            :record="editing"
            :link="link"
            :readonly="!canManage"
            @saved="reload"
        />
    </div>
</template>
