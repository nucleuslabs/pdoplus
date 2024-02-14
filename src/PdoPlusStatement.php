<?php namespace PdoPlus;

use PDO;
use PDOException;
use PDOStatement;

class PdoPlusStatement extends PDOStatement {
    protected $rowNumber = 0;
    
    protected function __construct() {
    }


    /**
     * @param array|mixed $input_parameters An array of values with as many elements as there are bound parameters in the SQL statement being executed, or one or more non-array arguments to be matched with sequential parameter markers.
     * @throws PDOException
     * @return PdoPlusStatement
     */
    #[\ReturnTypeWillChange]
    public function execute($input_parameters = null) {
        $args = func_get_args();
        $argc = func_num_args();
        try {
            if($argc === 0) {
                parent::execute();
            } else {
                if($argc === 1 && is_array($args[0])) {
                    $args = $args[0];
                }
                parent::execute($args);
            }
        } catch(PDOException $e) {
            PdoPlus::rethrow(null, $e, $this->queryString);
        }
        return $this;
    }

    /**
     * Returns an array containing all of the remaining rows in the result set
     *
     * @return array An associative array using the first column as the key and the remainder as associative values
     * @deprecated Use ->fetchAll(PDO::FETCH_UNIQUE)
     */
    public function fetchKeyAssoc() {
        return $this->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
    }

    /**
     *
     * Method idea stolen from here but made it better:
     * http://stackoverflow.com/questions/210564/getting-raw-sql-query-string-from-pdo-prepared-statements
     *
     * @param $params
     * @return mixed
     */
    public function debugQuery($params) {
        $keys = [];
        foreach ($params as $key => $value) {
            if (is_string($key)) {
                $keys[] = '/:'.$key.'/';
            } else {
                $keys[] = '/[?]/';
            }
        }
        return preg_replace($keys, $params, $this->queryString, 1, $count);
    }

    /**
     * Fetches the next row from a result set
     *
     * @param int $fetch_style
     * @param int $cursor_orientation
     * @param int $offset
     * @return array|false|object
     * @throws \Exception
     * @see http://php.net/manual/en/pdostatement.fetch.php
     */
    
    #[\ReturnTypeWillChange]
    public function fetch($fetch_style = NULL, $cursor_orientation = NULL, $offset = NULL) {
        if($cursor_orientation === PDO::FETCH_ORI_ABS && $offset !== null && $offset !== $this->rowNumber) {
            // https://bugs.php.net/bug.php?id=63466
            if($this->rowNumber > $offset) {
                throw new \Exception("Cannot move cursor backwards (from $this->rowNumber to $offset)");
            }
            while($this->rowNumber++ < $offset) {
                parent::fetch(PDO::FETCH_NUM);
            }
        } else {
            ++$this->rowNumber;
        }
        if($fetch_style === PDO::FETCH_BOTH) {
            $args = array_slice(func_get_args(),1);
            $row = parent::fetch(PDO::FETCH_NAMED, ...$args);
            if($row === false) {
                return $row;
            }
            return self::named2both($row);
        }
        return parent::fetch(...func_get_args());
    }

    private static function named2both($row) {
        $i = 0;
        $result = [];
        foreach($row as $k => $v) {
            if(is_array($v)) {
                foreach($v as $v2) {
                    $result[$i++] = $v2;
                }
                /** @noinspection PhpUndefinedVariableInspection */
                $result[$k] = $v2;
            } else {
                $result[$i++] = $v;
                $result[$k] = $v;
            }
        }
        return $result;
    }

    /**
     * Returns an array containing all of the result set rows
     *
     * @param int $fetch_style
     * @param string $fetch_argument
     * @param int $ctor_args
     * @return array|object|false
     * @see http://php.net/manual/en/pdostatement.fetchall.php
     */
    public function fetchAll($fetch_style = NULL, $fetch_argument = NULL, ...$ctor_args) {
        if($fetch_style === PDO::FETCH_BOTH) {
            $args = array_slice(func_get_args(),1);
            $result = parent::fetchAll(PDO::FETCH_NAMED, ...$args);
            if($result === false) {
                return $result;
            }
            return array_map('self::named2both',$result);
        }
        return parent::fetchAll(...func_get_args());
    }
}

