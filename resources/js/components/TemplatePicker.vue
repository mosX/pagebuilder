<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { send } from '@/lib/http';
import { siteTemplates } from '@/routes/api';

type Template = {
    key: string;
    label: string;
    description: string;
    /** A site saved as a template by a user. */
    custom?: boolean;
};

/**
 * Choice of the starter site a new company is created with.
 */
const model = defineModel<string>({ required: true });
const templates = ref<Template[]>([]);

onMounted(async () => {
    templates.value = await send<Template[]>(siteTemplates());
});
</script>

<template>
    <div class="grid w-full grid-cols-2 gap-2">
        <button
            v-for="template in templates"
            :key="template.key"
            type="button"
            class="rounded border p-3 text-left transition"
            :class="
                model === template.key
                    ? 'border-[var(--el-color-primary)] bg-[var(--el-color-primary-light-9)]'
                    : 'border-[var(--el-border-color)] hover:border-[var(--el-color-primary-light-5)]'
            "
            @click="model = template.key"
        >
            <div class="flex items-center gap-1 text-sm font-medium">
                {{ template.label }}
                <el-tag v-if="template.custom" size="small" type="success">
                    свой
                </el-tag>
            </div>
            <div
                class="mt-1 text-xs leading-snug text-[var(--el-text-color-secondary)]"
            >
                {{ template.description }}
            </div>
        </button>
    </div>
</template>
