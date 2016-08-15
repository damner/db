<?php

namespace Db;

use Psr\Log\LoggerInterface;
use Serializable;
use MySQLi;
use MySQLi_Result;
use Exception;
use InvalidArgumentException;
use Db\Exceptions\ConnectionException;
use Db\Exceptions\QueryException;

class Db implements DbInterface, CompilableQueryInterface, Serializable
{
    /**
     * Connection params.
     *
     * @var array
     */
    protected $data = array();

    /**
     * Mysqli connection instance.
     *
     * @var mysqli
     */
    protected $connection;

    /**
     * Query compiler instance.
     *
     * @var QueryCompilerInterface
     */
    protected $compiler;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Current transaction nesting level.
     *
     * @var LoggerInterface
     */
    protected $transactions = 0;

    /**
     * Constructor.
     *
     * @param array                  $data     Connection params.
     * @param QueryCompilerInterface $compiler Query compiler.
     * @param LoggerInterface        $logger   Logger.
     */
    public function __construct(array $data, QueryCompilerInterface $compiler, LoggerInterface $logger)
    {
        $this->setData($data);

        $this->setQueryCompiler($compiler);

        $this->logger = $logger;
    }

    /**
     * Sets connection params.
     *
     * @param array $data Connection params.
     */
    public function setData(array $data)
    {
        $this->data = $data + $this->data;
    }

    /**
     * Sets query compiler.
     *
     * @param QueryCompilerInterface $compiler Query compiler.
     */
    protected function setQueryCompiler(QueryCompilerInterface $compiler)
    {
        $this->compiler = $compiler;
        $this->compiler->setEscaperFunction(array($this, 'escape'));
    }

    /**
     * Initializes the connection.
     *
     * @throws ConnectionException if connection fails.
     */
    public function connect()
    {
        $host = $this->data['host'];

        if (!empty($this->data['persistent'])) {
            $host = 'p:'.$host;
        }

        $time = microtime(true);

        $this->connection = new MySQLi($host, $this->data['user'], $this->data['password'], $this->data['dbname'], $this->data['port']);

        $this->logger->info('Connection to mysql server', array(
            'connection' => true,
            'time' => microtime(true) - $time,
        ));

        if (isset($this->data['charset'])) {
            $time = microtime(true);

            $this->connection->set_charset($this->data['charset']);

            $this->logger->info(sprintf('Set connection charset to "%s".', $this->data['charset']), array(
                'time' => microtime(true) - $time,
                'errno' => $this->connection->errno ? $this->connection->errno : null,
                'error' => $this->connection->error ? $this->connection->error : null,
            ));

            if ($this->connection->error) {
                throw new ConnectionException($this->connection->error, $this->connection->errno);
            }
        }
    }

    /**
     * Returns logger.
     *
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /* Serializable interface */

    public function serialize()
    {
        return serialize(array(
            'data' => $this->data,
            'compiler' => $this->compiler,
            'logger' => $this->logger,
        ));
    }

    public function unserialize($data)
    {
        $data = unserialize($data);

        $this->data = $data['data'];
        $this->logger = $data['logger'];

        $this->setQueryCompiler($data['compiler']);
    }

    /* End of Serializable interface */

    /* DbCompilableQueryInterface interface */

    public function getQueryCompiler()
    {
        return $this->compiler;
    }

    public function getCompiledQuery($arguments)
    {
        if (is_string($arguments)) {
            $arguments = func_get_args();
        }

        if (!is_array($arguments)) {
            throw new InvalidArgumentException(sprintf('First function parameter must be an array or a string, "%s" given.', gettype($arguments)));
        }

        if (count($arguments) === 1) {
            return reset($arguments);
        }

        if (count($arguments) === 0) {
            throw new InvalidArgumentException('Bad count of items in array "$arguments".');
        }

        $query = array_shift($arguments);

        $query = $this->compiler->compile($query, $arguments);

        return $query;
    }

    /* End of DbCompilableQueryInterface interface */

    /* DbInterface interface */

    public function escape($string)
    {
        return $this->getConnection()->escape_string($string);
    }

    public function getConnection()
    {
        if ($this->connection === null) {
            $this->connect();
        }

        return $this->connection;
    }

    public function getError()
    {
        return $this->getConnection()->error;
    }

