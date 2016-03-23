<?php namespace PdoPlus;
use Exception;
use PDO;
use PDOException;

/**
 * @method PdoPlusStatement prepare(string $statement, array $driver_options=[]) Prepares a statement for execution and returns a statement object
 */
abstract class PdoPlus extends PDO {
    /**
     * @param string $identifier
     * @throws Exception
     * @return string
     */
    protected static function quote_identifier($identifier) {
        throw new Exception('Not supported on base class');
    }

    public function count($table) {
        return (int)$this->query('SELECT COUNT(*) FROM ??',[$table])->fetchColumn();
    }

    public function min($table, $column) {
        return (int)$this->query('SELECT MIN(??) FROM ??',[$column,$table])->fetchColumn();
    }

    public function max($table, $column, $where = null) {
        if($where != null){
            $whereStr = "WHERE ".$this->build_where_list($where);
        }else{
            $whereStr = '';
        }
        return (int)$this->query('SELECT MAX(??) FROM ??'.$whereStr,[$column,$table])->fetchColumn();
    }

    public function truncate($table) {
        return $this->exec('TRUNCATE ??',[$table]);
    }

    /**
     * Prepare and execute a statement
     *
     * @param string $statement SQL query
     * @param mixed|array $input_parameters An array of values with as many elements as there are bound parameters in the SQL statement being executed, or a single value to be mapped to a single placeholder.
     * @return PdoPlusStatement
     * @deprecated You may now chain methods together such as $pdo->prepare("SELECT * FROM x WHERE y=?")->execute(10)->fetch()
     */
    public function prepare_execute($statement, $input_parameters=[]) {
        $stmt = $this->prepare($statement);
        if(!is_array($input_parameters)) $input_parameters = [$input_parameters];
        $stmt->execute($input_parameters);
        return $stmt;
    }

    public function quote_array($array, $add_parens=true) {
        $out = [];
        foreach($array as $v) {
            $out[] = $this->quote($v);
        }
        $sql = implode(', ',$out);
        if($add_parens) {
            $sql = "($sql)";
        }
        return $sql;
    }

