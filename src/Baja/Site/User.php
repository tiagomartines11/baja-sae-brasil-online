<?php
class User
{
    use DBObject;

    /* @var int */
    protected $_user_id;
    /* @var string */
    protected $_username;
    /* @var string */
    protected $_permissions;
    /* @var string */
    protected $_juizPerms;

    /**
     * @return int
     */
    public function getUserId()
    {
        return $this->_user_id;
    }

    /**
     * @param int $user_id
     */
    public function setUserId($user_id)
    {
        $this->_user_id = $user_id;
    }

    /**
     * @return string
     */
    public function getUsername()
    {
        return $this->_username;
    }

    /**
     * @param string $username
     */
    public function setUsername($username)
    {
        $this->_username = $username;
    }

    /**
     * @return string[]
     */
    public function getPermissions()
    {
        return explode(",",$this->_permissions);
    }

    /**
     * @return string[]
     */
    public function getJuizPerms()
    {
        return explode(",",$this->_juizPerms);
    }

    /**
     * @param string $permissions
     */
    public function setPermissions($permissions)
    {
        $this->_permissions = implode(',', $permissions);
    }

    /**
     * @param string $juizPerms
     */
    public function setJuizPerms($juizPerms)
    {
        $this->_juizPerms = implode(',', $juizPerms);
    }

    /**
     * @param string $id
     * @return null|User
     */
    public static function getUserById($id )
    {
        $user = null;
        $query = "SELECT * FROM users WHERE user_id = %d";
        $row = DB::queryFirstRow( $query, $id );
        if ( isset( $row ) )
        {
            $user = new User();
            $user->initFromAssocArray($row);
        }
        return $user;
    }

    /**
     * @param string $username
     * @return null|User
     */
    public static function getUserByUsername($username )
    {
        $user = null;
        $query = "SELECT * FROM users WHERE username = %s";
        $row = DB::queryFirstRow( $query, $username );
        if ( isset( $row ) )
        {
            $user = new User();
            $user->initFromAssocArray($row);
        }
        return $user;
    }

    /**
     * @return User[]
     */
    public static function getAll()
    {
        $ret = [];
        $query = "SELECT * FROM users ORDER BY username ASC";
        $rows = DB::query( $query );
        foreach( $rows as $row )
        {
            $item = new User();
            $item->initFromAssocArray($row);
            $ret[] = $item;
        }
        return $ret;
    }

    /**
     * @param User $user
     */
    static function insertUpdate($user) {
        DB::insertUpdate('users', $user->toDBArray());
    }
}