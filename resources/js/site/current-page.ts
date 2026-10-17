import type { ComputedRef, InjectionKey } from 'vue';
import type { SiteInfo } from '@/site/types';

/** Slug of the page being shown, for highlighting it in menus. */
export const currentPageKey: InjectionKey<ComputedRef<string | null>> =
    Symbol('currentPage');

/**
 * The site being shown: search, account and language links for header blocks.
 * Not provided in the editor, where these blocks show a placeholder.
 */
export const currentSiteKey: InjectionKey<ComputedRef<SiteInfo | null>> =
    Symbol('currentSite');