    public function query($query)
    {
        $query = $this->getCompiledQuery(func_get_args());

        $connection = $this->getConnection();

        $time = microtime(true);

        $result = $connection->query($query);

        $this->logger->info($query, array(
            'query' => true,
            'time' => microtime(true) - $time,
            'affected-rows' => $this->getAffectedRows(),
            'errno' => $connection->errno ? $connection->errno : null,
            'error' => $connection->error ? $connection->error : null,
        ));

        if ($connection->error) {
            throw new QueryException($connection->error, $connection->errno);
        }

        return $result;
    }

    public function getOne($query)
    {
        $query = $this->getCompiledQuery(func_get_args());

        $result = $this->query($query);
        $value = $result->fetch_row();

        return $value === null ? null : reset($value);
    }

    public function getAll($query)
    {
        $query = $this->getCompiledQuery(func_get_args());

        $result = $this->query($query);

        static $fetch_all_exists;
        if ($fetch_all_exists === null) {
            $fetch_all_exists = method_exists('mysqli_result', 'fetch_all');
        }

        if ($fetch_all_exists) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            $rows = array();
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function getRow($query)
    {
        $query = $this->getCompiledQuery(func_get_args());

        return $this->query($query)->fetch_assoc();
    }

    public function getCol($query)
    {
        $query = $this->getCompiledQuery(func_get_args());

        $result = $this->query($query);

        $column = array();
        while ($row = $result->fetch_row()) {
            $column[] = $row[0];
        }

        return $column;
    }

    public function getAssoc($query)
    {
        $query = $this->getCompiledQuery(func_get_args());

        $rows = $this->getAll($query);
        if (!count($rows)) {
            return array();
        }

        $keys = array_keys(reset($rows));

        return count($keys) === 2 ? array_by_key($keys[0], $rows, $keys[1]) : array_by_key($keys[0], $rows);
    }

    public function getTransactionLevel()
    {
        return $this->transactions;
    }

    public function transaction($callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Argument is not callable');
        }

        $this->beginTransaction();

        try {
            $result = $callback();

            $this->commit();
        } catch (Exception $e) {
            $this->rollBack();

            throw $e;
        }

        return $result;
    }

    public function beginTransaction()
    {
        ++$this->transactions;

        if ($this->transactions > 1) {
            return;
        }

        if (method_exists('mysqli', 'begin_transaction')) {
            $this->getConnection()->begin_transaction();
        } else {
            $this->query('START TRANSACTION');
        }
    }

    public function commit()
    {
        --$this->transactions;

        if ($this->transactions > 0) {
            return;
        }

        $this->getConnection()->commit();
    }

    public function rollback()
    {
        --$this->transactions;

        if ($this->transactions > 0) {
            return;
        }

        $this->getConnection()->rollback();
    }

    /* End of DbInterface interface */

    public function getInsertedId()
    {
        return $this->getConnection()->insert_id;
    }

    public function fetchRow(MySQLi_Result $result)
    {
        return $result->fetch_assoc();
    }

    public function getNumRows(MySQLi_Result $result)
    {
        return $result->num_rows;
    }

    public function getNumCols(MySQLi_Result $result)
    {
        return $result->field_count;
    }

    public function getAffectedRows()
    {
        return $this->getConnection()->affected_rows;
    }

    // Run query and return last inserted id.
    public function insert($query)
    {
        $query = $this->getCompiledQuery(func_get_args());

        $this->query($query);

        return $this->getInsertedId();
    }

    // Run query to insert multiple rows.
    public function insertRows($table, array $rows)
    {
        if (!count($rows)) {
            return;
        }

        $sql_parts = array();
        foreach ($rows as $values) {
            $sql_parts[] = count($values) ? $this->getCompiledQuery('(?@)', $values) : '()';
        }

        return $this->query('INSERT INTO ?F (?@F) VALUES ?N', $table, array_keys(reset($rows)), implode(',', $sql_parts));
    }

    // Run query to replace multiple rows.
    public function replaceRows($table, array $rows)
    {
        if (!count($rows)) {
            return;
        }

        $sql_parts = array();
        foreach ($rows as $values) {
            $sql_parts[] = count($values) ? $this->getCompiledQuery('(?@)', $values) : '()';
        }

        return $this->query('REPLACE INTO ?F (?@F) VALUES ?N', $table, array_keys(reset($rows)), implode(',', $sql_parts));
    }

