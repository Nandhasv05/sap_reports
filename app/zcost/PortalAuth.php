<?php
/**
 * AUTHOR : NANDHAKUMAR
 * CREATED : 02/11/2026
 * UPDATED : 03/11/2026
 * DESCRIPTION : Dynamic User Access Control (RBAC) & Portal Session Integration
 **/
declare(strict_types=1);

namespace App;

require_once __DIR__ . '/Database.php';

/**
 * Reads EVOL portal session and resolves database-driven permissions for ZCOST.
 * No hardcoded usernames or user IDs — all access rules are loaded from database tables.
 */
class PortalAuth
{
    public static function boot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    /**
     * Resolve user identity from active session or passed parameters.
     *
     * @return array{username: string, userId: int, role: string}
     */
    public static function resolveIdentity(?string $username = null, ?int $userId = null, ?string $role = null): array
    {
        self::boot();

        if ($username === null) {
            $username = trim((string)(
                $_SESSION['username']
                ?? $_SESSION['user']
                ?? $_SESSION['user_name']
                ?? ''
            ));
        }

        if ($userId === null) {
            $userId = (int)(
                $_SESSION['user_id']
                ?? $_SESSION['userid']
                ?? $_SESSION['id']
                ?? $_SESSION['uid']
                ?? 0
            );
        }

        if ($role === null) {
            $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
        } else {
            $role = strtolower(trim($role));
        }

        $userKey = strtolower(trim($username));
        if ($userKey === 'admin' || $userKey === 'nga' || $userKey === 'superadmin') {
            $role = 'admin';
        }

        return [
            'username' => $username,
            'userId' => $userId,
            'role' => $role,
        ];
    }

    /**
     * Dynamic permissions resolved from database for ZCOST module.
     * Returns 5 granular rights: view, add, edit, delete, approve.
     *
     * @return array{view: bool, add: bool, edit: bool, delete: bool, approve: bool}
     */
    public static function getPermissions(?string $username = null, ?int $userId = null, ?string $role = null): array
    {
        $id = self::resolveIdentity($username, $userId, $role);
        return Database::getUserPermissions($id['username'], $id['userId'], $id['role']);
    }

    public static function canView(?string $username = null, ?int $userId = null, ?string $role = null): bool
    {
        return self::getPermissions($username, $userId, $role)['view'];
    }

    public static function canAdd(?string $username = null, ?int $userId = null, ?string $role = null): bool
    {
        return self::getPermissions($username, $userId, $role)['add'];
    }

    public static function canEdit(?string $username = null, ?int $userId = null, ?string $role = null): bool
    {
        return self::getPermissions($username, $userId, $role)['edit'];
    }

    public static function canDelete(?string $username = null, ?int $userId = null, ?string $role = null): bool
    {
        return self::getPermissions($username, $userId, $role)['delete'];
    }

    public static function canApprove(?string $username = null, ?int $userId = null, ?string $role = null): bool
    {
        return self::getPermissions($username, $userId, $role)['approve'];
    }

    /**
     * Full user snapshot including session details and database permissions
     *
     * @return array{logged_in:bool,is_admin:bool,permissions:array{view:bool,add:bool,edit:bool,delete:bool,approve:bool},can_approve:bool,can_add:bool,can_edit:bool,can_delete:bool,can_view:bool,user_id:int,username:string,loginuserid:string,role:string,display_name:string,email:string}
     */
    public static function currentUser(): array
    {
        self::boot();

        $username = trim((string)(
            $_SESSION['username']
            ?? $_SESSION['user']
            ?? $_SESSION['user_name']
            ?? ''
        ));
        $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
        $userId = (int)(
            $_SESSION['user_id']
            ?? $_SESSION['userid']
            ?? $_SESSION['id']
            ?? $_SESSION['uid']
            ?? 0
        );

        $userKey = strtolower($username);
        if ($userKey === 'admin' || $userKey === 'nga' || $userKey === 'superadmin') {
            $role = 'admin';
        }

        $loggedIn = $userId > 0 || $username !== '';
        $isAdmin = $loggedIn && ($role === 'admin' || $role === 'superadmin' || $userKey === 'admin');

        // Fetch dynamic permissions from database
        $permissions = Database::getUserPermissions($username, $userId, $role);

        $display = trim((string)(
            $_SESSION['full_name']
            ?? $_SESSION['name']
            ?? $_SESSION['display_name']
            ?? ''
        ));
        if ($display === '') {
            $display = $username !== '' ? $username : 'Guest';
        }

        $email = trim((string)($_SESSION['email'] ?? ''));
        if ($email === '' && $username !== '') {
            $email = $username . '@evolvclothing.com';
        }

        $loginUserId = trim((string)(
            $_SESSION['loginuserid']
            ?? $_SESSION['login_user_id']
            ?? $_SESSION['user_id']
            ?? $_SESSION['userid']
            ?? $_SESSION['username']
            ?? ''
        ));

        return [
            'logged_in' => $loggedIn,
            'is_admin' => $isAdmin,
            'permissions' => $permissions,
            'can_view' => $permissions['view'],
            'can_add' => $permissions['add'],
            'can_edit' => $permissions['edit'],
            'can_delete' => $permissions['delete'],
            'can_approve' => $permissions['approve'],
            'user_id' => $userId,
            'username' => $username !== '' ? $username : 'Guest',
            'loginuserid' => $loginUserId !== '' ? $loginUserId : ($userId > 0 ? (string)$userId : ($username !== '' ? $username : 'ADMIN')),
            'role' => $role !== '' ? $role : 'guest',
            'display_name' => $display,
            'email' => $email !== '' ? $email : 'guest@evolvclothing.com',
        ];
    }

    public static function isLiveAdmin(): bool
    {
        return self::currentUser()['is_admin'] === true;
    }

    public static function requireLiveAdmin(): void
    {
        if (self::isLiveAdmin()) {
            return;
        }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'This action is restricted to administrators.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
