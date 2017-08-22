<?php
/**
 * AccessHandler.php
 *
 * The AccessHandler class file.
 *
 * PHP versions 5
 *
 * @author    Alexander Schneider <alexanderschneider85@gmail.com>
 * @copyright 2008-2017 Alexander Schneider
 * @license   http://www.gnu.org/licenses/gpl-2.0.html  GNU General Public License, version 2
 * @version   SVN: $id$
 * @link      http://wordpress.org/extend/plugins/user-access-manager/
 */
namespace UserAccessManager\AccessHandler;

use UserAccessManager\Config\MainConfig;
use UserAccessManager\Database\Database;
use UserAccessManager\ObjectHandler\ObjectHandler;
use UserAccessManager\UserGroup\AbstractUserGroup;
use UserAccessManager\UserGroup\DynamicUserGroup;
use UserAccessManager\UserGroup\UserGroup;
use UserAccessManager\UserGroup\UserGroupFactory;
use UserAccessManager\UserHandler\UserHandler;
use UserAccessManager\Wrapper\Wordpress;

/**
 * Class AccessHandler
 *
 * @package UserAccessManager\AccessHandler
 */
class AccessHandler
{
    /**
     * @var Wordpress
     */
    private $wordpress;

    /**
     * @var MainConfig
     */
    private $config;

    /**
     * @var Database
     */
    private $database;

    /**
     * @var ObjectHandler
     */
    private $objectHandler;

    /**
     * @var UserHandler
     */
    private $userHandler;

    /**
     * @var UserGroupFactory
     */
    private $userGroupFactory;

    /**
     * @var null|UserGroup[]
     */
    private $userGroups = null;

    /**
     * @var null|DynamicUserGroup[]
     */
    private $dynamicUserGroups = null;

    /**
     * @var null|UserGroup[]
     */
    private $filteredUserGroups = null;

    /**
     * @var null|AbstractUserGroup[]
     */
    private $userGroupsForUser = null;

    /**
     * @var null|array
     */
    private $excludedTerms = null;

    /**
     * @var null|array
     */
    private $excludedPosts = null;

    /**
     * @var array
     */
    private $objectUserGroups = [];

    /**
     * @var array
     */
    private $objectAccess = [];

    /**
     * The constructor
     *
     * @param Wordpress        $wordpress
     * @param MainConfig       $config
     * @param Database         $database
     * @param ObjectHandler    $objectHandler
     * @param UserHandler      $userHandler
     * @param UserGroupFactory $userGroupFactory
     */
    public function __construct(
        Wordpress $wordpress,
        MainConfig $config,
        Database $database,
        ObjectHandler $objectHandler,
        UserHandler $userHandler,
        UserGroupFactory $userGroupFactory
    ) {
        $this->wordpress = $wordpress;
        $this->config = $config;
        $this->database = $database;
        $this->objectHandler = $objectHandler;
        $this->userHandler = $userHandler;
        $this->userGroupFactory = $userGroupFactory;
    }

    /**
     * Returns all user groups.
     *
     * @return UserGroup[]
     */
    public function getUserGroups()
    {
        if ($this->userGroups === null) {
            $this->userGroups = [];

            $query = "SELECT ID FROM {$this->database->getUserGroupTable()}";
            $userGroups = (array)$this->database->getResults($query);

            foreach ($userGroups as $userGroup) {
                $group = $this->userGroupFactory->createUserGroup($userGroup->ID);
                $this->userGroups[$group->getId()] = $group;
            }
        }

        return $this->userGroups;
    }

    /**
     * Returns all dynamic user groups.
     *
     * @return null|DynamicUserGroup[]
     */
    public function getDynamicUserGroups()
    {
        if ($this->dynamicUserGroups === null) {
            $this->dynamicUserGroups = [];

            $notLoggedInUserGroup = $this->userGroupFactory->createDynamicUserGroup(
                DynamicUserGroup::USER_TYPE,
                DynamicUserGroup::NOT_LOGGED_IN_USER_ID
            );
            $this->dynamicUserGroups[$notLoggedInUserGroup->getId()] = $notLoggedInUserGroup;

            $userGroupTypes = implode('\', \'', [DynamicUserGroup::ROLE_TYPE, DynamicUserGroup::USER_TYPE]);

            $query = "SELECT group_id AS id, group_type AS type
                FROM {$this->database->getUserGroupToObjectTable()}
                WHERE group_type IN ('{$userGroupTypes}')
                  GROUP BY group_type, group_id";

            $dynamicUserGroups = (array)$this->database->getResults($query);

            foreach ($dynamicUserGroups as $dynamicUserGroup) {
                $group = $this->userGroupFactory->createDynamicUserGroup(
                    $dynamicUserGroup->type,
                    $dynamicUserGroup->id
                );

                $this->dynamicUserGroups[$group->getId()] = $group;
            }
        }

        return $this->dynamicUserGroups;
    }

