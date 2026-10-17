<script setup lang="ts">
import { computed, inject } from 'vue';
import { currentSiteKey } from '@/site/current-page';

defineProps<{
    block: {
        login_label: string;
        show_register: boolean;
        variant: 'link' | 'button';
    };
}>();

const site = inject(currentSiteKey, null);
const account = computed(() => site?.value?.account ?? null);
// The editor has no site context: show the guest state as a preview.
const inEditor = computed(() => !site?.value);

const styles = {
    link: 'text-ink/80 hover:text-brand',
    button: 'rounded-site bg-brand px-4 py-2 font-medium text-brand-contrast hover:bg-brand/90',
};
</script>

<template>
    <div v-if="account || inEditor" class="flex items-center gap-4 text-sm">
        <a
            v-if="account?.member"
            :href="account.url"
            class="transition"
            :class="styles[block.variant]"
        >
            {{ account.member.name }}
        </a>
        <template v-else>
            <a
                :href="account?.login_url"
                class="transition"
                :class="styles[block.variant]"
            >
                {{ block.login_label || 'Войти' }}
            </a>
            <a
                v-if="
                    block.show_register && (inEditor || account?.register_url)
                "
                :href="account?.register_url ?? undefined"
                class="text-ink/80 transition hover:text-brand"
            >
                Регистрация
            </a>
        </template>
        <span v-if="inEditor" class="text-xs text-gray-400">
            Работает, если кабинет включён в разделе «Сайт»
        </span>
    </div>
</template>
