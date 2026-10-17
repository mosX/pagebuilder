<script setup lang="ts">
import {
    Back,
    Clock,
    DocumentAdd,
    Iphone,
    Monitor,
    Postcard,
    RefreshLeft,
    RefreshRight,
    View,
} from '@element-plus/icons-vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { VueDraggable } from 'vue-draggable-plus';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import { normalizeTree, serializeTree } from '@/blocks/registry';
import type { BlockNode } from '@/blocks/registry';
import BlockInspector from '@/components/blocks/editor/BlockInspector.vue';
import BlockPalette from '@/components/blocks/editor/BlockPalette.vue';
import {
    createBlockEditor,
    deviceWidths,
} from '@/components/blocks/editor/context';
import type { TemplateTarget } from '@/components/blocks/editor/context';
import EditorNode from '@/components/blocks/editor/EditorNode.vue';
import RelationSelect from '@/components/records/RelationSelect.vue';
import { isValidationError, send } from '@/lib/http';
import versions from '@/routes/api/block-versions';
import collectionsApi from '@/routes/api/collections';
import records from '@/routes/api/records';
import { useCollectionsStore } from '@/stores/collections';
import { usePatternsStore } from '@/stores/patterns';
import { useSessionStore } from '@/stores/session';
import { useSiteThemeStore } from '@/stores/site-theme';
import type {
    CollectionRecord,
    CollectionSchema,
    RelationValue,
} from '@/types';

/**
 * The block constructor. It edits one "target":
 * - field:{collection}:{record}:{field}  a blocks field of a record (e.g. a page);
 *   with ?locale=en, its translation (target suffix @en);
 * - template:{collection}                the template of a collection's record pages;
 * - pattern:{id}                         a reusable section.
 * Saving keeps a draft (not visible on the site); publishing makes it live and keeps a revision.
 */
type Mode = 'field' | 'template' | 'pattern' | 'layout';
type Revision = { id: number; created_at: string | null; user: string | null };
type Versions = {
    published: BlockNode[];
    draft: {
        tree: BlockNode[];
        updated_at: string | null;
        user: string | null;
    } | null;
    revisions: Revision[];
};

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const collections = useCollectionsStore();
const patterns = usePatternsStore();
const siteTheme = useSiteThemeStore();

const mode: Mode =
    route.name === 'collections.template'
        ? 'template'
        : route.name === 'patterns.edit'
          ? 'pattern'
          : route.name === 'site.layout'
            ? 'layout'
            : 'field';
// Route param: header | footer | not-found (stored as layout:not_found).
const layoutPart = String(route.params.part ?? '').replace('-', '_');
const isTemplate = mode === 'template';
const name = String(route.params.name ?? '');
const recordId = Number(route.params.id);
const fieldName = String(route.params.field ?? '');
/** Language of a translation being edited; '' for the original. */
const locale =
    mode === 'field' && typeof route.query.locale === 'string'
        ? route.query.locale
        : '';

const target =
    mode === 'field'
        ? `field:${name}:${recordId}:${fieldName}${locale ? `@${locale}` : ''}`
        : mode === 'template'
          ? `template:${name}`
          : mode === 'layout'
            ? `layout:${layoutPart}`
            : `pattern:${recordId}`;

const collection = ref<CollectionSchema | null>(null);
const record = ref<CollectionRecord | null>(null);
const tree = ref<BlockNode[]>([]);
const loading = ref(true);
const busy = ref(false);

/** Last state stored on the server (draft or published) — for "unsaved changes". */
const saved = ref('[]');
/** Published state — a draft exists when the saved state differs from it. */
const published = ref('[]');
const draftInfo = ref<Versions['draft']>(null);
const revisions = ref<Revision[]>([]);
const historyOpen = ref(false);

// Template mode: the record whose data the canvas previews.
const sampleRecordId = ref<number | null>(null);
const sampleOptions = ref<RelationValue[]>([]);
const templateTarget = computed<TemplateTarget | null>(() =>
    isTemplate && collection.value
        ? { collection: collection.value, recordId: sampleRecordId }
        : null,
);