    /**
     * Returns the full user groups
     *
     * @return AbstractUserGroup[]
     */
    public function getFullUserGroups()
    {
        return $this->getUserGroups() + $this->getDynamicUserGroups();
    }

    /**
     * Returns the user groups filtered by the user user groups.
     *
     * @return AbstractUserGroup[]
     */
    public function getFilteredUserGroups()
    {
        $userGroups = $this->getFullUserGroups();
        $userUserGroups = $this->getUserGroupsForUser() + $this->getDynamicUserGroups();
        return array_intersect_key($userGroups, $userUserGroups);
    }

    /**
     * Adds a user group.
     *
     * @param UserGroup $userGroup The user group which we want to add.
     */
    public function addUserGroup(UserGroup $userGroup)
    {
        $this->getUserGroups();
        $this->userGroups[$userGroup->getId()] = $userGroup;
        $this->filteredUserGroups = null;
    }

    /**
     * Deletes a user group.
     *
     * @param integer $userGroupId The user group _iId which we want to delete.
     *
     * @return bool
     */
    public function deleteUserGroup($userGroupId)
    {
        $userGroups = $this->getUserGroups();

        if (isset($userGroups[$userGroupId])
            && $userGroups[$userGroupId]->delete() === true
        ) {
            unset($this->userGroups[$userGroupId]);
            $this->filteredUserGroups = null;

            return true;
        }

        return false;
    }

    /**
     * Returns the user groups for the given object.
     *
     * @param string  $objectType  The object type.
     * @param integer $objectId    The id of the object.
     * @param bool    $ignoreDates If true we ignore the dates for the object assignment.
     *
     * @return AbstractUserGroup[]
     */
    public function getUserGroupsForObject($objectType, $objectId, $ignoreDates = false)
    {
        if ($this->objectHandler->isValidObjectType($objectType) === false) {
            return [];
        } elseif (isset($this->objectUserGroups[$objectType]) === false) {
            $this->objectUserGroups[$objectType] = [];
        }

        if ($ignoreDates === true || isset($this->objectUserGroups[$objectType][$objectId]) === false) {
            $objectUserGroups = [];
            $userGroups = $this->getFullUserGroups();

            foreach ($userGroups as $userGroup) {
                $userGroup->setIgnoreDates($ignoreDates);

                if ($userGroup->isObjectMember($objectType, $objectId) === true) {
                    $objectUserGroups[$userGroup->getId()] = $userGroup;
                }
            }

            if ($ignoreDates === true) {
                return $objectUserGroups;
            }

            $this->objectUserGroups[$objectType][$objectId] = $objectUserGroups;
        }

        return $this->objectUserGroups[$objectType][$objectId];
    }

    /**
     * Unset the object user groups.
     */
    public function unsetUserGroupsForObject()
    {
        $this->objectUserGroups = [];
    }

    /**
     * Returns the user groups for the user.
     *
     * @return AbstractUserGroup[]
     */
    public function getUserGroupsForUser()
    {
        if ($this->userHandler->checkUserAccess(UserHandler::MANAGE_USER_GROUPS_CAPABILITY) === true) {
            return $this->getUserGroups();
        }

        if ($this->userGroupsForUser === null) {
            $currentUser = $this->wordpress->getCurrentUser();
            $userGroupsForUser = $this->getUserGroupsForObject(
                ObjectHandler::GENERAL_USER_OBJECT_TYPE,
                $currentUser->ID
            );

            $userUserGroup = $this->userGroupFactory->createDynamicUserGroup(
                DynamicUserGroup::USER_TYPE,
                $currentUser->ID
            );
            $userGroupsForUser[$userUserGroup->getId()] = $userUserGroup;

            $roles = $this->userHandler->getUserRole($currentUser);

            foreach ($roles as $role) {
                $group = $this->userGroupFactory->createDynamicUserGroup(
                    DynamicUserGroup::ROLE_TYPE,
                    $role
                );

                $userGroupsForUser[$group->getId()] = $group;
            }

            $userGroups = $this->getUserGroups();

            foreach ($userGroups as $userGroup) {
                if (isset($userGroupsForUser[$userGroup->getId()]) === false
                    && ($this->userHandler->isIpInRange($_SERVER['REMOTE_ADDR'], $userGroup->getIpRangeArray())
                        || $this->config->atAdminPanel() === false && $userGroup->getReadAccess() === 'all'
                        || $this->config->atAdminPanel() === true && $userGroup->getWriteAccess() === 'all')
                ) {
                    $userGroupsForUser[$userGroup->getId()] = $userGroup;
                }
            }

            $this->userGroupsForUser = $userGroupsForUser;
        }

        return $this->userGroupsForUser;
    }

    /**
     * Returns the user groups for the object filtered by the user user groups.
     *
     * @param string $objectType
     * @param int    $objectId
     * @param bool   $ignoreDates
     *
     * @return AbstractUserGroup[]
     */
    public function getFilteredUserGroupsForObject($objectType, $objectId, $ignoreDates = false)
    {
        $userGroups = $this->getUserGroupsForObject($objectType, $objectId, $ignoreDates);
        $userUserGroups = $this->getUserGroupsForUser() + $this->getDynamicUserGroups();
        return array_intersect_key($userGroups, $userUserGroups);
    }

