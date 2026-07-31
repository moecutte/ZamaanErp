<?php

namespace App\Filament\Concerns;

trait HasRoleAccess
{
    /**
     * Roles allowed to see this resource/page. Empty = all authenticated panel users.
     *
     * @return list<string>
     */
    public static function allowedRoles(): array
    {
        return ['admin'];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        $roles = static::allowedRoles();

        return $roles === [] || $user->hasAnyRole($roles);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit($record): bool
    {
        return static::canAccess();
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