const editor = createBlockEditor(tree, templateTarget);

const serialized = computed(() => JSON.stringify(serializeTree(tree.value)));
const isDirty = computed(() => serialized.value !== saved.value);
const hasDraft = computed(() => saved.value !== published.value);

const title = computed(() => {
    if (mode === 'template') {
        return `Шаблон страницы записи: ${collection.value?.label ?? ''}`;
    }

    if (mode === 'pattern') {
        return `Секция: ${patterns.find(recordId)?.name ?? ''}`;
    }

    if (mode === 'layout') {
        return layoutPart === 'header'
            ? 'Шапка сайта'
            : layoutPart === 'footer'
              ? 'Подвал сайта'
              : 'Страница 404';
    }

    const display = collection.value?.display_field;
    const value = display ? record.value?.[display] : null;

    return typeof value === 'string' && value ? value : `Запись #${recordId}`;
});

const subtitle = computed(() => {
    if (mode === 'template') {
        return collection.value?.site.url
            ? `${collection.value.site.url}/…`
            : 'Коллекция пока не опубликована на сайте';
    }

    if (mode === 'pattern') {
        return 'Изменения появятся на всех страницах с этой секцией';
    }

    if (mode === 'layout') {
        return layoutPart === 'not_found'
            ? 'Показывается, когда страница не найдена'
            : 'Показывается на всех страницах сайта';
    }

    return collection.value?.fields.find((f) => f.name === fieldName)?.label;
});

// Pages of the system pages collection can be opened on the public site.
const siteUrl = computed(() => {
    const slug = record.value?.slug;
    const base = session.tenant?.current.site_url;

    if (
        collection.value?.kind !== 'pages' ||
        typeof slug !== 'string' ||
        !base
    ) {
        return null;
    }

    const prefix = locale ? `/${locale}` : '';

    return slug === 'home' ? `${base}${prefix}` : `${base}${prefix}/${slug}`;
});

async function loadVersions() {
    const result = await send<Versions>(versions.show(), { target });

    published.value = JSON.stringify(
        serializeTree(normalizeTree(result.published)),
    );
    draftInfo.value = result.draft;
    revisions.value = result.revisions;

    tree.value = normalizeTree(result.draft?.tree ?? result.published);
    saved.value = serialized.value;
}

async function load() {
    if (mode === 'field' || mode === 'template') {
        const schema = await send<{ collection: CollectionSchema }>(
            collectionsApi.show(name),
        );
        collection.value = schema.collection;
    }

    if (mode === 'field') {
        record.value = await send<CollectionRecord>(
            records.show({ collection: name, record: recordId }),
        );
    }

    if (mode === 'template') {
        sampleOptions.value = await send<RelationValue[]>(
            records.options(name),
        );
        sampleRecordId.value = sampleOptions.value[0]?.id ?? null;
    }

    await loadVersions();
    editor.resetHistory();
    document.title = `${title.value} - Конструктор`;
}

onMounted(async () => {
    try {
        await Promise.all([
            load(),
            collections.loaded ? null : collections.load(),
            siteTheme.load(),
            patterns.loaded ? null : patterns.load(),
        ]);
    } finally {
        loading.value = false;
    }
});

async function run(action: () => Promise<void>) {
    busy.value = true;

    try {
        await action();
    } catch (error) {
        if (isValidationError(error)) {
            ElMessage.error(
                error.response?.data.message ?? 'Не удалось сохранить.',
            );
        }
    } finally {
        busy.value = false;
    }
}

function saveDraft() {
    return run(async () => {
        const result = await send<{ tree: BlockNode[] }>(versions.draft(), {
            target,
            tree: serializeTree(tree.value),
        });

        // The server normalizes the tree (ids, props), so keep its version.
        tree.value = normalizeTree(result.tree);
        saved.value = serialized.value;
        draftInfo.value = {
            tree: result.tree,
            updated_at: new Date().toISOString(),
            user: session.user?.name ?? null,
        };
        ElMessage.success('Черновик сохранён. На сайте пока прежняя версия.');
    });
}