    /**
     * @param $database
     * @param $table
     * @return array
     * @throws Exception
     */
    public function get_columns($database, $table) {
        if($database) {
            $columns = $this->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?')->execute($database, $table)->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $columns = $this->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?')->execute($table)->fetchAll(PDO::FETCH_COLUMN);
        }
        if(!$columns) throw new Exception("No columns found for $database.$table");
        return $columns;
    }

    /**
     * Tests if a table already has a column.
     *
     * @param string|null $database Database to check against. Set to "null" to use current database.
     * @param string $table Table to check
     * @param string $column Column to check the existence of
     * @return bool
     */
	public function column_exists($database, $table, $column) {
        if(strlen($database)) {
            return (bool)$this->prepare('SELECT EXISTS(SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?)')->execute([$database, $table, $column])->fetch(PDO::FETCH_COLUMN);
        } else {
            return (bool)$this->prepare('SELECT EXISTS(SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?)')->execute([$table, $column])->fetch(PDO::FETCH_COLUMN);
        }
	}
    public function interactive($filter=false) {
        while(true) {
            $sql = readline('SQL> ');
            if($sql==='q') break;
            readline_add_history($sql);
            try {
                $stmt = $this->query($sql);
                if($r = $stmt->fetch()) {
                    do {
                        Util::pprint_cli($filter ? array_filter($r) : $r);
                    } while($r = $stmt->fetch());
                } else {
                    echo 'No results.'.PHP_EOL;
                }
            } catch(PDOException $e) {
                echo $e->getMessage().PHP_EOL;
            }
        }
    }

    public function get_tables() {
        return $this->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function rethrow($pdo=null, \PDOException $e, $sql) {
        $code = $e->errorInfo[1];
        $message = $e->errorInfo[2];
        switch($code) {
            case MyPdo::ER_DUP_ENTRY:
                throw new DuplicateEntryException($message . "\nSQL: " . $sql, $code);
            case MyPdo::ER_NO_SUCH_TABLE:
                if(preg_match('#Table \'(.*)\' doesn\'t exist\z#A', $message,$matches)) {
                    $table = $matches[1];
                } else {
                    $table = null;
                }
                throw new NoSuchTableException($message . "\nSQL: " . $sql, $table, $code);
            case MyPdo::ER_CANNOT_ADD_FOREIGN:
                if($pdo) {
                    try {
                        $err = $pdo->query('show engine innodb status')->fetchColumn(2);
                        preg_match("#^------------------------\nLATEST FOREIGN KEY ERROR\n------------------------\n(.*?)(?:------------\n|\\Z)#sm", $err, $m);
                        $details = $m[1];
                    } catch(PDOException $statusException) {
                        try {
                            $user = $pdo->query('select user()')->fetchColumn();
                            $details = "(user $user does not have PROCESS privilege)";
                        } catch(PDOException $userException) {
                            $details = "(" . $statusException->getMessage() . ")";
                        }
                    }
                } else {
                    $details = "(no connection)";
                }

                throw new CannotAddForeignKeyException($e->getMessage(), $details, $sql, $code, $e->getPrevious());
            default:
                throw new MyPdoException($message . "\nSQL: " . $sql, $code);

        }
    }

    /**
     * Executes an SQL statement in a single function call, returning the result set (if any). All parameters are escaped client-side.
     *
     * @param string $sql An array of values with as many elements as there are bound parameters in the SQL statement being executed.
     * @param array $params An array of values with as many elements as there are bound parameters in the SQL statement being executed.
     * @return PdoPlusStatement
     */
    public function query($sql, $params=[]) {
        $escaper = new Escaper($this);
        $stmt = $escaper->format($sql, $params);
        try {
            return parent::query($stmt);
        } catch(PDOException $e) {
            static::rethrow($this, $e, $stmt);die;
        }
    }

    /**
     * Execute an SQL statement and return the number of affected rows. All parameters are escaped client-side.
     *
     * @param string $sql The SQL statement to prepare and execute.
     * @param array $params An array of values with as many elements as there are bound parameters in the SQL statement being executed.
     * @return int
     */
    public function exec($sql, $params=[]) {
        $escaper = new Escaper($this);
        $stmt = $escaper->format($sql, $params);
        try {
            return parent::exec($stmt);
        } catch(PDOException $e) {
            static::rethrow($this, $e, $stmt);die;
        }
    }

    /**
     * @param $table
     * @param string $fields
     * @param null $limit
     * @return PdoPlusStatement
     */
    abstract public function select_all($table, $fields='*', $limit=null);

    public function build_schema($data, $join='LEFT JOIN') {
        if(is_string($data)) return static::quote_identifier($data);
        if(is_array($data)) {
            $tables = array();
            foreach($data as $table=>$on) {
                if(is_int($table)) {
                    $tables[] = static::quote_identifier($on);
                } else {
                    $tables[] = static::quote_identifier($table).' ON '.$this->build_where_list($on);
                }
            }
            return implode(' '.trim($join).' ', $tables);
        }
        throw new Exception('Unsupported data type for schema');
    }

    /**
     * @param mixed $fields
     * @return string
     * @throws Exception
     */
    public static function build_columns($fields) {
        if(is_array($fields)) {
            $field_arr = [];
            if(Util::is_assoc($fields)) {
                foreach($fields as $alias=>$field) {
                    $field_arr[] = static::quote_identifier($field).' AS '.static::quote_identifier($alias);
                }
            } else {
                foreach($fields as $f) {
                    $field_arr[] = static::quote_identifier($f);
                }
            }
            return implode(', ', $field_arr);
        } elseif($fields===null) {
            return '*';
        } elseif(is_string($fields)) {
            return $fields;
        } else throw new \DomainException('fields');
    }

    /**
     * @param $data (Field => Value) pairs
     * @param string $type "AND" or "OR" each key/value pair together
     * @return string SQL
     * @throws Exception
     */
    public function build_where_list($data,$type='AND') {
        if(empty($data)) return '1';
        if(is_array($data)) return implode(" $type ", $this->build_where_pairs($data,strtoupper(trim($type))!=='AND'));
        if(is_string($data)) return $data;
//        if($data instanceof Expr) return $data->toSql();
//        if($data instanceof Expr) throw new Exception('Unsupported data type for where clause');
        throw new Exception('Unsupported data type for where clause');
    }

    /**
     * @param array $data (Field => Value) pairs
     * @param bool $conjunction True to "and" nested arrays together (conjunction), false to "or" them (disjunction)
     * @return array Flat array of SQL segments to be AND'd or OR'd together
     */
    protected function build_where_pairs($data,$conjunction=false) {
        $pairs = array();
        foreach($data as $k=>$v) {
            if(is_int($k)) {
                if(is_array($v)) $pairs[] = '('.$this->build_where_list($v,$conjunction?'AND':'OR').')';
                else $pairs[] = "($v)";
            }
            else if(strpos($k,'?')!==false) $pairs[] = str_replace('?', $this->quote($v), $k);// TODO: Should support $k being an array for multiple ?s. Also, perhaps this should be in parentheses
            else if(is_null($v)) $pairs[] = static::quote_identifier($k).' IS NULL';
            else if(is_array($v)) {
                if(empty($v)) $pairs[] = '0';
                else $pairs[] = static::quote_identifier($k).' IN ('.$this->quote($v).')';
            } else $pairs[] = static::quote_identifier($k).'='.$this->quote($v);
        }
        return $pairs;
    }
    
    /**
     * @param string $table
     * @param array $data
     * @param int $options bitmask
     * @return string
     */
    public function insert($table, $data, $options = 0) {
        $stmt = $this->prepare_insert($table, array_keys($data), $options);
        $stmt->execute($data);
        return $this->lastInsertId();
    }

    /**
     * @param string $table
     * @param array $columns
     * @param int $options bitmask
     * @return mixed
     */
    abstract public function prepare_insert($table, $columns, $options = 0);

    /**
     * Returns the name of the database you are currently connected to. Executes a query to do so.
     * @return string Database name
     */
    public function get_database(){
        return $this->query('SELECT DATABASE()')->fetchColumn();
    }

    /**
     * EXPERIMENTAL
     *
     * Only supports the bare minimum table,columns and where but matches function signature for MySQL_DATABASE select
     *
     * @param string $table
     * @param array|string $columns
     * @param string|array|null $where
     * @param null $group_by Not Supported
     * @param null $order Not Supported
     * @param int|null $limit
     * @param null $offset Not Supported
     * @param int $result_type Not Supported
     * @param null $having Not Supported
     * @param bool|false $distinct Not Supported
     * @return PdoPlusStatement
     * @throws Exception
     */
    public function prepare_select($table, $columns = '*', $where = null, $group_by = null, $order = null, $limit = null, $offset = null, $result_type = null, $having = null, $distinct = false) {
        $table_str = static::quote_identifier($table);
        $field_str = static::build_columns($columns);
        $sql = "SELECT $field_str FROM $table_str";
        if($where !== null){
            $sql .= " WHERE ".$this->build_where_list($where);
        }
        return $this->prepare($sql);
    }

}

