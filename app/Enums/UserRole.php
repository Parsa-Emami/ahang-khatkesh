<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Curator = 'curator';
    case Listener = 'listener';

    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Administrator => [Permission::AdminAccess, Permission::DashboardRead, Permission::CatalogManage, Permission::MediaManage, Permission::ImportsManage, Permission::UsersManage, Permission::AuditRead, Permission::OperationsRead],
            self::Curator => [Permission::AdminAccess, Permission::DashboardRead, Permission::CatalogManage, Permission::MediaManage, Permission::ImportsManage],
            self::Listener => [],
        };
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function isPrivileged(): bool
    {
        return in_array($this, [self::Owner, self::Administrator], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'مالک', self::Administrator => 'مدیر', self::Curator => 'مدیر محتوا', self::Listener => 'شنونده'
        };
    }
}
