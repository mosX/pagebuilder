<script setup lang="ts">
import {
    ArrowDown,
    Coin,
    Document,
    Expand,
    Fold,
    Monitor,
    Odometer,
    OfficeBuilding,
    Picture,
    Setting,
    User,
    UserFilled,
} from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useCollectionsStore } from '@/stores/collections';
import { useSessionStore } from '@/stores/session';

type MenuItem = { path: string; label: string; icon: Component };
type MenuGroup = { title: string; items: MenuItem[] };

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const session = useSessionStore();
const collections = useCollectionsStore();
const route = useRoute();
const router = useRouter();
const collapsed = ref(false);
const switching = ref(false);

// Collections belong to the company, so reload them whenever the company changes.
watch(
    () => session.tenant?.current.id,
    () => {
        collections.reset();

        if (session.can('content.view')) {
            void collections.load();
        }
    },
    { immediate: true },
);

const groups = computed<MenuGroup[]>(() => {
    const visible = (items: (MenuItem & { show: boolean })[]) =>
        items.filter((item) => item.show);

    return [
        {
            title: '',
            items: [{ path: '/admin', label: 'Дашборд', icon: Odometer }],
        },
        {
            title: 'Контент',
            items: [
                ...collections.items.map((c) => ({
                    path: `/admin/content/${c.name}`,
                    label: c.label,
                    icon: Document,
                })),
                ...(session.can('content.view')
                    ? [
                          {
                              path: '/admin/media',
                              label: 'Медиатека',
                              icon: Picture,
                          },
                      ]
                    : []),
            ],
        },
        {
            title: 'Настройки',
            items: visible([
                {
                    path: '/admin/collections',
                    label: 'Коллекции',
                    icon: Coin,
                    show: session.can('content.view'),
                },
                {
                    path: '/admin/members',
                    label: 'Пользователи',
                    icon: User,
                    show: session.can('members.view'),
                },
                {
                    path: '/admin/company',
                    label: 'Компания',
                    icon: Setting,
                    show: session.can('company.view'),
                },
            ]),
        },
        {
            title: 'Платформа',
            items: visible([
                {
                    path: '/admin/super/tenants',
                    label: 'Все компании',
                    icon: OfficeBuilding,
                    show: session.isSuperAdmin,
                },
            ]),
        },
    ].filter((group) => group.items.length);
});

// Longest matching prefix wins, so /admin does not swallow /admin/members.
const activeMenu = computed(
    () =>
        groups.value
            .flatMap((g) => g.items)
            .filter(
                (i) =>
                    route.path === i.path ||
                    route.path.startsWith(`${i.path}/`),
            )
            .sort((a, b) => b.path.length - a.path.length)[0]?.path ??
        route.path,
);

async function onSwitchTenant(id: number) {
    if (id === session.tenant?.current.id) {
        return;
    }

    switching.value = true;

    try {
        await session.switchTenant(id);
        ElMessage.success(
            `Вы работаете в компании «${session.tenant?.current.name}».`,
        );
        await router.push({ name: 'dashboard' });
    } finally {
        switching.value = false;
    }
}

async function onUserCommand(command: string) {
    if (command === 'profile') {
        await router.push({ name: 'profile' });
    } else if (command === 'logout') {
        await session.logout();
        await router.push({ name: 'login' });
    }
}
</script>

<template>
    <el-container class="h-screen">
        <el-aside
            :width="collapsed ? '64px' : '230px'"
            class="flex flex-col border-r border-[var(--el-border-color-light)] bg-white transition-all"
        >
            <router-link
                :to="{ name: 'dashboard' }"
                class="flex h-14 items-center gap-2 border-b border-[var(--el-border-color-light)] px-5 font-semibold text-[var(--el-color-primary)]"
            >
                <el-icon :size="22"><OfficeBuilding /></el-icon>
                <span v-if="!collapsed">{{ appName }}</span>
            </router-link>

            <el-menu
                :default-active="activeMenu"
                :collapse="collapsed"
                class="flex-1 overflow-y-auto !border-r-0"
                @select="(path: string) => router.push(path)"
            >
                <template v-for="group in groups" :key="group.title">
                    <el-menu-item-group :title="collapsed ? '' : group.title">
                        <el-menu-item
                            v-for="item in group.items"
                            :key="item.path"
                            :index="item.path"
                        >
                            <el-icon><component :is="item.icon" /></el-icon>
                            <template #title>{{ item.label }}</template>
                        </el-menu-item>
                    </el-menu-item-group>
                </template>
            </el-menu>
        </el-aside>

        <el-container>
            <el-header
                class="flex items-center gap-4 border-b border-[var(--el-border-color-light)] bg-white"
            >
                <el-button text @click="collapsed = !collapsed">
                    <el-icon :size="18">
                        <Expand v-if="collapsed" />
                        <Fold v-else />
                    </el-icon>
                </el-button>

                <el-select
                    v-if="session.tenant"
                    :model-value="session.tenant.current.id"
                    :loading="switching"
                    class="!w-64"
                    filterable
                    @change="onSwitchTenant"
                >
                    <template #prefix>
                        <el-icon><OfficeBuilding /></el-icon>
                    </template>
                    <el-option
                        v-for="t in session.tenant.available"
                        :key="t.id"
                        :label="t.name"
                        :value="t.id"
                    />
                </el-select>

                <el-tag v-if="session.tenant?.role" type="info">
                    {{ session.tenant.role.label }}
                </el-tag>
                <el-tag v-if="session.isSuperAdmin" type="danger">
                    Суперадмин
                </el-tag>

                <div class="flex-1" />

                <el-button
                    v-if="session.tenant"
                    tag="a"
                    :href="session.tenant.current.site_url"
                    target="_blank"
                    :icon="Monitor"
                    text
                >
                    Сайт
                </el-button>

                <el-dropdown trigger="click" @command="onUserCommand">
                    <span
                        class="flex cursor-pointer items-center gap-2 outline-none"
                    >
                        <el-avatar :size="30" :icon="UserFilled" />
                        <span>{{ session.user?.name }}</span>
                        <el-icon><ArrowDown /></el-icon>
                    </span>
                    <template #dropdown>
                        <el-dropdown-menu>
                            <el-dropdown-item command="profile">
                                Профиль
                            </el-dropdown-item>
                            <el-dropdown-item command="logout" divided>
                                Выйти
                            </el-dropdown-item>
                        </el-dropdown-menu>
                    </template>
                </el-dropdown>
            </el-header>

            <el-main class="bg-[var(--el-bg-color-page)]">
                <!-- Re-mount pages after a company switch so they reload their data. -->
                <router-view
                    :key="`${session.tenant?.current.id}:${route.fullPath}`"
                />
            </el-main>
        </el-container>
    </el-container>
</template>
