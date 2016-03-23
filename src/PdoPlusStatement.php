<?php namespace PdoPlus;

use PDO;
use PDOException;
use PDOStatement;

class PdoPlusStatement extends PDOStatement {

    protected function __construct() {
    }


    /**
     * @param array|mixed $input_parameters An array of values with as many elements as there are bound parameters in the SQL statement being executed, or one or more non-array arguments to be matched with sequential parameter markers.
     * @throws PDOException
     * @return PdoPlusStatement
     */
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
}

