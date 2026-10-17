<script setup lang="ts">
import { Document, Picture } from '@element-plus/icons-vue';
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { PropSchema } from '@/blocks/registry';
import ListInput from '@/components/blocks/editor/ListInput.vue';
import RichTextEditor from '@/components/blocks/editor/RichTextEditor.vue';
import MediaPicker from '@/components/media/MediaPicker.vue';
import { useBlockEditor } from '@/components/blocks/editor/context';
import { useCollectionsStore } from '@/stores/collections';
import { usePatternsStore } from '@/stores/patterns';

const props = defineProps<{
    schema: PropSchema;
    /** All props of the block, for fields that depend on a sibling (e.g. `field` → `collection`). */
    siblings: Record<string, unknown>;
}>();

const model = defineModel<unknown>({ required: true });
const collections = useCollectionsStore();
const editor = useBlockEditor();
const pickerOpen = ref(false);
const patterns = usePatternsStore();
const router = useRouter();

const fieldOptions = computed(() => {
    const name = props.schema.of ? props.siblings[props.schema.of] : null;
    const collection =
        typeof name === 'string' ? collections.find(name) : undefined;

    return (collection?.fields ?? []).filter(
        (f) =>
            f.type !== 'blocks' &&
            (!props.schema.fieldTypes ||
                props.schema.fieldTypes.includes(f.type)),
    );
});

/** Fields a visitor can fill in a form. */
const FORM_TYPES = [
    'string',
    'text',
    'email',
    'url',
    'integer',
    'decimal',
    'boolean',
    'date',
    'datetime',
    'select',
];

const formFieldOptions = computed(() => {
    const name = props.schema.of ? props.siblings[props.schema.of] : null;
    const collection =
        typeof name === 'string' ? collections.find(name) : undefined;

    return (collection?.fields ?? []).filter((f) =>
        FORM_TYPES.includes(f.type),
    );
});

// Fields of the record a template renders.
const recordFieldOptions = computed(() =>
    (editor.template.value?.collection.fields ?? []).filter(
        (f) => f.type !== 'blocks',
    ),
);
</script>

<template>
    <el-input
        v-if="schema.type === 'text' || schema.type === 'url'"
        v-model="model as string"
        :placeholder="schema.type === 'url' ? 'https://… или /путь' : ''"
        clearable
    />
    <div v-else-if="schema.type === 'image'" class="flex w-full flex-col gap-2">
        <img
            v-if="model"
            :src="String(model)"
            alt=""
            class="max-h-32 w-full rounded bg-gray-100 object-contain"
        />
        <div class="flex gap-2">
            <el-button size="small" :icon="Picture" @click="pickerOpen = true">
                Медиатека
            </el-button>
            <el-button v-if="model" size="small" text @click="model = ''">
                Убрать
            </el-button>
        </div>
        <el-input
            v-model="model as string"
            size="small"
            placeholder="или адрес https://…"
            clearable
        />
        <MediaPicker
            v-model="pickerOpen"
            :selected-url="String(model ?? '')"
            @pick="(item) => (model = item.url)"
        />
    </div>
    <div v-else-if="schema.type === 'file'" class="flex w-full flex-col gap-2">
        <div class="flex gap-2">
            <el-button size="small" :icon="Document" @click="pickerOpen = true">
                Документы
            </el-button>
            <el-button v-if="model" size="small" text @click="model = ''">
                Убрать
            </el-button>
        </div>
        <el-input
            v-model="model as string"
            size="small"
            placeholder="или адрес https://…"
            clearable
        />
        <MediaPicker
            v-model="pickerOpen"
            kind="document"
            :selected-url="String(model ?? '')"
            @pick="(item) => (model = item.url)"
        />
    </div>
    <el-input
        v-else-if="schema.type === 'textarea'"
        v-model="model as string"
        type="textarea"
        :autosize="{ minRows: 3, maxRows: 10 }"
    />
    <ListInput
        v-else-if="schema.type === 'list'"
        v-model="model"
        :schema="schema"
    />
    <RichTextEditor v-else-if="schema.type === 'richtext'" v-model="model" />
    <el-input-number
        v-else-if="schema.type === 'number'"
        v-model="model as number"
        :min="schema.min"
        :max="schema.max"
        :step="schema.step ?? 1"
        controls-position="right"
    />
    <el-switch
        v-else-if="schema.type === 'boolean'"
        v-model="model as boolean"
    />
    <el-select v-else-if="schema.type === 'select'" v-model="model as string">
        <el-option
            v-for="option in schema.options ?? []"
            :key="option.value"
            :label="option.label"
            :value="option.value"
        />
    </el-select>
    <div v-else-if="schema.type === 'color'" class="flex items-center gap-2">
        <el-color-picker v-model="model as string" />
        <span class="text-xs text-[var(--el-text-color-secondary)]">
            {{ model || 'нет' }}
        </span>
    </div>
    <el-select
        v-else-if="schema.type === 'collection'"
        v-model="model as string"
        clearable
        placeholder="Выберите коллекцию"
    >
        <el-option
            v-for="c in collections.items"
            :key="c.name"
            :label="c.label"
            :value="c.name"
        />
    </el-select>
    <el-select
        v-else-if="schema.type === 'field'"
        v-model="model as string"
        clearable
        :disabled="!fieldOptions.length"
        placeholder="Не показывать"
    >
        <el-option
            v-for="f in fieldOptions"
            :key="f.name"
            :label="f.label"
            :value="f.name"
        />
    </el-select>
    <el-select
        v-else-if="schema.type === 'fields'"
        :model-value="Array.isArray(model) ? model : []"
        multiple
        clearable
        :disabled="!formFieldOptions.length"
        placeholder="Выберите поля"
        @update:model-value="(value: string[]) => (model = value)"
    >
        <el-option
            v-for="f in formFieldOptions"
            :key="f.name"
            :label="f.is_required ? `${f.label} (обязательное)` : f.label"
            :value="f.name"
        />
    </el-select>
    <el-select
        v-else-if="schema.type === 'record_field'"
        v-model="model as string"
        clearable
        placeholder="Поле записи"
    >
        <el-option
            v-for="f in recordFieldOptions"
            :key="f.name"
            :label="`${f.label} · ${f.type_label}`"
            :value="f.name"
        />
    </el-select>
    <div
        v-else-if="schema.type === 'pattern'"
        class="flex w-full flex-col gap-2"
    >
        <el-select v-model="model as number" placeholder="Выберите секцию">
            <el-option
                v-for="p in patterns.items"
                :key="p.id"
                :label="p.name"
                :value="p.id"
            />
        </el-select>
        <el-button
            v-if="model"
            size="small"
            @click="
                router.push({
                    name: 'patterns.edit',
                    params: { id: Number(model) },
                })
            "
        >
            Редактировать секцию
        </el-button>
        <div class="text-xs text-[var(--el-text-color-secondary)]">
            Изменения секции появятся на всех страницах, где она используется.
        </div>
    </div>
    <div
        v-if="schema.help"
        class="mt-1 w-full text-xs leading-snug text-[var(--el-text-color-secondary)]"
    >
        {{ schema.help }}
    </div>
</template>
