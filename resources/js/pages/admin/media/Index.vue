<script setup lang="ts">
import { CopyDocument, Delete } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { ref } from 'vue';
import MediaGrid from '@/components/media/MediaGrid.vue';
import { useForm } from '@/composables/useForm';
import { send } from '@/lib/http';
import media from '@/routes/api/media';
import { useSessionStore } from '@/stores/session';
import type { MediaItem, MessageResponse } from '@/types';

const session = useSessionStore();
const grid = ref<InstanceType<typeof MediaGrid> | null>(null);
const current = ref<MediaItem | null>(null);
const form = useForm({ alt: '' });

function open(item: MediaItem) {
    current.value = item;
    form.alt = item.alt ?? '';
    form.defaults();
}

function size(bytes: number) {
    return bytes > 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} МБ`
        : `${Math.round(bytes / 1024)} КБ`;
}

async function copyUrl(item: MediaItem) {
    await navigator.clipboard.writeText(
        new URL(item.url, location.origin).href,
    );
    ElMessage.success('Ссылка скопирована.');
}

async function saveAlt() {
    if (!current.value) {
        return;
    }

    const result = await form.submit<MessageResponse & { media: MediaItem }>(
        media.update(current.value.id),
    );

    if (result) {
        current.value = result.media;
        form.defaults();
        ElMessage.success(result.message);
        grid.value?.reload();
    }
}

async function remove(item: MediaItem) {
    const result = await send<MessageResponse>(media.destroy(item.id));
    ElMessage.success(result.message);
    current.value = null;
    grid.value?.reload();
}
</script>

<template>
    <div>
        <div class="mb-4">
            <h1 class="text-xl font-semibold">Медиатека</h1>
            <p class="text-sm text-[var(--el-text-color-secondary)]">
                Изображения компании для страниц и блоков.
            </p>
        </div>

        <el-card shadow="never">
            <MediaGrid ref="grid" @open="open" />
        </el-card>

        <el-drawer
            :model-value="current !== null"
            title="Файл"
            size="420px"
            @close="current = null"
        >
            <div v-if="current" class="flex flex-col gap-4">
                <img
                    :src="current.url"
                    :alt="current.alt ?? ''"
                    class="w-full rounded-md bg-gray-100"
                />
                <el-descriptions :column="1" size="small" border>
                    <el-descriptions-item label="Имя">
                        {{ current.name }}
                    </el-descriptions-item>
                    <el-descriptions-item label="Размер">
                        {{ size(current.size) }}
                    </el-descriptions-item>
                    <el-descriptions-item
                        v-if="current.width"
                        label="Разрешение"
                    >
                        {{ current.width }} × {{ current.height }}
                    </el-descriptions-item>
                </el-descriptions>

                <el-form label-position="top" @submit.prevent="saveAlt">
                    <el-form-item
                        label="Альтернативный текст"
                        :error="form.errors.alt"
                    >
                        <el-input
                            v-model="form.alt"
                            :disabled="!session.can('content.manage')"
                            placeholder="Что изображено — для доступности и SEO"
                        />
                    </el-form-item>
                    <div class="flex gap-2">
                        <el-button
                            v-if="session.can('content.manage')"
                            type="primary"
                            native-type="submit"
                            :loading="form.processing"
                            :disabled="!form.isDirty"
                        >
                            Сохранить
                        </el-button>
                        <el-button
                            :icon="CopyDocument"
                            @click="copyUrl(current)"
                        >
                            Ссылка
                        </el-button>
                        <el-popconfirm
                            v-if="session.can('content.manage')"
                            title="Удалить файл? На страницах он перестанет отображаться."
                            confirm-button-text="Удалить"
                            cancel-button-text="Отмена"
                            width="280"
                            @confirm="remove(current)"
                        >
                            <template #reference>
                                <el-button type="danger" plain :icon="Delete" />
                            </template>
                        </el-popconfirm>
                    </div>
                </el-form>
            </div>
        </el-drawer>
    </div>
</template>