function publish() {
    return run(async () => {
        const result = await send<{ tree: BlockNode[] }>(versions.publish(), {
            target,
            tree: serializeTree(tree.value),
        });

        tree.value = normalizeTree(result.tree);
        saved.value = serialized.value;
        published.value = serialized.value;
        draftInfo.value = null;
        ElMessage.success('Опубликовано.');

        if (mode === 'pattern') {
            await patterns.load();
        }

        revisions.value = (
            await send<Versions>(versions.show(), { target })
        ).revisions;
    });
}

async function discardDraft() {
    try {
        await ElMessageBox.confirm(
            'Удалить черновик и вернуться к опубликованной версии?',
            'Черновик',
            {
                confirmButtonText: 'Удалить',
                cancelButtonText: 'Отмена',
                type: 'warning',
            },
        );
    } catch {
        return;
    }

    await run(async () => {
        await send(versions.discard(), { target });
        await loadVersions();
        editor.resetHistory();
        ElMessage.success('Черновик удалён.');
    });
}

async function restoreRevision(revision: Revision) {
    const result = await send<{ tree: BlockNode[] }>(
        versions.revision(revision.id),
    );
    tree.value = normalizeTree(result.tree);
    historyOpen.value = false;
    ElMessage.info(
        'Версия загружена в редактор. Сохраните или опубликуйте её.',
    );
}

function formatDate(value: string | null) {
    return value
        ? new Date(value).toLocaleString('ru-RU', {
              dateStyle: 'short',
              timeStyle: 'short',
          })
        : '';
}

function back() {
    void router.push(
        mode === 'template'
            ? { name: 'collections.schema', params: { name } }
            : mode === 'pattern'
              ? { name: 'patterns' }
              : mode === 'layout'
                ? { name: 'site' }
                : { name: 'records', params: { name } },
    );
}

/** Typing in inputs and the rich text editor must keep its own shortcuts. */
function isTyping(event: KeyboardEvent) {
    const element = event.target as HTMLElement | null;

    return !!element?.closest(
        'input, textarea, select, [contenteditable="true"]',
    );
}

