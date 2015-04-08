<?php

namespace MyBB\Core\Permissions;

use Illuminate\Database\DatabaseManager;
use MyBB\Core\Database\Models\ContentClass;
use MyBB\Core\Database\Models\Role;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use MyBB\Core\Database\Models\User;
use MyBB\Core\Permissions\Interfaces\InheritPermissionInterface;
use MyBB\Core\Permissions\Interfaces\PermissionInterface;

class PermissionChecker
{
	const NEVER = -1;
	const NO = 0;
	const YES = 1;

	/** @var CacheRepository $cache */
	private $cache;

	/** @var DatabaseManager $db */
	private $db;

	/** @var ContentClass $classModel */
	private $classModel;

    /** @var array $permissions */
    private $permissions;

    /** @var array $unviewableIds */
    private $unviewableIds;

	/**
	 * @param CacheRepository $cache
	 * @param DatabaseManager $db
	 * @param ContentClass    $classModel
	 */
	public function __construct(CacheRepository $cache, DatabaseManager $db, ContentClass $classModel)
	{
		$this->cache = $cache;
		$this->db = $db;
		$this->classModel = $classModel;
	}

	/**
	 * Get an array of unviewable ids for the registered content type
	 *
	 * @param string $content
     * @param User $user
	 *
	 * @return array
	 */
	public function getUnviewableIdsForContent($content, User $user = null)
	{
		$concreteClass = $this->classModel->getClass($content);

        if ($concreteClass == null) {
            throw new \RuntimeException("No class is registered for content type '{$content}'");
        }

        if(!($concreteClass instanceof PermissionInterface)) {
            throw new \RuntimeException("The registered class for '{$content}' needs to implement PermissionInterface");
        }

        if ($this->unviewableIds[$content] != null) {
            return $this->unviewableIds[$content];
        }

        $models = $concreteClass::all();

        $unviewable = [];
        foreach ($models as $model) {
            if (!$this->hasPermission($content, $model->getContentId(), $concreteClass::getViewablePermission(), $user)) {
                $unviewable[] = $model->getContentId();
            }
        }

        $this->unviewableIds[$content] = $unviewable;

        return $unviewable;
	}


    /**
     * Checks whether the specified user has the specified permission
     *
     * @param string $content
     * @param int $contentID
     * @param array|string $permission
     * @param User         $user
     *
     * @return bool
     */
    public function hasPermission($content, $contentID, $permission, User $user = null)
    {
        $concreteClass = $this->classModel->getClass($content);

        if ($concreteClass == null) {
            throw new \RuntimeException("No class is registered for content type '{$content}'");
        }

        if(!($concreteClass instanceof PermissionInterface)) {
            throw new \RuntimeException("The registered class for '{$content}' needs to implement PermissionInterface");
        }

        if ($user == null) {
            $user = app('auth.driver')->user();
        }

        // Handle the array case
        if (is_array($permission)) {
            foreach ($permission as $perm) {
                $hasPermission = $this->hasPermission($content, $contentID, $perm, $user);

                // No need to check more permissions
                if (!$hasPermission) {
                    return false;
                }
            }

            return true;
        }

        // We already calculated the permissions for this user, no need to recheck all roles
        if (isset($this->permissions[$content][$contentID][$user->getKey()][$permission])) {
            return $this->permissions[$content][$contentID][$user->getKey()][$permission];
        }

        // Handle special cases where no role has been set
        $roles = $user->roles;
        if ($roles->count() == 0) {
            if ($user->exists) {
                // User saved? Something is wrong, attach the registered role
                $registeredRole = Role::where('role_slug', '=', 'user')->first();
                $user->roles()->attach($registeredRole->id, ['is_display' => 1]);
                $roles = [$registeredRole];
            } else {
                // Guest
                $guestRole = Role::where('role_slug', '=', 'guest')->first();
                $roles = [$guestRole];
            }
        }

        // Assume "No" by default
        $isAllowed = false;
        foreach ($roles as $role) {
            $hasPermission = $this->getPermissionForRole($role, $permission, $content, $contentID);

            // If we never want to grant the permission we can skip all other roles. But don't forget to cache it
            if ($hasPermission == PermissionChecker::NEVER) {
                $isAllowed = false;
                break;
            } // Override our "No" assumption - but don't return yet, we may have a "Never" permission later
            elseif ($hasPermission == PermissionChecker::YES) {
                $isAllowed = true;
            }
        }

        // No parent? No need to do anything else here
        if (($concreteClass instanceof InheritPermissionInterface) && $concreteClass::find($contentID)->getParent() != null) {
            // If we have a positive permission but need to check parents for negative values do so here
            if ($isAllowed && in_array($permission, $concreteClass::getNegativeParentOverrides())) {
                $isAllowed = $this->hasPermission($content, $concreteClass::find($contentID)->getParent()->getContentId(), $permission, $user);
            }

            // Do the same for negative permissions with parent positives
            if (!$isAllowed && in_array($permission, $concreteClass::getPositiveParentOverrides())) {
                $isAllowed = $this->hasPermission($content, $concreteClass::find($contentID)->getParent()->getContentId(), $permission, $user);
            }
        }

        // Don't forget to cache the permission for this call
        $this->permissions[$content][$contentID][$user->getKey()][$permission] = $isAllowed;

        return $isAllowed;
    }

