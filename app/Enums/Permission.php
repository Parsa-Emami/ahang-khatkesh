<?php

namespace App\Enums;

enum Permission: string
{
    case AdminAccess = 'admin.access';
    case DashboardRead = 'dashboard.read';
    case CatalogManage = 'catalog.manage';
    case MediaManage = 'media.manage';
    case ImportsManage = 'imports.manage';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case AuditRead = 'audit.read';
    case OperationsRead = 'operations.read';
}
