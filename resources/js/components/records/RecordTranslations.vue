<script setup lang="ts">
import { ElMessage } from 'element-plus';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { send } from '@/lib/http';
import translations from '@/routes/api/records/translations';
import type { CollectionRecord, CollectionSchema } from '@/types';

type Translatable = { name: string; label: string; type: string };

type Response = {
    languages: { default: string; extra: string[] };
    locales: Record<string, string>;
    fields: Translatable[];
    translations: Record<string, Record<string, unknown>>;
};

/**
 * Translations of a record's text fields into the site's additional languages.
 * Block fields are translated in the constructor; empty values fall back to the original.
 */
const props = defineProps<{
    collection: CollectionSchema;
    record: CollectionRecord;
    readonly?: boolean;
}>();

const router = useRouter();
const data = ref<Response | null>(null);
const tab = ref('');
const values = reactive<Record<string, Record<string, string>>>({});
const saving = ref(false);

const textFields = computed(
    () => data.value?.fields.filter((f) => f.type !== 'blocks') ?? [],
);
const blockFields = computed(
    () => data.value?.fields.filter((f) => f.type === 'blocks') ?? [],
);

onMounted(async () => {
    const response = await send<Response>(
        translations.show({
            collection: props.collection.name,
            record: props.record.id,
        }),
    );

    for (const locale of response.languages.extra) {
        const stored = response.translations[locale] ?? {};

        values[locale] = Object.fromEntries(
            response.fields
                .filter((f) => f.type !== 'blocks')
                .map((f) => [
                    f.name,
                    typeof stored[f.name] === 'string'
                        ? (stored[f.name] as string)
                        : '',
                ]),
        );
    }

    tab.value = response.languages.extra[0] ?? '';
    data.value = response;
});

function original(field: string) {
    const value = props.record[field];

    return typeof value === 'string' ? value : '';
}

async function save(locale: string) {
    saving.value = true;

    try {
        const result = await send<{ message: string }>(
            translations.update({
                collection: props.collection.name,
                record: props.record.id,
                locale,
            }),
            { values: values[locale] },
        );
        ElMessage.success(result.message);
    } finally {
        saving.value = false;
    }
}

function openConstructor(field: string, locale: string) {
    void router.push({
        name: 'blocks.editor',
        params: { name: props.collection.name, id: props.record.id, field },
        query: { locale },
    });
}
</script>

<template>
    <div
        v-if="data && data.languages.extra.length && data.fields.length"
        class="mt-6"
    >
        <el-divider content-position="left">Переводы</el-divider>
        <el-tabs v-model="tab">
            <el-tab-pane
                v-for="locale in data.languages.extra"
                :key="locale"
                :label="data.locales[locale] ?? locale"
                :name="locale"
            >
                <el-form label-position="top" :disabled="readonly">
                    <el-form-item
                        v-for="field in textFields"
                        :key="field.name"
                        :label="field.label"
                    >
                        <el-input
                            v-model="values[locale][field.name]"
                            :type="field.type === 'text' ? 'textarea' : 'text'"
                            :autosize="{ minRows: 2, maxRows: 8 }"
                            :placeholder="original(field.name)"
                        />
                    </el-form-item>
                    <el-form-item
                        v-for="field in blockFields"
                        :key="field.name"
                        :label="field.label"
                    >
                        <el-button
                            plain
                            @click="openConstructor(field.name, locale)"
                        >
                            Перевести в конструкторе
                        </el-button>
                    </el-form-item>
                </el-form>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs text-[var(--el-text-color-secondary)]">
                        Пустые поля показываются на основном языке.
                    </span>
                    <el-button
                        v-if="!readonly && textFields.length"
                        type="primary"
                        plain
                        :loading="saving"
                        @click="save(locale)"
                    >
                        Сохранить перевод
                    </el-button>
                </div>
            </el-tab-pane>
        </el-tabs>
    </div>
</template>
