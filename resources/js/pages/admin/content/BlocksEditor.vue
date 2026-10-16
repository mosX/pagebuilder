<script setup lang="ts">
import { Back, View } from '@element-plus/icons-vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { VueDraggable } from 'vue-draggable-plus';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import { normalizeTree, serializeTree } from '@/blocks/registry';
import type { BlockNode } from '@/blocks/registry';
import BlockInspector from '@/components/blocks/editor/BlockInspector.vue';
import BlockPalette from '@/components/blocks/editor/BlockPalette.vue';
import { createBlockEditor } from '@/components/blocks/editor/context';
import type { TemplateTarget } from '@/components/blocks/editor/context';
import EditorNode from '@/components/blocks/editor/EditorNode.vue';
import RelationSelect from '@/components/records/RelationSelect.vue';
import { isValidationError, send } from '@/lib/http';
import collectionsApi from '@/routes/api/collections';
import records from '@/routes/api/records';
import { useCollectionsStore } from '@/stores/collections';
import { useSessionStore } from '@/stores/session';
import type {
    CollectionRecord,
    CollectionSchema,
    RelationValue,
} from '@/types';

/**
 * The block constructor in two modes:
 * - a blocks field of one record (e.g. a page's content);
 * - the template every record page of a collection is rendered with.
 */
const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const collections = useCollectionsStore();

const isTemplate = route.name === 'collections.template';
const name = String(route.params.name);
const recordId = Number(route.params.id);
const fieldName = String(route.params.field ?? '');

const collection = ref<CollectionSchema | null>(null);
const record = ref<CollectionRecord | null>(null);
const tree = ref<BlockNode[]>([]);
const saved = ref('[]');
const loading = ref(true);
const saving = ref(false);

// Template mode: the record whose data the canvas previews.
const sampleRecordId = ref<number | null>(null);
const sampleOptions = ref<RelationValue[]>([]);
const template = computed<TemplateTarget | null>(() =>
    isTemplate && collection.value
        ? { collection: collection.value, recordId: sampleRecordId }
        : null,
);

const editor = createBlockEditor(tree, template);

const field = computed(() =>
    collection.value?.fields.find((f) => f.name === fieldName),
);
const isDirty = computed(
    () => JSON.stringify(serializeTree(tree.value)) !== saved.value,
);

const title = computed(() => {
    if (isTemplate) {
        return `Шаблон страницы записи: ${collection.value?.label ?? ''}`;
    }

    const display = collection.value?.display_field;
    const value = display ? record.value?.[display] : null;

    return typeof value === 'string' && value ? value : `Запись #${recordId}`;
});

const subtitle = computed(() =>
    isTemplate
        ? collection.value?.site.url
            ? `${collection.value.site.url}/…`
            : 'Коллекция пока не опубликована на сайте'
        : field.value?.label,
);

// Pages of the system pages collection can be opened on the public site.
const siteUrl = computed(() => {
    if (isTemplate) {
        return null;
    }

    const slug = record.value?.slug;
    const base = session.tenant?.current.site_url;

    if (
        collection.value?.kind !== 'pages' ||
        typeof slug !== 'string' ||
        !base
    ) {
        return null;
    }

    return slug === 'home' ? base : `${base}/${slug}`;
});

async function load() {
    const schema = await send<{ collection: CollectionSchema }>(
        collectionsApi.show(name),
    );
    collection.value = schema.collection;

    if (isTemplate) {
        tree.value = normalizeTree(schema.collection.site_template);
        sampleOptions.value = await send<RelationValue[]>(
            records.options(name),
        );
        sampleRecordId.value = sampleOptions.value[0]?.id ?? null;
    } else {
        const current = await send<CollectionRecord>(
            records.show({ collection: name, record: recordId }),
        );
        record.value = current;
        tree.value = normalizeTree(current[fieldName]);
    }

    saved.value = JSON.stringify(serializeTree(tree.value));
    document.title = `${title.value} - Конструктор`;
}

onMounted(async () => {
    try {
        await Promise.all([
            load(),
            collections.loaded ? null : collections.load(),
        ]);
    } finally {
        loading.value = false;
    }
});