    // Run query to create database table.
    public function createTable($table, array $fields, $engine = null, $comment = null)
    {
        $primary = array();
        $indexes = array();

        $queries = array();
        foreach ($fields as $name => $field) {
            $queries[] = $this->getFieldDeclaration($field['type'], $name, $field);
            if (isset($field['primary'])) {
                $primary[$name] = $name;
            }
            if (isset($field['index'])) {
                $indexes[$name] = $name;
            }
        }

        foreach ($primary as $name) {
            $queries[] = $this->getCompiledQuery('PRIMARY KEY (?F)', $name);
        }

        foreach ($indexes as $name) {
            $queries[] = $this->getCompiledQuery('KEY ?F (?F)', $name, $name);
        }

        if (!empty($engine)) {
            $engine = ' ENGINE = '.mb_strtoupper($engine);
        }

        if (!empty($comment)) {
            $comment = $this->getCompiledQuery(' COMMENT = ?', $comment);
        }

        $charset = ' CHARACTER SET = utf8 COLLATE = utf8_general_ci';

        $query = implode(', ', $queries);

        $this->query($this->getCompiledQuery('CREATE TABLE ?F', $table).' ('.$query.')'.$engine.$comment.$charset);
    }

    // Run query to alter database table.
    public function alterTable($table, array $changes)
    {
        $queries = array();

        // Add fields
        if (isset($changes['add'])) {
            foreach ($changes['add'] as $name => $field) {
                $queries[] = ' ADD '.$this->getFieldDeclaration($field['type'], $name, $field);

                // Add new foreign key
                if (isset($field['add_foreign_key'])) {
                    $sql_ondelete = $field['add_foreign_key']['ondelete'] !== null ? ' ON DELETE '.$field['add_foreign_key']['ondelete'] : '';
                    $sql_onupdate = $field['add_foreign_key']['onupdate'] !== null ? ' ON UPDATE '.$field['add_foreign_key']['onupdate'] : '';

                    $queries[] = $this->getCompiledQuery(' ADD FOREIGN KEY (?F) REFERENCES ?F (?F)', $name, $field['add_foreign_key']['table'], $field['add_foreign_key']['field']).$sql_ondelete.$sql_onupdate;
                }
            }
        }

        // Remove fields
        if (isset($changes['drop'])) {
            foreach ($changes['drop'] as $name => $field) {
                $queries[] = $this->getCompiledQuery(' DROP ?F', $name);

                // Remove old foreign keys
                foreach ($this->getForeignKeys($table) as $key) {
                    if ($key['field'] === $name) {
                        $queries[] = $this->getCompiledQuery(' DROP FOREIGN KEY ?F', $key['name']);
                    }
                }
            }
        }

        // Change fields
        if (isset($changes['change'])) {
            foreach ($changes['change'] as $name => $field) {
                if (empty($field['name'])) {
                    $field['name'] = $name;
                }
                $queries[] = $this->getCompiledQuery(' CHANGE ?F ', $name).$this->getFieldDeclaration($field['type'], $field['name'], $field);

                // Remove old foreign keys
                if (!empty($field['drop_foreign_keys']) || isset($field['add_foreign_key'])) {
                    foreach ($this->getForeignKeys($table) as $key) {
                        if ($key['field'] === $name) {
                            $queries[] = $this->getCompiledQuery(' DROP FOREIGN KEY ?F', $key['name']);
                        }
                    }
                }
                // Add new foreign key
                if (isset($field['add_foreign_key'])) {
                    $sql_ondelete = $field['add_foreign_key']['ondelete'] !== null ? ' ON DELETE '.$field['add_foreign_key']['ondelete'] : '';
                    $sql_onupdate = $field['add_foreign_key']['onupdate'] !== null ? ' ON UPDATE '.$field['add_foreign_key']['onupdate'] : '';

                    $queries[] = $this->getCompiledQuery(' ADD FOREIGN KEY (?F) REFERENCES ?F (?F)', $field['name'], $field['add_foreign_key']['table'], $field['add_foreign_key']['field']).$sql_ondelete.$sql_onupdate;
                }
            }
        }

        $rename = isset($changes['name']) ? $this->getCompiledQuery(' RENAME ?F', $changes['name']) : '';
        $comment = isset($changes['comment']) ? $this->getCompiledQuery(' COMMENT = ?', $changes['comment']) : '';
        $engine = isset($changes['engine']) ? ' ENGINE = '.mb_strtoupper($changes['engine']) : '';

        return $this->query($this->getCompiledQuery('ALTER TABLE ?F', $table).$rename.implode(',', $queries).$engine.$comment);
    }

