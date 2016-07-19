<?php

namespace Db;

interface DbInterface
{
    public function escape($string);
    public function getConnection();
    public function getError();
    public function query($query);
    public function getOne($query);
    public function getAll($query);
    public function getRow($query);
    public function getCol($query);
    public function getAssoc($query);
    public function getTransactionLevel();
    public function transaction($callback);
    public function beginTransaction();
    public function commit();
    public function rollback();
}
