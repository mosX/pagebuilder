<script setup lang="ts">
import { RefreshLeft } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { onMounted, ref } from 'vue';
import { send } from '@/lib/http';
import records from '@/routes/api/records';
import type { CollectionRecord, CollectionSchema } from '@/types';

type Revision = {
    id: number;
    created_at: string | null;
    author: string;
    changes: string[];
    values: Record<string, unknown>;
};

/**
 * Versions of a record: who changed what and when, and a way back to an earlier version.
 */
const props = defineProps<{
    collection: CollectionSchema;
    record: CollectionRecord;
    readonly?: boolean;
}>();

const emit = defineEmits<{ restored: [] }>();

const revisions = ref<Revision[]>([]);
const loading = ref(true);

onMounted(async () => {
    try {
        const result = await send<{ revisions: Revision[] }>(
            records.revisions({
                collection: props.collection.name,
                record: props.record.id,
            }),
        );
        revisions.value = result.revisions;
    } finally {
        loading.value = false;
    }
});

function label(name: string) {
    return props.collection.fields.find((f) => f.name === name)?.label ?? name;
}

/** A value as text: relations by their titles, empty as a dash. */
function text(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.map(text).join(', ') || '—';
    }

    if (typeof value === 'object') {
        return String((value as { label?: unknown }).label ?? '…');
    }

    if (typeof value === 'boolean') {
        return value ? 'Да' : 'Нет';
    }

    const plain = String(value).startsWith('private://')
        ? (String(value).split('/').pop() ?? '')
        : String(value);

    return plain.length > 60 ? `${plain.slice(0, 60)}…` : plain;
}

function when(value: string | null) {
    return value
        ? new Date(value).toLocaleString('ru-RU', {
              dateStyle: 'short',
              timeStyle: 'short',
          })
        : '';
}

async function restore(revision: Revision) {
    const result = await send<{ message: string }>(
        records.revisions.restore({
            collection: props.collection.name,
            record: props.record.id,
            revision: revision.id,
        }),
    );
    ElMessage.success(result.message);
    emit('restored');
}
</script>

<template>
    <div v-loading="loading" class="mt-6">
        <el-divider content-position="left">История изменений</el-divider>
        <p
            v-if="!loading && !revisions.length"
            class="text-sm text-[var(--el-text-color-secondary)]"
        >
            Изменений пока не было.
        </p>
        <el-timeline v-else class="!pl-1">
            <el-timeline-item
                v-for="(revision, index) in revisions"
                :key="revision.id"
                :timestamp="`${when(revision.created_at)} · ${revision.author}`"
                placement="top"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="flex min-w-0 flex-col gap-0.5 text-sm">
                        <span
                            v-if="!revision.changes.length"
                            class="text-[var(--el-text-color-secondary)]"
                        >
                            Запись создана
                        </span>
                        <span
                            v-for="field in revision.changes"
                            :key="field"
                            class="truncate"
                        >
                            <b>{{ label(field) }}:</b>
                            <span
                                v-if="revisions[index + 1]"
                                class="text-[var(--el-text-color-secondary)] line-through"
                            >
                                {{ text(revisions[index + 1].values[field]) }}
                            </span>
                            → {{ text(revision.values[field]) }}
                        </span>
                    </div>
                    <el-popconfirm
                        v-if="index > 0 && !readonly"
                        title="Вернуть запись к этой версии?"
                        confirm-button-text="Вернуть"
                        cancel-button-text="Отмена"
                        width="240"
                        @confirm="restore(revision)"
                    >
                        <template #reference>
                            <el-button :icon="RefreshLeft" size="small" text>
                                Вернуть
                            </el-button>
                        </template>
                    </el-popconfirm>
                </div>
            </el-timeline-item>
        </el-timeline>
    </div>
</template>
