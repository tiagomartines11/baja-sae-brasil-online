<?php

class Log
{
    use DBObject;

    private $_user;
    private $_pagina;
    private $_equipe;
    private $_dados;
    private $_data;

    /**
     * @return mixed
     */
    public function getUser()
    {
        return $this->_user;
    }

    /**
     * @return mixed
     */
    public function getPagina()
    {
        return $this->_pagina;
    }

    /**
     * @return mixed
     */
    public function getEquipe()
    {
        return $this->_equipe;
    }

    /**
     * @return mixed
     */
    public function getDados()
    {
        return $this->_dados;
    }

    /**
     * @return DateTime
     */
    public function getData()
    {
        return $this->_data;
    }


    /**
     * Log constructor.
     * @param $_user
     * @param $_pagina
     * @param $_equipe
     * @param $_dados
     */
    public function __construct($_user, $_pagina, $_equipe, $_dados)
    {
        $this->_user = $_user;
        $this->_pagina = $_pagina;
        $this->_equipe = $_equipe;
        $this->_dados = $_dados;
        $this->_data = new DateTime("now");
    }

    /**
     * @param Log $item
     */
    static function insert($item) {
        DB::insert('log', $item->toDBArray());
    }

    /**
     * @param $username
     * @return Log[]
     */
    public static function getAllForUser($username)
    {
        $ret = [];
        $query = "SELECT * FROM log WHERE user = %s ORDER BY data DESC";
        $rows = DB::query( $query, $username );
        foreach( $rows as $row )
        {
            $item = new self('','','','');
            $item->initFromAssocArray($row);
            $ret[] = $item;
        }
        return $ret;
    }

    /**
     * @param $page
     * @return Log[]
     */
    public static function getAllForPage($page)
    {
        $ret = [];
        $query = "SELECT * FROM log WHERE pagina = %s ORDER BY data DESC";
        $rows = DB::query( $query, $page );
        foreach( $rows as $row )
        {
            $item = new self('','','','');
            $item->initFromAssocArray($row);
            $ret[] = $item;
        }
        return $ret;
    }
}