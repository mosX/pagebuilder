import type { Directive } from 'vue';
import type { Permission } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }
}

declare module 'vue-router' {
    interface RouteMeta {
        title?: string;
        /** Only for signed-out users (login, register). */
        guest?: boolean;
        /** Requires a signed-in user. */
        auth?: boolean;
        /** Requires a current company. */
        tenant?: boolean;
        permission?: Permission;
        superAdmin?: boolean;
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }
}
