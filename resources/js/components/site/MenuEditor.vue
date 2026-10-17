<script setup lang="ts">
import { ArrowDown, ArrowUp, Delete, Plus } from '@element-plus/icons-vue';

export type MenuItem = {
    label: string;
    /** Labels in the site's other languages. */
    labels?: Record<string, string>;
    type: 'page' | 'url';
    page: string;
    url: string;
    children: MenuItem[];
};

/**
 * Editor of the site menu: items link to a page (by slug, so links work on any domain)
 * or to any URL; each item may have one level of sub-items.
 */
const props = defineProps<{
    pages: { title: string; slug: string; is_published: boolean | number }[];
    /** Sub-items cannot have their own children. */
    nested?: boolean;
    disabled?: boolean;
    /** The site's additional languages: each item gets a label per language. */
    languages?: { value: string; label: string }[];
}>();

const items = defineModel<MenuItem[]>({ required: true });

function add() {
    const page = props.pages[0];
    items.value = [
        ...items.value,
        {
            label: page?.title ?? 'Пункт',
            labels: {},
            type: 'page',
            page: page?.slug ?? '',
            url: '',
            children: [],
        },
    ];
}

function remove(index: number) {
    items.value = items.value.filter((_, i) => i !== index);
}

function move(index: number, delta: number) {
    const next = [...items.value];
    const target = index + delta;

    if (target >= 0 && target < next.length) {
        [next[index], next[target]] = [next[target], next[index]];
        items.value = next;
    }
}

function onPage(item: MenuItem, slug: string) {
    // Follow the page title until the label was changed by hand.
    const previous = props.pages.find((p) => p.slug === item.page);

    if (!item.label || item.label === previous?.title) {
        item.label =
            props.pages.find((p) => p.slug === slug)?.title ?? item.label;
    }

    item.page = slug;
}

function setLabel(item: MenuItem, locale: string, label: string) {
    item.labels = { ...item.labels, [locale]: label };
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div
            v-for="(item, index) in items"
            :key="index"
            class="flex flex-col gap-2 rounded border border-[var(--el-border-color-lighter)] p-3"
            :class="{ 'bg-[var(--el-fill-color-lighter)]': nested }"
        >
            <div class="flex flex-wrap items-center gap-2">
                <el-input
                    v-model="item.label"
                    :disabled="disabled"
                    placeholder="Название"
                    class="!w-48"
                />
                <el-select
                    v-model="item.type"
                    :disabled="disabled"
                    class="!w-36"
                >
                    <el-option label="Страница" value="page" />
                    <el-option label="Ссылка" value="url" />
                </el-select>
                <el-select
                    v-if="item.type === 'page'"
                    :model-value="item.page"
                    :disabled="disabled"
                    filterable
                    class="!w-56"
                    @update:model-value="(slug: string) => onPage(item, slug)"
                >
                    <el-option
                        v-for="page in pages"
                        :key="page.slug"
                        :label="
                            page.is_published
                                ? page.title
                                : `${page.title} (не опубликована)`
                        "
                        :value="page.slug"
                    />
                </el-select>
                <el-input
                    v-else
                    v-model="item.url"
                    :disabled="disabled"
                    placeholder="https://… или /articles"
                    class="!w-56"
                />
                <span class="flex-1" />
                <el-button
                    :icon="ArrowUp"
                    link
                    :disabled="disabled || index === 0"
                    @click="move(index, -1)"
                />
                <el-button
                    :icon="ArrowDown"
                    link
                    :disabled="disabled || index === items.length - 1"
                    @click="move(index, 1)"
                />
                <el-button
                    :icon="Delete"
                    link
                    type="danger"
                    :disabled="disabled"
                    @click="remove(index)"
                />
            </div>
            <div
                v-if="languages?.length"
                class="flex flex-wrap items-center gap-2"
            >
                <el-input
                    v-for="language in languages"
                    :key="language.value"
                    :model-value="item.labels?.[language.value] ?? ''"
                    :disabled="disabled"
                    :placeholder="item.label"
                    size="small"
                    class="!w-48"
                    @update:model-value="
                        (label: string) => setLabel(item, language.value, label)
                    "
                >
                    <template #prepend>{{
                        language.value.toUpperCase()
                    }}</template>
                </el-input>
            </div>
            <div v-if="!nested" class="pl-6">
                <MenuEditor
                    v-model="item.children"
                    :pages="pages"
                    nested
                    :disabled="disabled"
                    :languages="languages"
                />
            </div>
        </div>
        <el-button
            :icon="Plus"
            size="small"
            class="self-start"
            :disabled="disabled || items.length >= 12"
            @click="add"
        >
            {{ nested ? 'Подпункт' : 'Пункт меню' }}
        </el-button>
    </div>
</template>
