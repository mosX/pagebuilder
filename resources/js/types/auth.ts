export type User = {
    id: number;
    name: string;
    email: string;
    is_super_admin: boolean;
    email_verified: boolean;
};

export type Option = {
    value: string;
    label: string;
};

export type Permission =
    | 'company.view'
    | 'company.update'
    | 'members.view'
    | 'members.manage'
    | 'schema.manage'
    | 'content.view'
    | 'content.manage'
    | 'audit.view'
    | 'api.manage';

export type TenantState = {
    current: { id: number; name: string; slug: string; site_url: string };
    role: Option | null;
    permissions: Permission[];
    available: { id: number; name: string }[];
};

export type Session = {
    user: User;
    tenant: TenantState | null;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

export type MessageResponse = {
    message: string;
};
