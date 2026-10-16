import axios from 'axios';
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { send } from '@/lib/http';
import { logout as logoutRoute, me } from '@/routes/api';
import { create, switchMethod } from '@/routes/api/tenants';
import { csrfCookie } from '@/routes/sanctum';
import type { Permission, Session, TenantState, User } from '@/types';

/**
 * The signed-in user and the company they currently work in, as returned by /api/me.
 */
export const useSessionStore = defineStore('session', () => {
    const user = ref<User | null>(null);
    const tenant = ref<TenantState | null>(null);
    const loaded = ref(false);

    const isSuperAdmin = computed(() => user.value?.is_super_admin ?? false);

    function can(permission: Permission): boolean {
        return tenant.value?.permissions.includes(permission) ?? false;
    }

    function set(session: Session | null) {
        user.value = session?.user ?? null;
        tenant.value = session?.tenant ?? null;
        loaded.value = true;
    }

    async function load(force = false) {
        if (loaded.value && !force) {
            return;
        }

        try {
            set(await send<Session>(me()));
        } catch (error) {
            if (axios.isAxiosError(error) && error.response?.status === 401) {
                set(null);
            } else {
                throw error;
            }
        }
    }

    /**
     * Sanctum needs the XSRF-TOKEN cookie before the first state-changing request.
     */
    async function prepareCsrf() {
        await send(csrfCookie());
    }

    async function logout() {
        await send(logoutRoute());
        set(null);
    }

    async function switchTenant(id: number) {
        set(await send<Session>(switchMethod(id)));
    }

    async function createTenant(name: string) {
        set(await send<Session>(create(), { name }));
    }

    return {
        user,
        tenant,
        loaded,
        isSuperAdmin,
        can,
        set,
        load,
        prepareCsrf,
        logout,
        switchTenant,
        createTenant,
    };
});
