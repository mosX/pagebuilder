import { defineStore } from 'pinia';
import { ref } from 'vue';
import { send } from '@/lib/http';
import siteSettings from '@/routes/api/site-settings';

type Design = { vars: Record<string, string>; fonts_url: string };

/**
 * The company theme for the editor canvas, so blocks look as they will on the site.
 */
export const useSiteThemeStore = defineStore('site-theme', () => {
    const vars = ref<Record<string, string>>({});
    const loaded = ref(false);
    const fontLinks = new Set<string>();

    /** Loads a font stylesheet once. */
    function useFonts(url: string) {
        if (fontLinks.has(url)) {
            return;
        }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        document.head.appendChild(link);
        fontLinks.add(url);
    }

    async function load() {
        if (loaded.value) {
            return;
        }

        const { design } = await send<{ design: Design }>(siteSettings.show());
        vars.value = design.vars;
        useFonts(design.fonts_url);
        loaded.value = true;
    }

    /** After the theme changed: reload on next use. */
    function reset() {
        loaded.value = false;
    }

    return { vars, loaded, load, reset, useFonts };
});
