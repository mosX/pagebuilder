import type { BlockNode } from '@/blocks/registry';

export type SiteLanguage = {
    code: string;
    label: string;
    url: string;
    active: boolean;
};

export type SiteInfo = {
    name: string;
    slug: string;
    /** '' on the company subdomain, '/site/{slug}' on the preview path, plus '/{locale}'. */
    base_url: string;
    /** base_url without the language prefix. */
    root_url: string;
    pages: { title: string; slug: string; url: string }[];
    locale: string;
    /** The current page in every language; empty for single-language sites. */
    languages: SiteLanguage[];
    search_url: string;
    /** Visitor accounts, when the company turned them on. */
    account: {
        url: string;
        login_url: string;
        register_url: string | null;
        member: {
            name: string;
            email: string;
            /** Extra profile fields of the site (phone…), for prefilling forms. */
            profile?: Record<string, string>;
        } | null;
    } | null;
};

export type SitePayload = {
    site: SiteInfo;
    page: {
        title: string;
        slug: string;
        description: string | null;
        blocks: BlockNode[];
    } | null;
    /** CSS variables of the company theme (--site-primary, …). */
    theme: Record<string, string>;
    /** Header and footer, edited in the block constructor. */
    layout: {
        header: BlockNode[];
        footer: BlockNode[];
    };
};