    // Return sql field declaration.
    protected function getFieldDeclaration($type, $name, array $field)
    {
        if (isset($field['length'])) {
            $length = ' ('.(int) $field['length'].')';
        }
        if (!empty($field['unsigned'])) {
            $unsigned = ' UNSIGNED';
        }
        if (!empty($field['zerofill'])) {
            $zerofill = ' ZEROFILL';
        }
        if (!empty($field['binary'])) {
            $binary = ' BINARY';
        }
        if (!empty($field['notnull'])) {
            $notnull = ' NOT NULL';
        }
        if (isset($field['auto_increment'])) {
            $auto_increment = ' AUTO_INCREMENT';
        }
        if (isset($field['comment'])) {
            $comment = $this->getCompiledQuery(' COMMENT ?', $field['comment']);
        }
        if (isset($field['default'])) {
            $default = $this->getCompiledQuery(' DEFAULT ?', $field['default']);
        }

        return $this->getCompiledQuery('?F ', $name).mb_strtoupper($type).@$length.@$default.@$unsigned.@$zerofill.@$binary.@$notnull.@$auto_increment.@$comment;
    }

    // Run query to drop table.
    public function dropTable($table)
    {
        $this->query('DROP TABLE ?F', $table);
    }

    // Run query to copy table.
    public function copyTable($old_table, $new_table)
    {
        $this->query('CREATE TABLE ?F LIKE ?F', $new_table, $old_table);
    }

    // Returns foreign keys of table.
    public function getForeignKeys($table)
    {
        $row = $this->getRow('SHOW CREATE TABLE ?F', $table);
        $sql = $row['Create Table'];

        preg_match_all('/CONSTRAINT `(.*?)` FOREIGN KEY \(`(.*?)`\) REFERENCES `(.*?)` \(`(.*?)`\)(.*)/u', $sql, $matches, PREG_SET_ORDER);
        $keys = array();
        foreach ($matches as $match) {
            preg_match_all('/ON (DELETE|UPDATE) (CASCADE|SET NULL|NO ACTION|RESTRICT)/u', $match[5], $actions, PREG_SET_ORDER);
            $ondelete = null;
            $onupdate = null;
            foreach ($actions as $action) {
                if ($action[1] === 'DELETE') {
                    $ondelete = $action[2];
                }
                if ($action[1] === 'UPDATE') {
                    $onupdate = $action[2];
                }
            }

            $keys[] = array(
                'name' => $match[1],
                'field' => $match[2],
                'table' => $match[3],
                'target_field' => $match[4],
                'ondelete' => $ondelete,
                'onupdate' => $onupdate,
            );
        }

        return $keys;
    }

    // Run query to create new foreign key.
    public function addForeignKey($table, $field, $foreign_table, $foreign_field, $name, $ondelete, $onupdate)
    {
        $sql_name = $name !== null ? $this->getCompiledQuery(' CONSTRAINT ?F', $name) : '';
        $sql_ondelete = $ondelete !== null ? 'ON DELETE '.$ondelete : '';
        $sql_onupdate = $onupdate !== null ? 'ON UPDATE '.$onupdate : '';

        return $this->query('ALTER TABLE ?F ADD?N FOREIGN KEY (?F) REFERENCES ?F (?F) ?N ?N', $table, $sql_name, $field, $foreign_table, $foreign_field, $sql_ondelete, $sql_onupdate);
    }

    // Run query to remove foreign key.
    public function dropForeignKey($table, $key)
    {
        return $this->query('ALTER TABLE ?F DROP FOREIGN KEY ?F', $table, $key);
    }

    // Return search subquery.
    public function getSearchSql($string, $field_name, $type = 'OR', $min_word_length = null)
    {
        $string = preg_replace('/[^0-9a-zа-яёЁ_\\-:#@$%*()\\[\\]{}?<>\\s]/iu', '', $string);

        if ($min_word_length > 1) {
            $string = preg_replace('/(^|\s)\S{1,'.($min_word_length - 1).'}(\s|$)/u', ' ', $string);
        }

        $string = addcslashes($string, '_%');

        $string = preg_replace('/\\s+/u', ' ', $string);
        $string = trim($string);

        if ($string === '') {
            return false;
        }

        if ($type) {
            $string = str_replace(' ', '%" '.$type.' '.$field_name.' LIKE "%', $string);
        }

        return '('.$field_name.' LIKE "%'.$string.'%")';
    }
}