    /**
     * Checks if the current_user has access to the given post.
     *
     * @param string  $objectType The object type which should be checked.
     * @param integer $objectId   The id of the object.
     *
     * @return bool
     */
    public function checkObjectAccess($objectType, $objectId)
    {
        if (isset($this->objectAccess[$objectType]) === false) {
            $this->objectAccess[$objectType] = [];
        }

        if (isset($this->objectAccess[$objectType][$objectId]) === false) {
            $access = false;
            $currentUser = $this->wordpress->getCurrentUser();

            if ($this->objectHandler->isValidObjectType($objectType) === false) {
                $access = true;
            } else {
                if ($this->userHandler->checkUserAccess(UserHandler::MANAGE_USER_GROUPS_CAPABILITY) === true) {
                    $access = true;
                } elseif ($this->config->authorsHasAccessToOwn() === true
                    && $this->objectHandler->isPostType($objectType)
                ) {
                    $post = $this->objectHandler->getPost($objectId);
                    $access = ($post !== false && $currentUser->ID === (int)$post->post_author);
                }

                if ($access === false) {
                    $membership = $this->getUserGroupsForObject($objectType, $objectId);

                    if (count($membership) > 0) {
                        $userUserGroups = $this->getUserGroupsForUser();

                        foreach ($membership as $userGroupId => $userGroup) {
                            if (isset($userUserGroups[$userGroupId]) === true) {
                                $access = true;
                                break;
                            }
                        }
                    } else {
                        $access = true;
                    }
                }
            }

            $this->objectAccess[$objectType][$objectId] = $access;
        }

        return $this->objectAccess[$objectType][$objectId];
    }

    /**
     * Returns the excluded objects.
     *
     * @param string $type
     * @param array  $filterTypesMap
     *
     * @return array
     */
    private function getExcludedObjects($type, array $filterTypesMap = [])
    {
        $excludedObjects = [];
        $userGroups = $this->getUserGroups();

        foreach ($userGroups as $userGroup) {
            $excludedObjects += $userGroup->getAssignedObjectsByType($type);
        }

        $userUserGroups = $this->getUserGroupsForUser();

        foreach ($userUserGroups as $userGroup) {
            $excludedObjects = array_diff_key($excludedObjects, $userGroup->getAssignedObjectsByType($type));
        }

        if ($filterTypesMap !== []) {
            $excludedObjects = array_filter(
                $excludedObjects,
                function ($element) use ($filterTypesMap) {
                    return isset($filterTypesMap[$element]) === false;
                }
            );
        }

        $objectIds = array_keys($excludedObjects);
        return array_combine($objectIds, $objectIds);
    }

    /**
     * Returns the excluded terms for a user.
     *
     * @return array
     */
    public function getExcludedTerms()
    {
        if ($this->userHandler->checkUserAccess(UserHandler::MANAGE_USER_GROUPS_CAPABILITY)) {
            $this->excludedTerms = [];
        }

        if ($this->excludedTerms === null) {
            $this->excludedTerms = $this->getExcludedObjects(ObjectHandler::GENERAL_TERM_OBJECT_TYPE);
        }

        return $this->excludedTerms;
    }

    /**
     * Returns the excluded posts.
     *
     * @return array
     */
    public function getExcludedPosts()
    {
        if ($this->userHandler->checkUserAccess(UserHandler::MANAGE_USER_GROUPS_CAPABILITY)) {
            $this->excludedPosts = [];
        }

        if ($this->excludedPosts === null) {
            $noneHiddenPostTypes = [];

            if ($this->wordpress->isAdmin() === false) {
                $postTypes = $this->objectHandler->getPostTypes();

                foreach ($postTypes as $postType) {
                    if ($this->config->hidePostType($postType) === false) {
                        $noneHiddenPostTypes[$postType] = $postType;
                    }
                }
            }

            $excludedPosts = $this->getExcludedObjects(ObjectHandler::GENERAL_POST_OBJECT_TYPE, $noneHiddenPostTypes);

            if ($this->config->authorsHasAccessToOwn() === true) {
                $query = $this->database->prepare(
                    "SELECT ID
                    FROM {$this->database->getPostsTable()}
                    WHERE post_author = %d",
                    $this->wordpress->getCurrentUser()->ID
                );

                $ownPosts = (array)$this->database->getResults($query);
                $ownPostIds = [];

                foreach ($ownPosts as $ownPost) {
                    $ownPostIds[$ownPost->ID] = $ownPost->ID;
                }

                $excludedPosts = array_diff_key($excludedPosts, $ownPostIds);
            }

            $this->excludedPosts = $excludedPosts;
        }

        return $this->excludedPosts;
    }
}