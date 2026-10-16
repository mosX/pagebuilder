<?php

namespace App\Enums;

enum TenantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Владелец',
            self::Admin => 'Администратор',
            self::Editor => 'Редактор',
            self::Viewer => 'Наблюдатель',
        };
    }

    /**
     * Higher level means more privileges.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 40,
            self::Admin => 30,
            self::Editor => 20,
            self::Viewer => 10,
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner, self::Admin => Permission::cases(),
            self::Editor => [Permission::CompanyView, Permission::MembersView, Permission::ContentView, Permission::ContentManage],
            self::Viewer => [Permission::CompanyView, Permission::ContentView],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Whether a member with this role may change or remove a member with the given role.
     * Owners manage everyone; others only manage members strictly below them.
     */
    public function canManage(self $other): bool
    {
        return $this === self::Owner || $this->level() > $other->level();
    }

    /**
     * Whether a member with this role may grant the given role.
     */
    public function canAssign(self $role): bool
    {
        return $this === self::Owner || $this->level() > $role->level();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $role) => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }
}