async function save() {
    saving.value = true;

    try {
        if (isTemplate) {
            const result = await send<{ template: BlockNode[] }>(
                collectionsApi.template(name),
                { template: serializeTree(tree.value) },
            );
            // The server may have normalized the tree (ids, props), so keep its version.
            tree.value = normalizeTree(result.template);
        } else {
            const result = await send<{ record: CollectionRecord }>(
                records.update({ collection: name, record: recordId }),
                { [fieldName]: serializeTree(tree.value) },
            );
            tree.value = normalizeTree(result.record[fieldName]);
            record.value = result.record;
        }

        saved.value = JSON.stringify(serializeTree(tree.value));
        ElMessage.success(
            isTemplate ? 'Шаблон сохранён.' : 'Страница сохранена.',
        );
    } catch (error) {
        if (isValidationError(error)) {
            ElMessage.error(
                error.response?.data.message ?? 'Не удалось сохранить.',
            );
        }
    } finally {
        saving.value = false;
    }
}

function back() {
    void router.push(
        isTemplate
            ? { name: 'collections.schema', params: { name } }
            : { name: 'records', params: { name } },
    );
}

function onKeydown(event: KeyboardEvent) {
    if ((event.ctrlKey || event.metaKey) && event.key === 's') {
        event.preventDefault();
        void save();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

onBeforeRouteLeave(async () => {
    if (!isDirty.value) {
        return true;
    }

    try {
        await ElMessageBox.confirm(
            'Есть несохранённые изменения. Уйти без сохранения?',
            'Конструктор',
            {
                confirmButtonText: 'Уйти',
                cancelButtonText: 'Остаться',
                type: 'warning',
            },
        );

        return true;
    } catch {
        return false;
    }
});

/** Links inside blocks must not navigate away while editing. */
function preventLinks(event: MouseEvent) {
    if ((event.target as HTMLElement).closest('a')) {
        event.preventDefault();
    }
}
</script>

<template>
    <div v-loading="loading" class="-m-5 flex h-[calc(100vh-60px)] flex-col">
        <div
            class="flex items-center gap-3 border-b border-[var(--el-border-color-light)] bg-white px-4 py-2"
        >
            <el-button :icon="Back" text @click="back">
                {{ collection?.label }}
            </el-button>
            <div class="min-w-0 flex-1">
                <div class="truncate font-medium">{{ title }}</div>
                <div class="text-xs text-[var(--el-text-color-secondary)]">
                    {{ subtitle }}
                    <span v-if="isDirty" class="text-[var(--el-color-warning)]">
                        · есть несохранённые изменения
                    </span>
                </div>
            </div>
            <div v-if="isTemplate" class="flex items-center gap-2">
                <span class="text-xs text-[var(--el-text-color-secondary)]">
                    Превью с записью:
                </span>
                <RelationSelect
                    v-if="!loading"
                    v-model="sampleRecordId"
                    :collection="name"
                    :initial="sampleOptions"
                    class="!w-56"
                />
            </div>
            <el-button
                v-if="siteUrl"
                :icon="View"
                tag="a"
                :href="siteUrl"
                target="_blank"
            >
                Открыть на сайте
            </el-button>
            <el-button
                type="primary"
                :loading="saving"
                :disabled="!isDirty"
                @click="save"
            >
                Сохранить
            </el-button>
        </div>

        <div class="grid min-h-0 flex-1 grid-cols-[240px_1fr_320px]">
            <aside
                class="overflow-y-auto border-r border-[var(--el-border-color-light)] bg-[var(--el-bg-color-page)] p-3"
            >
                <BlockPalette />
            </aside>

            <main
                class="overflow-y-auto bg-gray-100 p-6"
                @click="editor.select(null)"
            >
                <div
                    class="mx-auto min-h-full max-w-6xl bg-white shadow-sm"
                    @click.capture="preventLinks"
                >
                    <VueDraggable
                        v-model="tree"
                        group="blocks"
                        handle=".block-handle"
                        :animation="150"
                        ghost-class="opacity-40"
                        class="flex min-h-[70vh] flex-col gap-1 p-1"
                    >
                        <EditorNode
                            v-for="node in tree"
                            :key="node.id"
                            :node="node"
                        />
                    </VueDraggable>
                    <div
                        v-if="!tree.length && !loading"
                        class="pointer-events-none -mt-[60vh] text-center text-sm text-gray-400"
                    >
                        {{
                            isTemplate
                                ? 'Добавьте блоки «Поле записи» из группы «Запись» слева'
                                : 'Перетащите сюда первый блок из панели слева'
                        }}
                    </div>
                </div>
            </main>

            <aside
                class="overflow-y-auto border-l border-[var(--el-border-color-light)] bg-white p-4"
            >
                <BlockInspector />
            </aside>
        </div>
    </div>
</template>
