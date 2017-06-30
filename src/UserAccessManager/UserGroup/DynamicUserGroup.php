<?php
/**
 * DynamicUserGroup.php
 *
 * The DynamicUserGroup class file.
 *
 * PHP versions 5
 *
 * @author    Alexander Schneider <alexanderschneider85@gmail.com>
 * @copyright 2008-2017 Alexander Schneider
 * @license   http://www.gnu.org/licenses/gpl-2.0.html  GNU General Public License, version 2
 * @version   SVN: $id$
 * @link      http://wordpress.org/extend/plugins/user-access-manager/
 */
namespace UserAccessManager\UserGroup;

use UserAccessManager\Config\MainConfig;
use UserAccessManager\Database\Database;
use UserAccessManager\ObjectHandler\ObjectHandler;
use UserAccessManager\Util\Util;
use UserAccessManager\Wrapper\Php;
use UserAccessManager\Wrapper\Wordpress;

/**
 * Class DynamicUserGroup
 *
 * @package UserAccessManager\UserGroup
 */
class DynamicUserGroup extends AbstractUserGroup
{
    const USER_TYPE = 'user';
    const ROLE_TYPE = 'role';

    /**
     * DynamicUserGroup constructor.
     *
     * @param Php           $php
     * @param Wordpress     $wordpress
     * @param Database      $database
     * @param MainConfig    $config
     * @param Util          $util
     * @param ObjectHandler $objectHandler
     * @param string        $type
     * @param string        $id
     *
     * @throws UserGroupTypeException
     */
    public function __construct(
        Php $php,
        Wordpress $wordpress,
        Database $database,
        MainConfig $config,
        Util $util,
        ObjectHandler $objectHandler,
        $type,
        $id
    ) {
        $this->type = $type;
        parent::__construct($php, $wordpress, $database, $config, $util, $objectHandler, $id);

        if ($type !== self::USER_TYPE && $type !== self::ROLE_TYPE) {
            throw new UserGroupTypeException('Invalid dynamic group type.');
        }
    }

    /**
     * Returns the dynamic user group id.
     *
     * @return string
     */
    public function getId()
    {
        return $this->type.'|'.$this->id;
    }

    /**
     * Returns the dynamic group name.
     *
     * @return string
     */
    public function getName()
    {
        if ($this->name === null) {
            $this->name = '';

            if ($this->type === self::USER_TYPE && (int)$this->id === 0) {
                $this->name = TXT_UAM_ADD_DYNAMIC_NOT_LOGGED_IN_USERS;
            } elseif ($this->type === self::USER_TYPE) {
                $userData = $this->wordpress->getUserData($this->id);
                $this->name = TXT_UAM_USER.": {$userData->display_name} ($userData->user_login)";
            } elseif ($this->type === self::ROLE_TYPE) {
                $roles = $this->wordpress->getRoles()->roles;
                $this->name = TXT_UAM_ROLE.': ';
                $this->name .= (isset($roles[$this->id]['name']) === true) ? $roles[$this->id]['name'] : $this->id;
            }
        }

        return $this->name;
    }
}