function onKeydown(event: KeyboardEvent) {
    const mod = event.ctrlKey || event.metaKey;
    const key = event.key.toLowerCase();

    if (mod && key === 's') {
        event.preventDefault();
        void (event.shiftKey ? publish() : saveDraft());

        return;
    }

    if (isTyping(event)) {
        return;
    }

    const selectedId = editor.selectedId.value;

    if (mod && key === 'z') {
        event.preventDefault();
        if (event.shiftKey) {
            editor.redo();
        } else {
            editor.undo();
        }
    } else if (mod && key === 'y') {
        event.preventDefault();
        editor.redo();
    } else if (mod && key === 'c' && selectedId) {
        editor.copy(selectedId);
    } else if (mod && key === 'v' && editor.clipboard.value) {
        event.preventDefault();
        editor.paste();
    } else if ((key === 'delete' || key === 'backspace') && selectedId) {
        event.preventDefault();
        editor.remove(selectedId);
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
            class="flex flex-wrap items-center gap-3 border-b border-[var(--el-border-color-light)] bg-white px-4 py-2"
        >
            <el-button :icon="Back" text @click="back">
                {{
                    mode === 'pattern'
                        ? 'Секции'
                        : mode === 'layout'
                          ? 'Сайт'
                          : collection?.label
                }}
            </el-button>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 truncate font-medium">
                    {{ title }}
                    <el-tag v-if="locale" size="small" type="primary">
                        перевод: {{ locale.toUpperCase() }}
                    </el-tag>
                    <el-tag v-if="hasDraft" size="small" type="warning">
                        черновик
                    </el-tag>
                </div>
                <div
                    class="truncate text-xs text-[var(--el-text-color-secondary)]"
                >
                    {{ subtitle }}
                    <span v-if="isDirty" class="text-[var(--el-color-warning)]">
                        · есть несохранённые изменения
                    </span>
                    <span v-else-if="hasDraft && draftInfo">
                        · черновик от {{ formatDate(draftInfo.updated_at) }}
                    </span>
                </div>
            </div>

            <el-button-group>
                <el-tooltip content="Отменить (Ctrl+Z)">
                    <el-button
                        :icon="RefreshLeft"
                        :disabled="!editor.canUndo.value"
                        @click="editor.undo()"
                    />
                </el-tooltip>
                <el-tooltip content="Повторить (Ctrl+Shift+Z)">
                    <el-button
                        :icon="RefreshRight"
                        :disabled="!editor.canRedo.value"
                        @click="editor.redo()"
                    />
                </el-tooltip>
                <el-tooltip content="Вставить скопированный блок (Ctrl+V)">
                    <el-button
                        :icon="DocumentAdd"
                        :disabled="!editor.clipboard.value"
                        @click="editor.paste()"
                    />
                </el-tooltip>
                <el-tooltip content="История версий">
                    <el-button :icon="Clock" @click="historyOpen = true" />
                </el-tooltip>
            </el-button-group>

            <el-radio-group v-model="editor.device.value" size="small">
                <el-radio-button value="desktop">
                    <el-icon><Monitor /></el-icon>
                </el-radio-button>
                <el-radio-button value="tablet">
                    <el-icon><Postcard /></el-icon>
                </el-radio-button>
                <el-radio-button value="mobile">
                    <el-icon><Iphone /></el-icon>
                </el-radio-button>
            </el-radio-group>

            <div v-if="isTemplate" class="flex items-center gap-2">
                <span class="text-xs text-[var(--el-text-color-secondary)]">
                    Превью с записью:
                </span>
                <RelationSelect
                    v-if="!loading"
                    v-model="sampleRecordId"
                    :collection="name"
                    :initial="sampleOptions"
                    class="!w-48"
                />
            </div>

            <el-button
                v-if="siteUrl"
                :icon="View"
                tag="a"
                :href="siteUrl"
                target="_blank"
            >
                Сайт
            </el-button>
            <el-button v-if="hasDraft && !isDirty" text @click="discardDraft">
                Удалить черновик
            </el-button>
            <el-tooltip
                content="Сохранить черновик (Ctrl+S) — на сайте не появится"
            >
                <el-button
                    :loading="busy"
                    :disabled="!isDirty"
                    @click="saveDraft"
                >
                    Сохранить
                </el-button>
            </el-tooltip>
            <el-tooltip content="Опубликовать на сайте (Ctrl+Shift+S)">
                <el-button
                    type="primary"
                    :loading="busy"
                    :disabled="!isDirty && !hasDraft"
                    @click="publish"
                >
                    Опубликовать
                </el-button>
            </el-tooltip>
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
                <!-- A container, so blocks respond to the previewed device width. -->
                <div
                    class="@container mx-auto min-h-full bg-white shadow-sm transition-all"
                    :style="{
                        ...siteTheme.vars,
                        width: deviceWidths[editor.device.value],
                        maxWidth: '100%',
                    }"
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

        <el-drawer
            v-model="historyOpen"
            title="История публикаций"
            size="380px"
        >
            <el-empty
                v-if="!revisions.length"
                :image-size="80"
                description="Опубликованных версий пока нет"
            />
            <el-timeline v-else>
                <el-timeline-item
                    v-for="(revision, index) in revisions"
                    :key="revision.id"
                    :timestamp="formatDate(revision.created_at)"
                    :type="index === 0 ? 'primary' : undefined"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm">
                            {{ index === 0 ? 'Текущая версия' : 'Версия' }}
                            <span
                                v-if="revision.user"
                                class="text-[var(--el-text-color-secondary)]"
                            >
                                · {{ revision.user }}
                            </span>
                        </span>
                        <el-button
                            v-if="index > 0"
                            size="small"
                            @click="restoreRevision(revision)"
                        >
                            Восстановить
                        </el-button>
                    </div>
                </el-timeline-item>
            </el-timeline>
        </el-drawer>
    </div>
</template>