	/**
	 * Check whether a specific Role has the specified permission
	 *
	 * @param Role        $role       The role to check
	 * @param string      $permission The permission to check
	 * @param string|null $content    If the permission is related to some content (eg forum) this string specifies the
	 *                                type of text
	 * @param int|null    $contentID  If $content is set this specifies the ID of the content to check
	 *
	 * @return PermissionChecker::NEVER|NO|YES
	 */
	public function getPermissionForRole(Role $role, $permission, $content = null, $contentID = null)
	{
		// Permissions associated with user/groups are saved without content (all permissions are associated with groups anyways)
		if ($content == 'user' || $content == 'usergroup') {
			$content = null;
            $contentID = null;
		}

		//if ($this->hasCache($role, $permission, $content, $contentID)) {
		//	return $this->getCache($role, $permission, $content, $contentID);
		//}

		// Get the value if we have one otherwise the devault value
		$permission = $this->db->table('permissions')
			->where('permission_name', '=', $permission)
			->where('content_name', '=', $content)
			->leftJoin('permission_role', function ($join) use ($role, $content, $contentID) {
				$join->on('permission_id', '=', 'id')
					->where('role_id', '=', $role->id);

				if ($content != null && $contentID != null) {
					$join->where('content_id', '=', $contentID);
				}
			})
			->first(['value', 'default_value']);

		if ($permission->value !== null) {
			//$this->putCache($role, $permission, $content, $contentID, $permission->value);

			return $permission->value;
		}

		//$this->putCache($role, $permission, $content, $contentID, $permission->default_value);

		return $permission->default_value;
	}

	/**
	 * @param Role        $role
	 * @param string      $permission
	 * @param string|null $content
	 * @param int|null    $contentID
	 *
	 * @return bool
	 */
	private function hasCache(Role $role, $permission, $content, $contentID)
	{
		return $this->getCache($role, $permission, $content, $contentID) != null;
	}

	/**
	 * @param Role        $role
	 * @param string      $permission
	 * @param string|null $content
	 * @param int|null    $contentID
	 *
	 * @return mixed
	 */
	private function getCache(Role $role, $permission, $content, $contentID)
	{
		return $this->cache->get("permission.{$role->slug}.{$permission}.{$content}.{$contentID}");
	}

	/**
	 * @param Role         $role
	 * @param string       $permission
	 * @param string|null  $content
	 * @param int|null     $contentID
	 * @param NEVER|NO|YES $value
	 */
	private function putCache(Role $role, $permission, $content, $contentID, $value)
	{
		$this->cache->forever("permission.{$role->slug}.{$permission}.{$content}.{$contentID}", $value);
	}
}
