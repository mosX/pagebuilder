<script setup lang="ts">
import { Document, Search, UploadFilled } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import type { UploadRequestOptions } from 'element-plus';
import { onMounted, ref } from 'vue';
import { isValidationError, send } from '@/lib/http';
import media from '@/routes/api/media';
import { useSessionStore } from '@/stores/session';
import type { MediaItem, Paginated } from '@/types';

const props = defineProps<{
    /** Highlights the chosen item instead of opening its details. */
    selectable?: boolean;
    selectedUrl?: string | null;
    /** Only images or only documents; everything when not set. */
    kind?: 'image' | 'document';
}>();

const imageTypes = 'image/jpeg,image/png,image/webp,image/gif,image/avif';
const documentTypes =
    '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.zip';
const accept =
    props.kind === 'image'
        ? imageTypes
        : props.kind === 'document'
          ? documentTypes
          : `${imageTypes},${documentTypes}`;

const emit = defineEmits<{ open: [item: MediaItem] }>();

const session = useSessionStore();
const page = ref<Paginated<MediaItem> | null>(null);
const loading = ref(true);
const search = ref('');

async function load(pageNumber = 1) {
    loading.value = true;

    try {
        page.value = await send<Paginated<MediaItem>>(media.index(), {
            search: search.value || undefined,
            kind: props.kind,
            page: pageNumber,
            per_page: 24,
        });
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());

async function upload(options: UploadRequestOptions) {
    const body = new FormData();
    body.append('file', options.file);

    try {
        const result = await send<{ message: string; media: MediaItem }>(
            media.store(),
            body,
        );
        ElMessage.success(`Загружено: ${result.media.name}`);
        await load();
        emit('open', result.media);
    } catch (error) {
        if (isValidationError(error)) {
            ElMessage.error(
                error.response?.data.message ?? 'Не удалось загрузить файл.',
            );
        }
    }
}

defineExpose({ reload: () => load(page.value?.current_page) });
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-start gap-3">
            <el-upload
                v-if="session.can('content.manage')"
                drag
                multiple
                :show-file-list="false"
                :accept="accept"
                :http-request="upload"
                class="w-full sm:w-80"
            >
                <el-icon :size="32"><UploadFilled /></el-icon>
                <div class="text-sm">
                    Перетащите файлы или <em>выберите их</em>
                </div>
                <template #tip>
                    <div class="text-xs text-[var(--el-text-color-secondary)]">
                        <template v-if="kind !== 'document'"
                            >Изображения JPG, PNG, WebP, GIF, AVIF — до 10
                            МБ.</template
                        >
                        <template v-if="kind !== 'image'">
                            Документы PDF, Word, Excel, PowerPoint, TXT, CSV,
                            ZIP — до 20 МБ.</template
                        >
                    </div>
                </template>
            </el-upload>
            <el-input
                v-model="search"
                placeholder="Поиск по имени файла"
                :prefix-icon="Search"
                clearable
                class="!w-64"
                @change="load()"
            />
        </div>

        <div
            v-loading="loading"
            class="grid min-h-40 grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6"
        >
            <button
                v-for="item in page?.data ?? []"
                :key="item.id"
                type="button"
                class="group flex flex-col overflow-hidden rounded-md border-2 bg-white text-left transition hover:border-[var(--el-color-primary)]"
                :class="
                    selectable && selectedUrl === item.url
                        ? 'border-[var(--el-color-primary)]'
                        : 'border-transparent'
                "
                @click="emit('open', item)"
            >
                <img
                    v-if="item.kind === 'image'"
                    :src="item.url"
                    :alt="item.alt ?? ''"
                    loading="lazy"
                    class="aspect-square w-full bg-gray-100 object-cover"
                />
                <span
                    v-else
                    class="flex aspect-square w-full flex-col items-center justify-center gap-2 bg-gray-50 text-gray-500"
                >
                    <el-icon :size="40"><Document /></el-icon>
                    <span class="text-sm font-semibold uppercase">{{
                        item.extension
                    }}</span>
                </span>
                <span class="truncate px-2 py-1 text-xs" :title="item.name">
                    {{ item.name }}
                </span>
            </button>
            <el-empty
                v-if="!loading && !page?.data.length"
                class="col-span-full"
                :image-size="80"
                description="Файлов пока нет"
            />
        </div>

        <div v-if="page && page.last_page > 1" class="flex justify-end">
            <el-pagination
                layout="prev, pager, next"
                :total="page.total"
                :page-size="page.per_page"
                :current-page="page.current_page"
                @current-change="load"
            />
        </div>
    </div>
</template>
