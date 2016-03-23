<?php namespace PdoPlus;

use Exception;
use PDO;
use PDOException;


/**
 * @method PdoPlusStatement prepare(string $statement, array $driver_options=[]) Prepares a statement for execution and returns a statement object
 */
class MyPdo extends PdoPlus {
    const INS_LOW_PRIORITY = 1;
    const INS_DELAYED = 2;
    const INS_HIGH_PRIORITY = 4;
    const INS_IGNORE = 8;
    const INS_REPLACE = 16;


    public static $connectionCount = 0;

    function __construct($host, $database_name=null, $username, $password, $options=[]) {
        $options = self::merge([
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STATEMENT_CLASS => [PdoPlusStatement::class, []],
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ], $options);

        $dsn_params = [
            'host' => $host,
            'dbname' => $database_name,
            'charset' => 'utf8mb4', // charset requires PHP >= 5.3.6; see http://php.net/manual/en/ref.pdo-mysql.connection.php
        ];

        $dsn_filtered = [];
        foreach($dsn_params as $k=>$v) {
            if(strlen($v)) {
                $dsn_filtered[] = "$k=$v";
            }
        }

        $dsn = 'mysql:'.implode(';',$dsn_filtered);

        try {
            parent::__construct($dsn, $username, $password, $options);
            ++self::$connectionCount;
        } catch(\PDOException $ex) {
            if($ex->getCode() === self::ER_ACCESS_DENIED_ERROR) {
                throw new AccessDeniedException("Access denied for user '$username' (using password: ".(strlen($password)?'YES':'NO')."). DSN: $dsn");
            }
            if($ex->getCode() === self::ER_CON_COUNT_ERROR) {
                throw new PdoPlusException("Too many connections: ".self::$connectionCount, $ex->getCode(), $ex);
            }
            throw $ex;
        }
    }

    function __destruct() {
        --self::$connectionCount;
    }


    /**
     * Create a new MyPdo instance from an array of connection vars.
     *
     * @param array $dbVars Standard WebEngineX database connection vars
     * @param array $options See http://php.net/manual/en/pdo.construct.php
     * @return MyPdo
     */
    public static function createFromDbVars(array $dbVars, array $options = []) {
        if(!empty($dbVars['timezone'])) {
            $initCommand = (new Escaper())->format('SET SESSION time_zone=?', [$dbVars['timezone']]);
            if(!empty($options[PDO::MYSQL_ATTR_INIT_COMMAND])) {
                $initCommand .= ';'.$options[PDO::MYSQL_ATTR_INIT_COMMAND];
            }
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = $initCommand;
        }

        return new static($dbVars['host'], $dbVars['name'], $dbVars['login'], $dbVars['password'], $options);
    }

    /**
     * Emulated query. All parameters are escaped client-side. Query is executed immediately.
     *
     * Caution: This also differs from prepared statements in that all ? are replaced, even those contained in comments and strings.
     *
     * @param string $sql
     * @param array $params
     * @return PdoPlusStatement
     * @deprecated Just use query()
     */
    public function emuQuery($sql, $params=[]) {
        return $this->query((new Escaper($this))->format($sql, $params));
    }

    private static function merge() {
        $args = func_get_args();
        $out = array_shift($args);
        foreach($args as $arr) {
            foreach($arr as $k => $v) {
                $out[$k] = $v;
            }
        }
        return $out;
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
     * @throws \Exception
     */
    public function prepare_select($table, $columns = '*', $where = null, $group_by = null, $order = null, $limit = null, $offset = null, $result_type = null, $having = null, $distinct = false) {
        $table_str = self::quote_identifier($table);
        $field_str = self::build_columns($columns);
        $sql = "SELECT $field_str FROM $table_str";
        if($where !== null){
            $sql .= " WHERE ".$this->build_where_list($where);
        }
        if($limit!==null) {
            $sql .= " LIMIT $limit";
        }
        return $this->prepare($sql);
    }

    public function prepare_insert($table, $columns, $options = 0) {
        $table_sql = self::quote_identifier($table);
        $column_sql = implode(', ', array_map(['self', 'quote_identifier'], $columns));
        $placeholder_arr = [];
        foreach($columns as $col) {
            $placeholder_arr[] = ":$col";
        }
        $placeholder_sql = implode(', ', $placeholder_arr);
        $sql = ($options & self::INS_REPLACE) ? 'REPLACE ' : 'INSERT ';
        if($options & self::INS_LOW_PRIORITY) $sql .= 'LOW_PRIORITY ';
        elseif($options & self::INS_DELAYED) $sql .= 'DELAYED ';
        elseif($options & self::INS_HIGH_PRIORITY) $sql .= 'HIGH_PRIORITY ';
        if($options & self::INS_IGNORE) $sql .= 'IGNORE ';
        $sql .= "INTO $table_sql ($column_sql) VALUES ($placeholder_sql)";
        return $this->prepare($sql);
    }

    public function bulk_insert($table, $batch_size = 1000, $truncate_first = false, $ignore = false) {
        if($truncate_first) $this->truncate($table);
        return new BulkInsert($this, $table, $batch_size, $ignore);
    }

    public function insert_if_not_exists($table, $data, $where, $pk=null) {
        $select_sql = $pk===null ? '1' : self::quote_identifier($pk);
        $table_sql = self::quote_identifier($table);
        $where_sql = self::where_sql(array_keys($where));
        $result = $this->prepare("SELECT $select_sql FROM $table_sql WHERE $where_sql")->execute($where)->fetchColumn();
        return $result!==false ? $result : $this->insert($table, $data);
    }

    public function prepare_update($table, $columns, $where) {
        $table_sql = self::quote_identifier($table);
        $set_arr = [];
        foreach($columns as $col) {
            $set_arr[] = self::quote_identifier($col) . "=:$col";
        }
        $set_sql = implode(', ', $set_arr);
        $where_sql = self::where_sql($where);
        return $this->prepare("UPDATE $table_sql SET $set_sql WHERE $where_sql");
    }

    /**
     * Delete some rows from a table.
     *
     * @param string $table
     * @param array $where Simple [column => value] array
     * @param null|int $limit
     * @return int
     */
    public function delete($table, $where, $limit=null) {
        $table_sql = self::maybe_quote_identifier($table);
        $values = [];
        $where_arr = [];
        foreach($where as $k=>$v) {
            if(is_int($k)) {
                $where_arr[] = "($v)";
            } else {
                if(is_array($v)) {
                    $where_arr[] = self::quote_identifier($k).' IN ('.implode(',',array_fill(0,count($v),'?')).')';
                    $values = array_merge($values,array_values($v));
                } else {
                    $where_arr[] = self::quote_identifier($k).'=?';
                    $values[] = $v;
                }
            }
        }
        $where_sql = $where_arr ? implode(' AND ',$where_arr) : '1';
        $delete_sql = "DELETE FROM $table_sql WHERE $where_sql";
        if($limit) {
            $delete_sql .= ' LIMIT '.(int)$limit;
        }
        return (int)$this->prepare($delete_sql)->execute($values)->rowCount();
    }

    public function update($table, $data, $where, $limit=null) {
        $table_sql = self::maybe_quote_identifier($table);
        $values = [];

        $set_arr = [];
        foreach($data as $k=>$v) {
            if(is_int($k)) {
                $set_arr[] = $v;
            } else {
                $set_arr[] = self::quote_identifier($k).'=?';
                $values[] = $v;
            }
        }
        $set_sql = implode(', ',$set_arr);

        $where_arr = [];
        foreach($where as $k=>$v) {
            if(is_int($k)) {
                $where_arr[] = "($v)";
            } else {
                if(is_array($v)) {
                    $where_arr[] = self::quote_identifier($k).' IN ('.implode(',',array_fill(0,count($v),'?')).')';
                    $values = array_merge($values,array_values($v));
                } else {
                    $where_arr[] = self::quote_identifier($k).'=?';
                    $values[] = $v;
                }
            }
        }
        $where_sql = $where_arr ? implode(' AND ',$where_arr) : '1';
        $sql = "UPDATE $table_sql SET $set_sql WHERE $where_sql";
        if($limit !== null) {
            $sql .= ' LIMIT '.(int)$limit;
        }
        return (int)$this->prepare($sql)->execute($values)->rowCount();
    }

    public function exists($table, $where=[]) {
        $where_pairs = [];
        $where_values = [];
        foreach($where as $k=>$v) {
            if(is_int($k)) {
                $where_pairs[] = "($v)";
            } else {
                $where_pairs[] = self::quote_identifier($k).'=?';
                $where_values[] = $v;
            }
        }
        $where_sql = $where_pairs ? implode(' AND ',$where_pairs) : '1';
        $table_sql = self::quote_identifier($table);
        $select_sql = "SELECT EXISTS(SELECT * FROM $table_sql WHERE $where_sql)";
        return (bool)$this->prepare($select_sql)->execute($where_values)->fetchColumn();
    }

    public function table_exists($table) {
        return (bool)$this->prepare("SELECT EXISTS(SELECT * FROM information_schema.TABLES WHERE TABLE_NAME=? AND TABLE_SCHEMA=DATABASE())")->execute([$table])->fetchColumn();
    }

    private static function where_sql($where) {
        if($where===false) return '0';
        if($where===true || !$where) return '1';
        if(is_array($where)) {
            $where_arr = [];
            if(Util::is_assoc($where)) {
                foreach($where as $k => $v) {
                    $where_arr[] = "$k=:$v";
                }
            } else {
                foreach($where as $col) {
                    $where_arr[] = "$col=:$col";
                }
            }
            return implode(' AND ', $where_arr);
        }
        if(is_string($where)) {
            return $where;
        }
        throw new \Exception('Bad $where type');
    }

    /**
     * Quotes an identifier
     *
     * @param string $identifier Identifier (e.g., table or column name)
     * @param bool $strict True to quote the entire identifier as is, false to split on periods and then quote each part
     * @return string
     * @see http://dev.mysql.com/doc/refman/5.6/en/identifiers.html
     */
    public static function quote_identifier($identifier, $strict=false) {
        return $strict
            ? '`'.str_replace('`', '``', $identifier).'`'
            : implode('.',array_map(function($id) {
                return '`' . str_replace('`', '``', $id) . '`';
            },explode('.',$identifier)));
    }


    /**
     * *Maybe* quote a column if it looks like an identifier, otherwise leave it as is. For stricter quoting, use quote_identifier.
     *
     * @param $column
     * @return string
     */
    private static function maybe_quote_identifier($column) {
        $column = trim($column);
        if(!preg_match('/^[a-z_][a-z0-9_-]*(\.[a-z_][a-z0-9_-]*)*$/i', $column)) return $column;
        $parts = explode('.', $column);
        foreach($parts as &$p) $p = '`'.$p.'`';
        return implode('.', $parts);
    }

    /**
     * Truncates (empties) a table and resets any auto-increment counter
     *
     * @param string $table Table name
     * @return int
     */
    public function truncate($table) {
        return $this->exec('TRUNCATE ' . static::quote_identifier($table));
    }

    public function optimize($table) {
        $table_id = static::quote_identifier($table);
        $this->query('REPAIR TABLE ' .$table_id);
        $this->query('OPTIMIZE TABLE ' . $table_id);
    }


    /**
     * Copies all the records of a table from one database to another. Useful for replicating clients, notes and service providers.
     *
     * @param   string   $table    Table to copy
     * @param     string $from_db  From database name
     * @param string     $to_db    To database name
     * @param bool       $truncate Truncate to database before copying
     * @param string     $where    Where criteria for records to copy
     * @param string     $verb     "INSERT", "INSERT IGNORE", or "REPLACE" are good choices
     * @throws \PdoPlus\MyPdoException
     * @return int Number of records inserted
     */
    public function copy_table($table, $from_db, $to_db, $truncate = true, $where = null, $verb = "INSERT IGNORE") {
        $from_cols = $this->get_columns($from_db, $table);
        $to_cols = $this->get_columns($to_db, $table);
        $columns = implode(',', array_map('self::quote_identifier', array_intersect($from_cols, $to_cols)));
        $from_db_esc = self::quote_identifier($from_db);
        $to_db_esc = self::quote_identifier($to_db);
        $tbl_esc = self::quote_identifier($table);
        if($truncate) {
            $this->exec("TRUNCATE $to_db_esc.$tbl_esc");
        }
        $sql = "$verb INTO $to_db_esc.$tbl_esc ($columns) SELECT $columns FROM $from_db_esc.$tbl_esc";
        if($where) $sql .= ' WHERE ' . $where;
        return $this->exec($sql);
    }

    /**
     * Deletes excess records from destination database then performs an "upsert" (insert/on duplicate key update) to synchronize the remaining data.
     * This has the advantage of not deleting and then re-inserting the same data, does not wipe any additional columns that might be in the destination
     * database, and gives an accurate count of the number of records that were updated.
     *
     * @param string $table Table name
     * @param string $src_db Source database name
     * @param string $dest_db Destination database name
     * @param string $pk Name of primary key column
     * @return int
     * @throws \Exception
     */
    public function synchronize_table_upsert($table, $src_db, $dest_db, $pk){
        $from_cols = $this->get_columns($src_db, $table);
        $to_cols = $this->get_columns($dest_db, $table);
        $columns = array_intersect($from_cols, $to_cols);
        $esc = new Escaper($this);
        $deleted = $this->exec($esc->format("DELETE FROM ::to_db.::tbl WHERE NOT EXISTS(SELECT * FROM ::from_db.::tbl WHERE ::to_db.::tbl.::pk=::from_db.::tbl.::pk)", ['from_db' => $src_db, 'to_db' => $dest_db, 'pk' => $pk, 'tbl'=>$table]));
        $update = [];
        foreach($columns as $c) {
//            if($c===$pk) continue;
            $update[] = $esc->format("::c=VALUES(::c)", ['c' => $c]);
        }
        $update_sql = implode(', ',$update);
        $upsert_sql = $esc->format("INSERT INTO ::to_db.::tbl (::columns) SELECT ::columns FROM ::from_db.::tbl ON DUPLICATE KEY UPDATE $update_sql",['from_db' => $src_db, 'to_db' => $dest_db, 'tbl'=>$table, 'columns'=>$columns]);
        $upserted = $this->exec($upsert_sql);
        return $deleted + $upserted;
    }

    /**
     * Updates a table with data from another database, joining on the primary key. Can be used to sync the emr_client table with the PCS without inserting new records.
     *
     * @param string   $table   Table name
     * @param  string  $from_db From database name
     * @param   string $to_db   To database name
     * @param string   $pk      Primary key field name
     * @return int Number of records updated
     */
    public function synchronize_table($table, $from_db, $to_db, $pk = null) {
        if($pk === null) $pk = $from_db . '_id';
        $pk_esc = self::quote_identifier($pk);
        $from_cols = $this->get_columns($from_db, $table);
        $to_cols = $this->get_columns($to_db, $table);
        $columns = array_intersect($from_cols, $to_cols);
        $from_db_esc = self::quote_identifier($from_db);
        $to_db_esc = self::quote_identifier($to_db);
        $tbl_esc = self::quote_identifier($table);
        $set = [];
        foreach($columns as $col) {
            if($col === $pk) continue;
            $col = self::quote_identifier($col);
            $set[] = "`dest`.$col=`src`.$col";
        }
        $sql = "UPDATE $to_db_esc.$tbl_esc `dest` JOIN $from_db_esc.$tbl_esc `src` ON `dest`.$pk_esc=`src`.$pk_esc SET " . implode(',', $set);
        return $this->exec($sql);
    }

    public function select_all($table, $fields='*', $limit=null) {
        $field_str = self::build_columns($fields);
        $table_str = self::quote_identifier($table);
        $sql = "SELECT $field_str FROM $table_str";
        if($limit!==null) {
            $sql .= " LIMIT $limit";
        }
        return $this->query($sql);
    }

    /**
     * Quotes a string for use in a query.
     *
     * @param mixed $value The value to be quoted.
	 * @param null $parameter_type Provides a data type hint for drivers that have alternate quoting styles. Not used.
     * @return string Returns a quoted string that is theoretically safe to pass into an SQL statement.
     */
    public function quote($value, $parameter_type=null) {
        if(is_null($value)) return 'NULL';
        elseif(is_bool($value)) return $value ? 'TRUE' : 'FALSE';
        elseif(is_int($value)||is_float($value)) return $value;
        return parent::quote($value);
    }

    public function drop_table($table, $if_exists) {
        $sql = 'DROP TABLE ';
        if($if_exists) $sql .= 'IF EXISTS ';
        $sql .= self::quote_identifier($table);
        return $this->exec($sql);
    }

    public function drop_trigger($trigger, $if_exists) {
        $sql = 'DROP TRIGGER ';
        if($if_exists) $sql .= 'IF EXISTS ';
        $sql .= self::quote_identifier($trigger);
        return $this->exec($sql);
    }

    public function drop_column($table, $column_name) {
        $sql = 'ALTER TABLE '.self::quote_identifier($table).' DROP '.self::quote_identifier($column_name);
        return $this->exec($sql);
    }

    /**
     * @param string $table Name of existing table
     * @param string $column_name Name of column to add
     * @param string $type MySQL data type. @see http://dev.mysql.com/doc/refman/5.7/en/data-types.html
     * @param bool $null Allow null
     * @param bool|null|string $default Default value. Use PHP true/false/null to indicate their MySQL equivalents as strings will be automatically quoted.
     * @param bool $silent True to suppress "duplicate field name" exceptions. Function will return `false` instead.
     * @param string $after Add new column after this one. Default is to add column last.
     * @throws \Exception|\PDOException
     * @return bool|int Number of affected rows, or `false` if the field already exists and `$silent` is truthy.
     */
    public function add_column($table, $column_name, $type, $null, $default=null, $silent=true, $after=null) {
        $sql = 'ALTER TABLE '.self::quote_identifier($table).' ADD '.self::quote_identifier($column_name).' '.$type;
        if(!$null) $sql .= ' NOT NULL';
        if(func_num_args() >= 5) {
            $sql .= ' DEFAULT '.$this->quote($default);
        }
        if($after) {
            $sql .= ' AFTER '.self::quote_identifier($after);
        }
        try {
            return $this->exec($sql);
        } catch(PDOException $e) {
            if($silent && $e->errorInfo[1] === self::ER_DUP_FIELDNAME) return false;
            throw $e;
        }
    }

    /**
     * Switches the active database
     *
     * @param string $dbname Database name
     * @return int
     */
    public function use_database($dbname) {
        return $this->exec('USE ' . self::quote_identifier($dbname));
    }

    /**
     * @param string $table Table name
     * @return string[] Array of primary key columns. May be empty.
     */
    public function get_primary_keys($table) {
        return $this->query("SHOW COLUMNS FROM ?? WHERE `Key`='PRI'",[$table])->fetchAll(PDO::FETCH_COLUMN);
    }

    public function get_unique_keys($table) {
        $keys = $this->query("SHOW KEYS FROM ?? WHERE `Non_unique`=0",[$table])->fetchAll();
        $groups = [];
        foreach($keys as $k) {
            Util::array_push($groups,$k['Key_name'],$k['Column_name']);
        }
        return $groups;
    }




    /**
     * Gets a set of columns that (probably) uniquely identify a row.
     *
     * Picks a unique key that uses the fewest number of columns.
     *
     * If no unique keys are found, returns all columns. If there are full row duplicates then you're hooped.
     *
     * @param string $table
     * @return array
     * @throws Exception
     */
    public function get_unique_columns($table) {
        $groups = $this->get_unique_keys($table);
        if($groups) {
            // sort the keys to find the one with the fewest columns
            usort($groups, function($a,$b) {
                return count($a) - count($b);
            });
            return $groups[0];
        }
        return $this->get_columns(null, $table);
    }

    /**
     * @param string $table Table name
     * @return string|false Name if auto-increment column if one exists, otherwise false
     */
    public function get_autoincrement_column($table) {
        return $this->query("SHOW COLUMNS FROM ?? WHERE `Extra` LIKE '%auto_increment%'",[$table])->fetchColumn();
    }

    /**
     * @param string $table Table name
     * @return int|null Next auto-increment value or NULL if no auto-increment column exists
     */
    public function get_autoincrement_value($table) {
        $result = $this->query("SELECT `AUTO_INCREMENT` FROM  INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",[$table])->fetchColumn();
        return $result === false ? null : (int)$result;
    }


//    public function __debugInfo() {
//        // see http://dev.mysql.com/doc/refman/5.7/en/information-functions.html
//        return $this->query("SELECT DATABASE() `database`, USER() `user`, CONNECTION_ID() `connection_id`")->fetch();
//    }

    const ER_HASHCHK = 1000;
    const ER_NISAMCHK = 1001;
    const ER_NO = 1002;
    const ER_YES = 1003;
    const ER_CANT_CREATE_FILE = 1004;
    const ER_CANT_CREATE_TABLE = 1005;
    const ER_CANT_CREATE_DB = 1006;
    const ER_DB_CREATE_EXISTS = 1007;
    const ER_DB_DROP_EXISTS = 1008;
    const ER_DB_DROP_DELETE = 1009;
    const ER_DB_DROP_RMDIR = 1010;
    const ER_CANT_DELETE_FILE = 1011;
    const ER_CANT_FIND_SYSTEM_REC = 1012;
    const ER_CANT_GET_STAT = 1013;
    const ER_CANT_GET_WD = 1014;
    const ER_CANT_LOCK = 1015;
    const ER_CANT_OPEN_FILE = 1016;
    const ER_FILE_NOT_FOUND = 1017;
    const ER_CANT_READ_DIR = 1018;
    const ER_CANT_SET_WD = 1019;
    const ER_CHECKREAD = 1020;
    const ER_DISK_FULL = 1021;
    const ER_DUP_KEY = 1022;
    const ER_ERROR_ON_CLOSE = 1023;
    const ER_ERROR_ON_READ = 1024;
    const ER_ERROR_ON_RENAME = 1025;
    const ER_ERROR_ON_WRITE = 1026;
    const ER_FILE_USED = 1027;
    const ER_FILSORT_ABORT = 1028;
    const ER_FORM_NOT_FOUND = 1029;
    const ER_GET_ERRNO = 1030;
    const ER_ILLEGAL_HA = 1031;
    const ER_KEY_NOT_FOUND = 1032;
    const ER_NOT_FORM_FILE = 1033;
    const ER_NOT_KEYFILE = 1034;
    const ER_OLD_KEYFILE = 1035;
    const ER_OPEN_AS_READONLY = 1036;
    const ER_OUTOFMEMORY = 1037;
    const ER_OUT_OF_SORTMEMORY = 1038;
    const ER_UNEXPECTED_EOF = 1039;
    const ER_CON_COUNT_ERROR = 1040;
    const ER_OUT_OF_RESOURCES = 1041;
    const ER_BAD_HOST_ERROR = 1042;
    const ER_HANDSHAKE_ERROR = 1043;
    const ER_DBACCESS_DENIED_ERROR = 1044;
    const ER_ACCESS_DENIED_ERROR = 1045;
    const ER_NO_DB_ERROR = 1046;
    const ER_UNKNOWN_COM_ERROR = 1047;
    const ER_BAD_NULL_ERROR = 1048;
    const ER_BAD_DB_ERROR = 1049;
    const ER_TABLE_EXISTS_ERROR = 1050;
    const ER_BAD_TABLE_ERROR = 1051;
    const ER_NON_UNIQ_ERROR = 1052;
    const ER_SERVER_SHUTDOWN = 1053;
    const ER_BAD_FIELD_ERROR = 1054;
    const ER_WRONG_FIELD_WITH_GROUP = 1055;
    const ER_WRONG_GROUP_FIELD = 1056;
    const ER_WRONG_SUM_SELECT = 1057;
    const ER_WRONG_VALUE_COUNT = 1058;
    const ER_TOO_LONG_IDENT = 1059;
    /** Duplicate column name */
    const ER_DUP_FIELDNAME = 1060;
    /** Duplicate key name */
    const ER_DUP_KEYNAME = 1061;
    /** Duplicate entry for key */
    const ER_DUP_ENTRY = 1062;
    const ER_WRONG_FIELD_SPEC = 1063;
    const ER_PARSE_ERROR = 1064;
    const ER_EMPTY_QUERY = 1065;
    const ER_NONUNIQ_TABLE = 1066;
    const ER_INVALID_DEFAULT = 1067;
    const ER_MULTIPLE_PRI_KEY = 1068;
    const ER_TOO_MANY_KEYS = 1069;
    const ER_TOO_MANY_KEY_PARTS = 1070;
    const ER_TOO_LONG_KEY = 1071;
    const ER_KEY_COLUMN_DOES_NOT_EXITS = 1072;
    const ER_BLOB_USED_AS_KEY = 1073;
    const ER_TOO_BIG_FIELDLENGTH = 1074;
    const ER_WRONG_AUTO_KEY = 1075;
    const ER_READY = 1076;
    const ER_NORMAL_SHUTDOWN = 1077;
    const ER_GOT_SIGNAL = 1078;
    const ER_SHUTDOWN_COMPLETE = 1079;
    const ER_FORCING_CLOSE = 1080;
    const ER_IPSOCK_ERROR = 1081;
    const ER_NO_SUCH_INDEX = 1082;
    const ER_WRONG_FIELD_TERMINATORS = 1083;
    const ER_BLOBS_AND_NO_TERMINATED = 1084;
    const ER_TEXTFILE_NOT_READABLE = 1085;
    const ER_FILE_EXISTS_ERROR = 1086;
    const ER_LOAD_INFO = 1087;
    const ER_ALTER_INFO = 1088;
    const ER_WRONG_SUB_KEY = 1089;
    const ER_CANT_REMOVE_ALL_FIELDS = 1090;
    const ER_CANT_DROP_FIELD_OR_KEY = 1091;
    const ER_INSERT_INFO = 1092;
    const ER_UPDATE_TABLE_USED = 1093;
    const ER_NO_SUCH_THREAD = 1094;
    const ER_KILL_DENIED_ERROR = 1095;
    const ER_NO_TABLES_USED = 1096;
    const ER_TOO_BIG_SET = 1097;
    const ER_NO_UNIQUE_LOGFILE = 1098;
    const ER_TABLE_NOT_LOCKED_FOR_WRITE = 1099;
    const ER_TABLE_NOT_LOCKED = 1100;
    const ER_BLOB_CANT_HAVE_DEFAULT = 1101;
    const ER_WRONG_DB_NAME = 1102;
    const ER_WRONG_TABLE_NAME = 1103;
    const ER_TOO_BIG_SELECT = 1104;
    const ER_UNKNOWN_ERROR = 1105;
    const ER_UNKNOWN_PROCEDURE = 1106;
    const ER_WRONG_PARAMCOUNT_TO_PROCEDURE = 1107;
    const ER_WRONG_PARAMETERS_TO_PROCEDURE = 1108;
    const ER_UNKNOWN_TABLE = 1109;
    const ER_FIELD_SPECIFIED_TWICE = 1110;
    const ER_INVALID_GROUP_FUNC_USE = 1111;
    const ER_UNSUPPORTED_EXTENSION = 1112;
    const ER_TABLE_MUST_HAVE_COLUMNS = 1113;
    const ER_RECORD_FILE_FULL = 1114;
    const ER_UNKNOWN_CHARACTER_SET = 1115;
    const ER_TOO_MANY_TABLES = 1116;
    const ER_TOO_MANY_FIELDS = 1117;
    const ER_TOO_BIG_ROWSIZE = 1118;
    const ER_STACK_OVERRUN = 1119;
    const ER_WRONG_OUTER_JOIN = 1120;
    const ER_NULL_COLUMN_IN_INDEX = 1121;
    const ER_CANT_FIND_UDF = 1122;
    const ER_CANT_INITIALIZE_UDF = 1123;
    const ER_UDF_NO_PATHS = 1124;
    const ER_UDF_EXISTS = 1125;
    const ER_CANT_OPEN_LIBRARY = 1126;
    const ER_CANT_FIND_DL_ENTRY = 1127;
    const ER_FUNCTION_NOT_DEFINED = 1128;
    const ER_HOST_IS_BLOCKED = 1129;
    const ER_HOST_NOT_PRIVILEGED = 1130;
    const ER_PASSWORD_ANONYMOUS_USER = 1131;
    const ER_PASSWORD_NOT_ALLOWED = 1132;
    const ER_PASSWORD_NO_MATCH = 1133;
    const ER_UPDATE_INFO = 1134;
    const ER_CANT_CREATE_THREAD = 1135;
    const ER_WRONG_VALUE_COUNT_ON_ROW = 1136;
    const ER_CANT_REOPEN_TABLE = 1137;
    const ER_INVALID_USE_OF_NULL = 1138;
    const ER_REGEXP_ERROR = 1139;
    const ER_MIX_OF_GROUP_FUNC_AND_FIELDS = 1140;
    const ER_NONEXISTING_GRANT = 1141;
    const ER_TABLEACCESS_DENIED_ERROR = 1142;
    const ER_COLUMNACCESS_DENIED_ERROR = 1143;
    const ER_ILLEGAL_GRANT_FOR_TABLE = 1144;
    const ER_GRANT_WRONG_HOST_OR_USER = 1145;
    const ER_NO_SUCH_TABLE = 1146;
    const ER_NONEXISTING_TABLE_GRANT = 1147;
    const ER_NOT_ALLOWED_COMMAND = 1148;
    const ER_SYNTAX_ERROR = 1149;
    /**
     * @deprecated Unused as of MySQL 5.7
     * @link http://dev.mysql.com/doc/refman/5.7/en/error-messages-server.html#error_er_unused1
     */
    const ER_DELAYED_CANT_CHANGE_LOCK = 1150;
    const ER_UNUSED1 = 1150;
    /**
     * @deprecated Unused as of MySQL 5.7
     * @link http://dev.mysql.com/doc/refman/5.7/en/error-messages-server.html#error_er_unused2
     */
    const ER_TOO_MANY_DELAYED_THREADS = 1151;
    const ER_UNUSED2 = 1151;
    const ER_ABORTING_CONNECTION = 1152;
    const ER_NET_PACKET_TOO_LARGE = 1153;
    const ER_NET_READ_ERROR_FROM_PIPE = 1154;
    const ER_NET_FCNTL_ERROR = 1155;
    const ER_NET_PACKETS_OUT_OF_ORDER = 1156;
    const ER_NET_UNCOMPRESS_ERROR = 1157;
    const ER_NET_READ_ERROR = 1158;
    const ER_NET_READ_INTERRUPTED = 1159;
    const ER_NET_ERROR_ON_WRITE = 1160;
    const ER_NET_WRITE_INTERRUPTED = 1161;
    const ER_TOO_LONG_STRING = 1162;
    const ER_TABLE_CANT_HANDLE_BLOB = 1163;
    const ER_TABLE_CANT_HANDLE_AUTO_INCREMENT = 1164;
    /**
     * @deprecated Unused as of MySQL 5.7
     * @link http://dev.mysql.com/doc/refman/5.7/en/error-messages-server.html#error_er_unused3
     */
    const ER_DELAYED_INSERT_TABLE_LOCKED = 1165;
    const ER_UNUSED3 = 1165;
    const ER_WRONG_COLUMN_NAME = 1166;
    const ER_WRONG_KEY_COLUMN = 1167;
    const ER_WRONG_MRG_TABLE = 1168;
    const ER_DUP_UNIQUE = 1169;
    const ER_BLOB_KEY_WITHOUT_LENGTH = 1170;
    const ER_PRIMARY_CANT_HAVE_NULL = 1171;
    const ER_TOO_MANY_ROWS = 1172;
    const ER_REQUIRES_PRIMARY_KEY = 1173;
    const ER_NO_RAID_COMPILED = 1174;
    const ER_UPDATE_WITHOUT_KEY_IN_SAFE_MODE = 1175;
    const ER_KEY_DOES_NOT_EXITS = 1176;
    const ER_CHECK_NO_SUCH_TABLE = 1177;
    const ER_CHECK_NOT_IMPLEMENTED = 1178;
    const ER_CANT_DO_THIS_DURING_AN_TRANSACTION = 1179;
    const ER_ERROR_DURING_COMMIT = 1180;
    const ER_ERROR_DURING_ROLLBACK = 1181;
    const ER_ERROR_DURING_FLUSH_LOGS = 1182;
    const ER_ERROR_DURING_CHECKPOINT = 1183;
    const ER_NEW_ABORTING_CONNECTION = 1184;
    const ER_DUMP_NOT_IMPLEMENTED = 1185;
    const ER_FLUSH_MASTER_BINLOG_CLOSED = 1186;
    const ER_INDEX_REBUILD = 1187;
    const ER_MASTER = 1188;
    const ER_MASTER_NET_READ = 1189;
    const ER_MASTER_NET_WRITE = 1190;
    const ER_FT_MATCHING_KEY_NOT_FOUND = 1191;
    const ER_LOCK_OR_ACTIVE_TRANSACTION = 1192;
    const ER_UNKNOWN_SYSTEM_VARIABLE = 1193;
    const ER_CRASHED_ON_USAGE = 1194;
    const ER_CRASHED_ON_REPAIR = 1195;
    const ER_WARNING_NOT_COMPLETE_ROLLBACK = 1196;
    const ER_TRANS_CACHE_FULL = 1197;
    const ER_SLAVE_MUST_STOP = 1198;
    const ER_SLAVE_NOT_RUNNING = 1199;
    const ER_BAD_SLAVE = 1200;
    const ER_MASTER_INFO = 1201;
    const ER_SLAVE_THREAD = 1202;
    const ER_TOO_MANY_USER_CONNECTIONS = 1203;
    const ER_SET_CONSTANTS_ONLY = 1204;
    const ER_LOCK_WAIT_TIMEOUT = 1205;
    const ER_LOCK_TABLE_FULL = 1206;
    const ER_READ_ONLY_TRANSACTION = 1207;
    const ER_DROP_DB_WITH_READ_LOCK = 1208;
    const ER_CREATE_DB_WITH_READ_LOCK = 1209;
    const ER_WRONG_ARGUMENTS = 1210;
    const ER_NO_PERMISSION_TO_CREATE_USER = 1211;
    const ER_UNION_TABLES_IN_DIFFERENT_DIR = 1212;
    const ER_LOCK_DEADLOCK = 1213;
    const ER_TABLE_CANT_HANDLE_FT = 1214;
    const ER_CANNOT_ADD_FOREIGN = 1215;
    const ER_NO_REFERENCED_ROW = 1216;
    const ER_ROW_IS_REFERENCED = 1217;
    const ER_CONNECT_TO_MASTER = 1218;
    const ER_QUERY_ON_MASTER = 1219;
    const ER_ERROR_WHEN_EXECUTING_COMMAND = 1220;
    const ER_WRONG_USAGE = 1221;
    const ER_WRONG_NUMBER_OF_COLUMNS_IN_SELECT = 1222;
    const ER_CANT_UPDATE_WITH_READLOCK = 1223;
    const ER_MIXING_NOT_ALLOWED = 1224;
    const ER_DUP_ARGUMENT = 1225;
    const ER_USER_LIMIT_REACHED = 1226;
    const ER_SPECIFIC_ACCESS_DENIED_ERROR = 1227;
    const ER_LOCAL_VARIABLE = 1228;
    const ER_GLOBAL_VARIABLE = 1229;
    const ER_NO_DEFAULT = 1230;
    const ER_WRONG_VALUE_FOR_VAR = 1231;
    const ER_WRONG_TYPE_FOR_VAR = 1232;
    const ER_VAR_CANT_BE_READ = 1233;
    const ER_CANT_USE_OPTION_HERE = 1234;
    const ER_NOT_SUPPORTED_YET = 1235;
    const ER_MASTER_FATAL_ERROR_READING_BINLOG = 1236;
    const ER_SLAVE_IGNORED_TABLE = 1237;
    const ER_INCORRECT_GLOBAL_LOCAL_VAR = 1238;
    const ER_WRONG_FK_DEF = 1239;
    const ER_KEY_REF_DO_NOT_MATCH_TABLE_REF = 1240;
    const ER_OPERAND_COLUMNS = 1241;
    const ER_SUBQUERY_NO_1_ROW = 1242;
    const ER_UNKNOWN_STMT_HANDLER = 1243;
    const ER_CORRUPT_HELP_DB = 1244;
    const ER_CYCLIC_REFERENCE = 1245;
    const ER_AUTO_CONVERT = 1246;
    const ER_ILLEGAL_REFERENCE = 1247;
    const ER_DERIVED_MUST_HAVE_ALIAS = 1248;
    const ER_SELECT_REDUCED = 1249;
    const ER_TABLENAME_NOT_ALLOWED_HERE = 1250;
    const ER_NOT_SUPPORTED_AUTH_MODE = 1251;
    const ER_SPATIAL_CANT_HAVE_NULL = 1252;
    const ER_COLLATION_CHARSET_MISMATCH = 1253;
    const ER_SLAVE_WAS_RUNNING = 1254;
    const ER_SLAVE_WAS_NOT_RUNNING = 1255;
    const ER_TOO_BIG_FOR_UNCOMPRESS = 1256;
    const ER_ZLIB_Z_MEM_ERROR = 1257;
    const ER_ZLIB_Z_BUF_ERROR = 1258;
    const ER_ZLIB_Z_DATA_ERROR = 1259;
    const ER_CUT_VALUE_GROUP_CONCAT = 1260;
    const ER_WARN_TOO_FEW_RECORDS = 1261;
    const ER_WARN_TOO_MANY_RECORDS = 1262;
    const ER_WARN_NULL_TO_NOTNULL = 1263;
    const ER_WARN_DATA_OUT_OF_RANGE = 1264;
    const WARN_DATA_TRUNCATED = 1265;
    const ER_WARN_USING_OTHER_HANDLER = 1266;
    const ER_CANT_AGGREGATE_2COLLATIONS = 1267;
    const ER_DROP_USER = 1268;
    const ER_REVOKE_GRANTS = 1269;
    const ER_CANT_AGGREGATE_3COLLATIONS = 1270;
    const ER_CANT_AGGREGATE_NCOLLATIONS = 1271;
    const ER_VARIABLE_IS_NOT_STRUCT = 1272;
    const ER_UNKNOWN_COLLATION = 1273;
    const ER_SLAVE_IGNORED_SSL_PARAMS = 1274;
    const ER_SERVER_IS_IN_SECURE_AUTH_MODE = 1275;
    const ER_WARN_FIELD_RESOLVED = 1276;
    const ER_BAD_SLAVE_UNTIL_COND = 1277;
    const ER_MISSING_SKIP_SLAVE = 1278;
    const ER_UNTIL_COND_IGNORED = 1279;
    const ER_WRONG_NAME_FOR_INDEX = 1280;
    const ER_WRONG_NAME_FOR_CATALOG = 1281;
    const ER_WARN_QC_RESIZE = 1282;
    const ER_BAD_FT_COLUMN = 1283;
    const ER_UNKNOWN_KEY_CACHE = 1284;
    const ER_WARN_HOSTNAME_WONT_WORK = 1285;
    const ER_UNKNOWN_STORAGE_ENGINE = 1286;
    const ER_WARN_DEPRECATED_SYNTAX = 1287;
    const ER_NON_UPDATABLE_TABLE = 1288;
    const ER_FEATURE_DISABLED = 1289;
    const ER_OPTION_PREVENTS_STATEMENT = 1290;
    const ER_DUPLICATED_VALUE_IN_TYPE = 1291;
    const ER_TRUNCATED_WRONG_VALUE = 1292;
    const ER_TOO_MUCH_AUTO_TIMESTAMP_COLS = 1293;
    const ER_INVALID_ON_UPDATE = 1294;
    const ER_UNSUPPORTED_PS = 1295;
    const ER_GET_ERRMSG = 1296;
    const ER_GET_TEMPORARY_ERRMSG = 1297;
    const ER_UNKNOWN_TIME_ZONE = 1298;
    const ER_WARN_INVALID_TIMESTAMP = 1299;
    const ER_INVALID_CHARACTER_STRING = 1300;
    const ER_WARN_ALLOWED_PACKET_OVERFLOWED = 1301;
    const ER_CONFLICTING_DECLARATIONS = 1302;
    const ER_SP_NO_RECURSIVE_CREATE = 1303;
    const ER_SP_ALREADY_EXISTS = 1304;
    const ER_SP_DOES_NOT_EXIST = 1305;
    const ER_SP_DROP_FAILED = 1306;
    const ER_SP_STORE_FAILED = 1307;
    const ER_SP_LILABEL_MISMATCH = 1308;
    const ER_SP_LABEL_REDEFINE = 1309;
    const ER_SP_LABEL_MISMATCH = 1310;
    const ER_SP_UNINIT_VAR = 1311;
    const ER_SP_BADSELECT = 1312;
    const ER_SP_BADRETURN = 1313;
    const ER_SP_BADSTATEMENT = 1314;
    const ER_UPDATE_LOG_DEPRECATED_IGNORED = 1315;
    const ER_UPDATE_LOG_DEPRECATED_TRANSLATED = 1316;
    const ER_QUERY_INTERRUPTED = 1317;
    const ER_SP_WRONG_NO_OF_ARGS = 1318;
    const ER_SP_COND_MISMATCH = 1319;
    const ER_SP_NORETURN = 1320;
    const ER_SP_NORETURNEND = 1321;
    const ER_SP_BAD_CURSOR_QUERY = 1322;
    const ER_SP_BAD_CURSOR_SELECT = 1323;
    const ER_SP_CURSOR_MISMATCH = 1324;
    const ER_SP_CURSOR_ALREADY_OPEN = 1325;
    const ER_SP_CURSOR_NOT_OPEN = 1326;
    const ER_SP_UNDECLARED_VAR = 1327;
    const ER_SP_WRONG_NO_OF_FETCH_ARGS = 1328;
    const ER_SP_FETCH_NO_DATA = 1329;
    const ER_SP_DUP_PARAM = 1330;
    const ER_SP_DUP_VAR = 1331;
    const ER_SP_DUP_COND = 1332;
    const ER_SP_DUP_CURS = 1333;
    const ER_SP_CANT_ALTER = 1334;
    const ER_SP_SUBSELECT_NYI = 1335;
    const ER_STMT_NOT_ALLOWED_IN_SF_OR_TRG = 1336;
    const ER_SP_VARCOND_AFTER_CURSHNDLR = 1337;
    const ER_SP_CURSOR_AFTER_HANDLER = 1338;
    const ER_SP_CASE_NOT_FOUND = 1339;
    const ER_FPARSER_TOO_BIG_FILE = 1340;
    const ER_FPARSER_BAD_HEADER = 1341;
    const ER_FPARSER_EOF_IN_COMMENT = 1342;
    const ER_FPARSER_ERROR_IN_PARAMETER = 1343;
    const ER_FPARSER_EOF_IN_UNKNOWN_PARAMETER = 1344;
    const ER_VIEW_NO_EXPLAIN = 1345;
    const ER_FRM_UNKNOWN_TYPE = 1346;
    const ER_WRONG_OBJECT = 1347;
    const ER_NONUPDATEABLE_COLUMN = 1348;
    const ER_VIEW_SELECT_DERIVED = 1349;
    const ER_VIEW_SELECT_CLAUSE = 1350;
    const ER_VIEW_SELECT_VARIABLE = 1351;
    const ER_VIEW_SELECT_TMPTABLE = 1352;
    const ER_VIEW_WRONG_LIST = 1353;
    const ER_WARN_VIEW_MERGE = 1354;
    const ER_WARN_VIEW_WITHOUT_KEY = 1355;
    const ER_VIEW_INVALID = 1356;
    const ER_SP_NO_DROP_SP = 1357;
    const ER_SP_GOTO_IN_HNDLR = 1358;
    const ER_TRG_ALREADY_EXISTS = 1359;
    const ER_TRG_DOES_NOT_EXIST = 1360;
    const ER_TRG_ON_VIEW_OR_TEMP_TABLE = 1361;
    const ER_TRG_CANT_CHANGE_ROW = 1362;
    const ER_TRG_NO_SUCH_ROW_IN_TRG = 1363;
    const ER_NO_DEFAULT_FOR_FIELD = 1364;
    const ER_DIVISION_BY_ZERO = 1365;
    const ER_TRUNCATED_WRONG_VALUE_FOR_FIELD = 1366;
    const ER_ILLEGAL_VALUE_FOR_TYPE = 1367;
    const ER_VIEW_NONUPD_CHECK = 1368;
    const ER_VIEW_CHECK_FAILED = 1369;
    const ER_PROCACCESS_DENIED_ERROR = 1370;
    const ER_RELAY_LOG_FAIL = 1371;
    const ER_PASSWD_LENGTH = 1372;
    const ER_UNKNOWN_TARGET_BINLOG = 1373;
    const ER_IO_ERR_LOG_INDEX_READ = 1374;
    const ER_BINLOG_PURGE_PROHIBITED = 1375;
    const ER_FSEEK_FAIL = 1376;
    const ER_BINLOG_PURGE_FATAL_ERR = 1377;
    const ER_LOG_IN_USE = 1378;
    const ER_LOG_PURGE_UNKNOWN_ERR = 1379;
    const ER_RELAY_LOG_INIT = 1380;
    const ER_NO_BINARY_LOGGING = 1381;
    const ER_RESERVED_SYNTAX = 1382;
    const ER_WSAS_FAILED = 1383;
    const ER_DIFF_GROUPS_PROC = 1384;
    const ER_NO_GROUP_FOR_PROC = 1385;
    const ER_ORDER_WITH_PROC = 1386;
    const ER_LOGGING_PROHIBIT_CHANGING_OF = 1387;
    const ER_NO_FILE_MAPPING = 1388;
    const ER_WRONG_MAGIC = 1389;
    const ER_PS_MANY_PARAM = 1390;
    const ER_KEY_PART_0 = 1391;
    const ER_VIEW_CHECKSUM = 1392;
    const ER_VIEW_MULTIUPDATE = 1393;
    const ER_VIEW_NO_INSERT_FIELD_LIST = 1394;
    const ER_VIEW_DELETE_MERGE_VIEW = 1395;
    const ER_CANNOT_USER = 1396;
    const ER_XAER_NOTA = 1397;
    const ER_XAER_INVAL = 1398;
    const ER_XAER_RMFAIL = 1399;
    const ER_XAER_OUTSIDE = 1400;
    const ER_XAER_RMERR = 1401;
    const ER_XA_RBROLLBACK = 1402;
    const ER_NONEXISTING_PROC_GRANT = 1403;
    const ER_PROC_AUTO_GRANT_FAIL = 1404;
    const ER_PROC_AUTO_REVOKE_FAIL = 1405;
    const ER_DATA_TOO_LONG = 1406;
    const ER_SP_BAD_SQLSTATE = 1407;
    const ER_STARTUP = 1408;
    const ER_LOAD_FROM_FIXED_SIZE_ROWS_TO_VAR = 1409;
    const ER_CANT_CREATE_USER_WITH_GRANT = 1410;
    const ER_WRONG_VALUE_FOR_TYPE = 1411;
    const ER_TABLE_DEF_CHANGED = 1412;
    const ER_SP_DUP_HANDLER = 1413;
    const ER_SP_NOT_VAR_ARG = 1414;
    const ER_SP_NO_RETSET = 1415;
    const ER_CANT_CREATE_GEOMETRY_OBJECT = 1416;
    const ER_FAILED_ROUTINE_BREAK_BINLOG = 1417;
    const ER_BINLOG_UNSAFE_ROUTINE = 1418;
    const ER_BINLOG_CREATE_ROUTINE_NEED_SUPER = 1419;
    const ER_EXEC_STMT_WITH_OPEN_CURSOR = 1420;
    const ER_STMT_HAS_NO_OPEN_CURSOR = 1421;
    const ER_COMMIT_NOT_ALLOWED_IN_SF_OR_TRG = 1422;
    const ER_NO_DEFAULT_FOR_VIEW_FIELD = 1423;
    const ER_SP_NO_RECURSION = 1424;
    const ER_TOO_BIG_SCALE = 1425;
    const ER_TOO_BIG_PRECISION = 1426;
    const ER_M_BIGGER_THAN_D = 1427;
    const ER_WRONG_LOCK_OF_SYSTEM_TABLE = 1428;
    const ER_CONNECT_TO_FOREIGN_DATA_SOURCE = 1429;
    const ER_QUERY_ON_FOREIGN_DATA_SOURCE = 1430;
    const ER_FOREIGN_DATA_SOURCE_DOESNT_EXIST = 1431;
    const ER_FOREIGN_DATA_STRING_INVALID_CANT_CREATE = 1432;
    const ER_FOREIGN_DATA_STRING_INVALID = 1433;
    const ER_CANT_CREATE_FEDERATED_TABLE = 1434;
    const ER_TRG_IN_WRONG_SCHEMA = 1435;
    const ER_STACK_OVERRUN_NEED_MORE = 1436;
    const ER_TOO_LONG_BODY = 1437;
    const ER_WARN_CANT_DROP_DEFAULT_KEYCACHE = 1438;
    const ER_TOO_BIG_DISPLAYWIDTH = 1439;
    const ER_XAER_DUPID = 1440;
    const ER_DATETIME_FUNCTION_OVERFLOW = 1441;
    const ER_CANT_UPDATE_USED_TABLE_IN_SF_OR_TRG = 1442;
    const ER_VIEW_PREVENT_UPDATE = 1443;
    const ER_PS_NO_RECURSION = 1444;
    const ER_SP_CANT_SET_AUTOCOMMIT = 1445;
    const ER_MALFORMED_DEFINER = 1446;
    const ER_VIEW_FRM_NO_USER = 1447;
    const ER_VIEW_OTHER_USER = 1448;
    const ER_NO_SUCH_USER = 1449;
    const ER_FORBID_SCHEMA_CHANGE = 1450;
    const ER_ROW_IS_REFERENCED_2 = 1451;
    const ER_NO_REFERENCED_ROW_2 = 1452;
    const ER_SP_BAD_VAR_SHADOW = 1453;
    const ER_TRG_NO_DEFINER = 1454;
    const ER_OLD_FILE_FORMAT = 1455;
    const ER_SP_RECURSION_LIMIT = 1456;
    const ER_SP_PROC_TABLE_CORRUPT = 1457;
    const ER_SP_WRONG_NAME = 1458;
    const ER_TABLE_NEEDS_UPGRADE = 1459;
    const ER_SP_NO_AGGREGATE = 1460;
    const ER_MAX_PREPARED_STMT_COUNT_REACHED = 1461;
    const ER_VIEW_RECURSIVE = 1462;
    const ER_NON_GROUPING_FIELD_USED = 1463;
    const ER_TABLE_CANT_HANDLE_SPKEYS = 1464;
    const ER_NO_TRIGGERS_ON_SYSTEM_SCHEMA = 1465;
    const ER_REMOVED_SPACES = 1466;
    const ER_AUTOINC_READ_FAILED = 1467;
    const ER_USERNAME = 1468;
    const ER_HOSTNAME = 1469;
    const ER_WRONG_STRING_LENGTH = 1470;
    const ER_NON_INSERTABLE_TABLE = 1471;
    const ER_ADMIN_WRONG_MRG_TABLE = 1472;
    const ER_TOO_HIGH_LEVEL_OF_NESTING_FOR_SELECT = 1473;
    const ER_NAME_BECOMES_EMPTY = 1474;
    const ER_AMBIGUOUS_FIELD_TERM = 1475;
    const ER_FOREIGN_SERVER_EXISTS = 1476;
    const ER_FOREIGN_SERVER_DOESNT_EXIST = 1477;
    const ER_ILLEGAL_HA_CREATE_OPTION = 1478;
    const ER_PARTITION_REQUIRES_VALUES_ERROR = 1479;
    const ER_PARTITION_WRONG_VALUES_ERROR = 1480;
    const ER_PARTITION_MAXVALUE_ERROR = 1481;
    const ER_PARTITION_SUBPARTITION_ERROR = 1482;
    const ER_PARTITION_SUBPART_MIX_ERROR = 1483;
    const ER_PARTITION_WRONG_NO_PART_ERROR = 1484;
    const ER_PARTITION_WRONG_NO_SUBPART_ERROR = 1485;
    const ER_WRONG_EXPR_IN_PARTITION_FUNC_ERROR = 1486;
    const ER_NO_CONST_EXPR_IN_RANGE_OR_LIST_ERROR = 1487;
    const ER_FIELD_NOT_FOUND_PART_ERROR = 1488;
    const ER_LIST_OF_FIELDS_ONLY_IN_HASH_ERROR = 1489;
    const ER_INCONSISTENT_PARTITION_INFO_ERROR = 1490;
    const ER_PARTITION_FUNC_NOT_ALLOWED_ERROR = 1491;
    const ER_PARTITIONS_MUST_BE_DEFINED_ERROR = 1492;
    const ER_RANGE_NOT_INCREASING_ERROR = 1493;
    const ER_INCONSISTENT_TYPE_OF_FUNCTIONS_ERROR = 1494;
    const ER_MULTIPLE_DEF_CONST_IN_LIST_PART_ERROR = 1495;
    const ER_PARTITION_ENTRY_ERROR = 1496;
    const ER_MIX_HANDLER_ERROR = 1497;
    const ER_PARTITION_NOT_DEFINED_ERROR = 1498;
    const ER_TOO_MANY_PARTITIONS_ERROR = 1499;
    const ER_SUBPARTITION_ERROR = 1500;
    const ER_CANT_CREATE_HANDLER_FILE = 1501;
    const ER_BLOB_FIELD_IN_PART_FUNC_ERROR = 1502;
    const ER_UNIQUE_KEY_NEED_ALL_FIELDS_IN_PF = 1503;
    const ER_NO_PARTS_ERROR = 1504;
    const ER_PARTITION_MGMT_ON_NONPARTITIONED = 1505;
    const ER_FOREIGN_KEY_ON_PARTITIONED = 1506;
    const ER_DROP_PARTITION_NON_EXISTENT = 1507;
    const ER_DROP_LAST_PARTITION = 1508;
    const ER_COALESCE_ONLY_ON_HASH_PARTITION = 1509;
    const ER_REORG_HASH_ONLY_ON_SAME_NO = 1510;
    const ER_REORG_NO_PARAM_ERROR = 1511;
    const ER_ONLY_ON_RANGE_LIST_PARTITION = 1512;
    const ER_ADD_PARTITION_SUBPART_ERROR = 1513;
    const ER_ADD_PARTITION_NO_NEW_PARTITION = 1514;
    const ER_COALESCE_PARTITION_NO_PARTITION = 1515;
    const ER_REORG_PARTITION_NOT_EXIST = 1516;
    const ER_SAME_NAME_PARTITION = 1517;
    const ER_NO_BINLOG_ERROR = 1518;
    const ER_CONSECUTIVE_REORG_PARTITIONS = 1519;
    const ER_REORG_OUTSIDE_RANGE = 1520;
    const ER_PARTITION_FUNCTION_FAILURE = 1521;
    const ER_PART_STATE_ERROR = 1522;
    const ER_LIMITED_PART_RANGE = 1523;
    const ER_PLUGIN_IS_NOT_LOADED = 1524;
    const ER_WRONG_VALUE = 1525;
    const ER_NO_PARTITION_FOR_GIVEN_VALUE = 1526;
    const ER_FILEGROUP_OPTION_ONLY_ONCE = 1527;
    const ER_CREATE_FILEGROUP_FAILED = 1528;
    const ER_DROP_FILEGROUP_FAILED = 1529;
    const ER_TABLESPACE_AUTO_EXTEND_ERROR = 1530;
    const ER_WRONG_SIZE_NUMBER = 1531;
    const ER_SIZE_OVERFLOW_ERROR = 1532;
    const ER_ALTER_FILEGROUP_FAILED = 1533;
    const ER_BINLOG_ROW_LOGGING_FAILED = 1534;
    const ER_BINLOG_ROW_WRONG_TABLE_DEF = 1535;
    const ER_BINLOG_ROW_RBR_TO_SBR = 1536;
    const ER_EVENT_ALREADY_EXISTS = 1537;
    const ER_EVENT_STORE_FAILED = 1538;
    const ER_EVENT_DOES_NOT_EXIST = 1539;
    const ER_EVENT_CANT_ALTER = 1540;
    const ER_EVENT_DROP_FAILED = 1541;
    const ER_EVENT_INTERVAL_NOT_POSITIVE_OR_TOO_BIG = 1542;
    const ER_EVENT_ENDS_BEFORE_STARTS = 1543;
    const ER_EVENT_EXEC_TIME_IN_THE_PAST = 1544;
    const ER_EVENT_OPEN_TABLE_FAILED = 1545;
    const ER_EVENT_NEITHER_M_EXPR_NOR_M_AT = 1546;
    const ER_OBSOLETE_COL_COUNT_DOESNT_MATCH_CORRUPTED = 1547;
    const ER_OBSOLETE_CANNOT_LOAD_FROM_TABLE = 1548;
    const ER_EVENT_CANNOT_DELETE = 1549;
    const ER_EVENT_COMPILE_ERROR = 1550;
    const ER_EVENT_SAME_NAME = 1551;
    const ER_EVENT_DATA_TOO_LONG = 1552;
    const ER_DROP_INDEX_FK = 1553;
    const ER_WARN_DEPRECATED_SYNTAX_WITH_VER = 1554;
    const ER_CANT_WRITE_LOCK_LOG_TABLE = 1555;
    const ER_CANT_LOCK_LOG_TABLE = 1556;
    const ER_FOREIGN_DUPLICATE_KEY_OLD_UNUSED = 1557;
    const ER_COL_COUNT_DOESNT_MATCH_PLEASE_UPDATE = 1558;
    const ER_TEMP_TABLE_PREVENTS_SWITCH_OUT_OF_RBR = 1559;
    const ER_STORED_FUNCTION_PREVENTS_SWITCH_BINLOG_FORMAT = 1560;
    const ER_NDB_CANT_SWITCH_BINLOG_FORMAT = 1561;
    const ER_PARTITION_NO_TEMPORARY = 1562;
    const ER_PARTITION_CONST_DOMAIN_ERROR = 1563;
    const ER_PARTITION_FUNCTION_IS_NOT_ALLOWED = 1564;
    const ER_DDL_LOG_ERROR = 1565;
    const ER_NULL_IN_VALUES_LESS_THAN = 1566;
    const ER_WRONG_PARTITION_NAME = 1567;
    const ER_CANT_CHANGE_TX_CHARACTERISTICS = 1568;
    const ER_DUP_ENTRY_AUTOINCREMENT_CASE = 1569;
    const ER_EVENT_MODIFY_QUEUE_ERROR = 1570;
    const ER_EVENT_SET_VAR_ERROR = 1571;
    const ER_PARTITION_MERGE_ERROR = 1572;
    const ER_CANT_ACTIVATE_LOG = 1573;
    const ER_RBR_NOT_AVAILABLE = 1574;
    const ER_BASE64_DECODE_ERROR = 1575;
    const ER_EVENT_RECURSION_FORBIDDEN = 1576;
    const ER_EVENTS_DB_ERROR = 1577;
    const ER_ONLY_INTEGERS_ALLOWED = 1578;
    const ER_UNSUPORTED_LOG_ENGINE = 1579;
    const ER_BAD_LOG_STATEMENT = 1580;
    const ER_CANT_RENAME_LOG_TABLE = 1581;
    const ER_WRONG_PARAMCOUNT_TO_NATIVE_FCT = 1582;
    const ER_WRONG_PARAMETERS_TO_NATIVE_FCT = 1583;
    const ER_WRONG_PARAMETERS_TO_STORED_FCT = 1584;
    const ER_NATIVE_FCT_NAME_COLLISION = 1585;
    const ER_DUP_ENTRY_WITH_KEY_NAME = 1586;
    const ER_BINLOG_PURGE_EMFILE = 1587;
    const ER_EVENT_CANNOT_CREATE_IN_THE_PAST = 1588;
    const ER_EVENT_CANNOT_ALTER_IN_THE_PAST = 1589;
    const ER_SLAVE_INCIDENT = 1590;
    const ER_NO_PARTITION_FOR_GIVEN_VALUE_SILENT = 1591;
    const ER_BINLOG_UNSAFE_STATEMENT = 1592;
    const ER_SLAVE_FATAL_ERROR = 1593;
    const ER_SLAVE_RELAY_LOG_READ_FAILURE = 1594;
    const ER_SLAVE_RELAY_LOG_WRITE_FAILURE = 1595;
    const ER_SLAVE_CREATE_EVENT_FAILURE = 1596;
    const ER_SLAVE_MASTER_COM_FAILURE = 1597;
    const ER_BINLOG_LOGGING_IMPOSSIBLE = 1598;
    const ER_VIEW_NO_CREATION_CTX = 1599;
    const ER_VIEW_INVALID_CREATION_CTX = 1600;
    const ER_SR_INVALID_CREATION_CTX = 1601;
    const ER_TRG_CORRUPTED_FILE = 1602;
    const ER_TRG_NO_CREATION_CTX = 1603;
    const ER_TRG_INVALID_CREATION_CTX = 1604;
    const ER_EVENT_INVALID_CREATION_CTX = 1605;
    const ER_TRG_CANT_OPEN_TABLE = 1606;
    const ER_CANT_CREATE_SROUTINE = 1607;
    const ER_NEVER_USED = 1608;
    const ER_NO_FORMAT_DESCRIPTION_EVENT_BEFORE_BINLOG_STATEMENT = 1609;
    const ER_SLAVE_CORRUPT_EVENT = 1610;
    const ER_LOAD_DATA_INVALID_COLUMN = 1611;
    const ER_LOG_PURGE_NO_FILE = 1612;
    const ER_XA_RBTIMEOUT = 1613;
    const ER_XA_RBDEADLOCK = 1614;
    const ER_NEED_REPREPARE = 1615;
    const ER_DELAYED_NOT_SUPPORTED = 1616;
    const WARN_NO_MASTER_INFO = 1617;
    const WARN_OPTION_IGNORED = 1618;
    const WARN_PLUGIN_DELETE_BUILTIN = 1619;
    const ER_PLUGIN_DELETE_BUILTIN = 1619;
    const WARN_PLUGIN_BUSY = 1620;
    const ER_VARIABLE_IS_READONLY = 1621;
    const ER_WARN_ENGINE_TRANSACTION_ROLLBACK = 1622;
    const ER_SLAVE_HEARTBEAT_FAILURE = 1623;
    const ER_SLAVE_HEARTBEAT_VALUE_OUT_OF_RANGE = 1624;
    const ER_NDB_REPLICATION_SCHEMA_ERROR = 1625;
    const ER_CONFLICT_FN_PARSE_ERROR = 1626;
    const ER_EXCEPTIONS_WRITE_ERROR = 1627;
    const ER_TOO_LONG_TABLE_COMMENT = 1628;
    const ER_TOO_LONG_FIELD_COMMENT = 1629;
    const ER_FUNC_INEXISTENT_NAME_COLLISION = 1630;
    const ER_DATABASE_NAME = 1631;
    const ER_TABLE_NAME = 1632;
    const ER_PARTITION_NAME = 1633;
    const ER_SUBPARTITION_NAME = 1634;
    const ER_TEMPORARY_NAME = 1635;
    const ER_RENAMED_NAME = 1636;
    const ER_TOO_MANY_CONCURRENT_TRXS = 1637;
    const WARN_NON_ASCII_SEPARATOR_NOT_IMPLEMENTED = 1638;
    const ER_DEBUG_SYNC_TIMEOUT = 1639;
    const ER_DEBUG_SYNC_HIT_LIMIT = 1640;
    const ER_DUP_SIGNAL_SET = 1641;
    const ER_SIGNAL_WARN = 1642;
    const ER_SIGNAL_NOT_FOUND = 1643;
    const ER_SIGNAL_EXCEPTION = 1644;
    const ER_RESIGNAL_WITHOUT_ACTIVE_HANDLER = 1645;
    const ER_SIGNAL_BAD_CONDITION_TYPE = 1646;
    const WARN_COND_ITEM_TRUNCATED = 1647;
    const ER_COND_ITEM_TOO_LONG = 1648;
    const ER_UNKNOWN_LOCALE = 1649;
    const ER_SLAVE_IGNORE_SERVER_IDS = 1650;
    const ER_QUERY_CACHE_DISABLED = 1651;
    const ER_SAME_NAME_PARTITION_FIELD = 1652;
    const ER_PARTITION_COLUMN_LIST_ERROR = 1653;
    const ER_WRONG_TYPE_COLUMN_VALUE_ERROR = 1654;
    const ER_TOO_MANY_PARTITION_FUNC_FIELDS_ERROR = 1655;
    const ER_MAXVALUE_IN_VALUES_IN = 1656;
    const ER_TOO_MANY_VALUES_ERROR = 1657;
    const ER_ROW_SINGLE_PARTITION_FIELD_ERROR = 1658;
    const ER_FIELD_TYPE_NOT_ALLOWED_AS_PARTITION_FIELD = 1659;
    const ER_PARTITION_FIELDS_TOO_LONG = 1660;
    const ER_BINLOG_ROW_ENGINE_AND_STMT_ENGINE = 1661;
    const ER_BINLOG_ROW_MODE_AND_STMT_ENGINE = 1662;
    const ER_BINLOG_UNSAFE_AND_STMT_ENGINE = 1663;
    const ER_BINLOG_ROW_INJECTION_AND_STMT_ENGINE = 1664;
    const ER_BINLOG_STMT_MODE_AND_ROW_ENGINE = 1665;
    const ER_BINLOG_ROW_INJECTION_AND_STMT_MODE = 1666;
    const ER_BINLOG_MULTIPLE_ENGINES_AND_SELF_LOGGING_ENGINE = 1667;
    const ER_BINLOG_UNSAFE_LIMIT = 1668;
    const ER_UNUSED4 = 1669;
    const ER_BINLOG_UNSAFE_SYSTEM_TABLE = 1670;
    const ER_BINLOG_UNSAFE_AUTOINC_COLUMNS = 1671;
    const ER_BINLOG_UNSAFE_UDF = 1672;
    const ER_BINLOG_UNSAFE_SYSTEM_VARIABLE = 1673;
    const ER_BINLOG_UNSAFE_SYSTEM_FUNCTION = 1674;
    const ER_BINLOG_UNSAFE_NONTRANS_AFTER_TRANS = 1675;
    const ER_MESSAGE_AND_STATEMENT = 1676;
    const ER_SLAVE_CONVERSION_FAILED = 1677;
    const ER_SLAVE_CANT_CREATE_CONVERSION = 1678;
    const ER_INSIDE_TRANSACTION_PREVENTS_SWITCH_BINLOG_FORMAT = 1679;
    const ER_PATH_LENGTH = 1680;
    const ER_WARN_DEPRECATED_SYNTAX_NO_REPLACEMENT = 1681;
    const ER_WRONG_NATIVE_TABLE_STRUCTURE = 1682;
    const ER_WRONG_PERFSCHEMA_USAGE = 1683;
    const ER_WARN_I_S_SKIPPED_TABLE = 1684;
    const ER_INSIDE_TRANSACTION_PREVENTS_SWITCH_BINLOG_DIRECT = 1685;
    const ER_STORED_FUNCTION_PREVENTS_SWITCH_BINLOG_DIRECT = 1686;
    const ER_SPATIAL_MUST_HAVE_GEOM_COL = 1687;
    const ER_TOO_LONG_INDEX_COMMENT = 1688;
    const ER_LOCK_ABORTED = 1689;
    const ER_DATA_OUT_OF_RANGE = 1690;
    const ER_WRONG_SPVAR_TYPE_IN_LIMIT = 1691;
    const ER_BINLOG_UNSAFE_MULTIPLE_ENGINES_AND_SELF_LOGGING_ENGINE = 1692;
    const ER_BINLOG_UNSAFE_MIXED_STATEMENT = 1693;
    const ER_INSIDE_TRANSACTION_PREVENTS_SWITCH_SQL_LOG_BIN = 1694;
    const ER_STORED_FUNCTION_PREVENTS_SWITCH_SQL_LOG_BIN = 1695;
    const ER_FAILED_READ_FROM_PAR_FILE = 1696;
    const ER_VALUES_IS_NOT_INT_TYPE_ERROR = 1697;
    const ER_ACCESS_DENIED_NO_PASSWORD_ERROR = 1698;
    const ER_SET_PASSWORD_AUTH_PLUGIN = 1699;
    const ER_GRANT_PLUGIN_USER_EXISTS = 1700;
    const ER_TRUNCATE_ILLEGAL_FK = 1701;
    const ER_PLUGIN_IS_PERMANENT = 1702;
    const ER_SLAVE_HEARTBEAT_VALUE_OUT_OF_RANGE_MIN = 1703;
    const ER_SLAVE_HEARTBEAT_VALUE_OUT_OF_RANGE_MAX = 1704;
    const ER_STMT_CACHE_FULL = 1705;
    const ER_MULTI_UPDATE_KEY_CONFLICT = 1706;
    const ER_TABLE_NEEDS_REBUILD = 1707;
    const WARN_OPTION_BELOW_LIMIT = 1708;
    const ER_INDEX_COLUMN_TOO_LONG = 1709;
    const ER_ERROR_IN_TRIGGER_BODY = 1710;
    const ER_ERROR_IN_UNKNOWN_TRIGGER_BODY = 1711;
    const ER_INDEX_CORRUPT = 1712;
    const ER_UNDO_RECORD_TOO_BIG = 1713;
    const ER_BINLOG_UNSAFE_INSERT_IGNORE_SELECT = 1714;
    const ER_BINLOG_UNSAFE_INSERT_SELECT_UPDATE = 1715;
    const ER_BINLOG_UNSAFE_REPLACE_SELECT = 1716;
    const ER_BINLOG_UNSAFE_CREATE_IGNORE_SELECT = 1717;
    const ER_BINLOG_UNSAFE_CREATE_REPLACE_SELECT = 1718;
    const ER_BINLOG_UNSAFE_UPDATE_IGNORE = 1719;
    const ER_PLUGIN_NO_UNINSTALL = 1720;
    const ER_PLUGIN_NO_INSTALL = 1721;
    const ER_BINLOG_UNSAFE_WRITE_AUTOINC_SELECT = 1722;
    const ER_BINLOG_UNSAFE_CREATE_SELECT_AUTOINC = 1723;
    const ER_BINLOG_UNSAFE_INSERT_TWO_KEYS = 1724;
    const ER_TABLE_IN_FK_CHECK = 1725;
    const ER_UNSUPPORTED_ENGINE = 1726;
    const ER_BINLOG_UNSAFE_AUTOINC_NOT_FIRST = 1727;
    const ER_CANNOT_LOAD_FROM_TABLE_V2 = 1728;
    const ER_MASTER_DELAY_VALUE_OUT_OF_RANGE = 1729;
    const ER_ONLY_FD_AND_RBR_EVENTS_ALLOWED_IN_BINLOG_STATEMENT = 1730;
    const ER_PARTITION_EXCHANGE_DIFFERENT_OPTION = 1731;
    const ER_PARTITION_EXCHANGE_PART_TABLE = 1732;
    const ER_PARTITION_EXCHANGE_TEMP_TABLE = 1733;
    const ER_PARTITION_INSTEAD_OF_SUBPARTITION = 1734;
    const ER_UNKNOWN_PARTITION = 1735;
    const ER_TABLES_DIFFERENT_METADATA = 1736;
    const ER_ROW_DOES_NOT_MATCH_PARTITION = 1737;
    const ER_BINLOG_CACHE_SIZE_GREATER_THAN_MAX = 1738;
    const ER_WARN_INDEX_NOT_APPLICABLE = 1739;
    const ER_PARTITION_EXCHANGE_FOREIGN_KEY = 1740;
    const ER_NO_SUCH_KEY_VALUE = 1741;
    const ER_RPL_INFO_DATA_TOO_LONG = 1742;
    const ER_NETWORK_READ_EVENT_CHECKSUM_FAILURE = 1743;
    const ER_BINLOG_READ_EVENT_CHECKSUM_FAILURE = 1744;
    const ER_BINLOG_STMT_CACHE_SIZE_GREATER_THAN_MAX = 1745;
    const ER_CANT_UPDATE_TABLE_IN_CREATE_TABLE_SELECT = 1746;
    const ER_PARTITION_CLAUSE_ON_NONPARTITIONED = 1747;
    const ER_ROW_DOES_NOT_MATCH_GIVEN_PARTITION_SET = 1748;
    const ER_NO_SUCH_PARTITION__UNUSED = 1749;
    const ER_CHANGE_RPL_INFO_REPOSITORY_FAILURE = 1750;
    const ER_WARNING_NOT_COMPLETE_ROLLBACK_WITH_CREATED_TEMP_TABLE = 1751;
    const ER_WARNING_NOT_COMPLETE_ROLLBACK_WITH_DROPPED_TEMP_TABLE = 1752;
    const ER_MTS_FEATURE_IS_NOT_SUPPORTED = 1753;
    const ER_MTS_UPDATED_DBS_GREATER_MAX = 1754;
    const ER_MTS_CANT_PARALLEL = 1755;
    const ER_MTS_INCONSISTENT_DATA = 1756;
    const ER_FULLTEXT_NOT_SUPPORTED_WITH_PARTITIONING = 1757;
    const ER_DA_INVALID_CONDITION_NUMBER = 1758;
    const ER_INSECURE_PLAIN_TEXT = 1759;
    const ER_INSECURE_CHANGE_MASTER = 1760;
    const ER_FOREIGN_DUPLICATE_KEY_WITH_CHILD_INFO = 1761;
    const ER_FOREIGN_DUPLICATE_KEY_WITHOUT_CHILD_INFO = 1762;
    const ER_SQLTHREAD_WITH_SECURE_SLAVE = 1763;
    const ER_TABLE_HAS_NO_FT = 1764;
    const ER_VARIABLE_NOT_SETTABLE_IN_SF_OR_TRIGGER = 1765;
    const ER_VARIABLE_NOT_SETTABLE_IN_TRANSACTION = 1766;
    const ER_GTID_NEXT_IS_NOT_IN_GTID_NEXT_LIST = 1767;
    const ER_CANT_CHANGE_GTID_NEXT_IN_TRANSACTION_WHEN_GTID_NEXT_LIST_IS_NULL = 1768;
    const ER_SET_STATEMENT_CANNOT_INVOKE_FUNCTION = 1769;
    const ER_GTID_NEXT_CANT_BE_AUTOMATIC_IF_GTID_NEXT_LIST_IS_NON_NULL = 1770;
    const ER_SKIPPING_LOGGED_TRANSACTION = 1771;
    const ER_MALFORMED_GTID_SET_SPECIFICATION = 1772;
    const ER_MALFORMED_GTID_SET_ENCODING = 1773;
    const ER_MALFORMED_GTID_SPECIFICATION = 1774;
    const ER_GNO_EXHAUSTED = 1775;
    const ER_BAD_SLAVE_AUTO_POSITION = 1776;
    const ER_AUTO_POSITION_REQUIRES_GTID_MODE_ON = 1777;
    const ER_CANT_DO_IMPLICIT_COMMIT_IN_TRX_WHEN_GTID_NEXT_IS_SET = 1778;
    const ER_GTID_MODE_2_OR_3_REQUIRES_ENFORCE_GTID_CONSISTENCY_ON = 1779;
    const ER_GTID_MODE_REQUIRES_BINLOG = 1780;
    const ER_CANT_SET_GTID_NEXT_TO_GTID_WHEN_GTID_MODE_IS_OFF = 1781;
    const ER_CANT_SET_GTID_NEXT_TO_ANONYMOUS_WHEN_GTID_MODE_IS_ON = 1782;
    const ER_CANT_SET_GTID_NEXT_LIST_TO_NON_NULL_WHEN_GTID_MODE_IS_OFF = 1783;
    const ER_FOUND_GTID_EVENT_WHEN_GTID_MODE_IS_OFF = 1784;
    const ER_GTID_UNSAFE_NON_TRANSACTIONAL_TABLE = 1785;
    const ER_GTID_UNSAFE_CREATE_SELECT = 1786;
    const ER_GTID_UNSAFE_CREATE_DROP_TEMPORARY_TABLE_IN_TRANSACTION = 1787;
    const ER_GTID_MODE_CAN_ONLY_CHANGE_ONE_STEP_AT_A_TIME = 1788;
    const ER_MASTER_HAS_PURGED_REQUIRED_GTIDS = 1789;
    const ER_CANT_SET_GTID_NEXT_WHEN_OWNING_GTID = 1790;
    const ER_UNKNOWN_EXPLAIN_FORMAT = 1791;
    const ER_CANT_EXECUTE_IN_READ_ONLY_TRANSACTION = 1792;
    const ER_TOO_LONG_TABLE_PARTITION_COMMENT = 1793;
    const ER_SLAVE_CONFIGURATION = 1794;
    const ER_INNODB_FT_LIMIT = 1795;
    const ER_INNODB_NO_FT_TEMP_TABLE = 1796;
    const ER_INNODB_FT_WRONG_DOCID_COLUMN = 1797;
    const ER_INNODB_FT_WRONG_DOCID_INDEX = 1798;
    const ER_INNODB_ONLINE_LOG_TOO_BIG = 1799;
    const ER_UNKNOWN_ALTER_ALGORITHM = 1800;
    const ER_UNKNOWN_ALTER_LOCK = 1801;
    const ER_MTS_CHANGE_MASTER_CANT_RUN_WITH_GAPS = 1802;
    const ER_MTS_RECOVERY_FAILURE = 1803;
    const ER_MTS_RESET_WORKERS = 1804;
    const ER_COL_COUNT_DOESNT_MATCH_CORRUPTED_V2 = 1805;
    const ER_SLAVE_SILENT_RETRY_TRANSACTION = 1806;
    const ER_DISCARD_FK_CHECKS_RUNNING = 1807;
    const ER_TABLE_SCHEMA_MISMATCH = 1808;
    const ER_TABLE_IN_SYSTEM_TABLESPACE = 1809;
    const ER_IO_READ_ERROR = 1810;
    const ER_IO_WRITE_ERROR = 1811;
    const ER_TABLESPACE_MISSING = 1812;
    const ER_TABLESPACE_EXISTS = 1813;
    const ER_TABLESPACE_DISCARDED = 1814;
    const ER_INTERNAL_ERROR = 1815;
    const ER_INNODB_IMPORT_ERROR = 1816;
    const ER_INNODB_INDEX_CORRUPT = 1817;
    const ER_INVALID_YEAR_COLUMN_LENGTH = 1818;
    const ER_NOT_VALID_PASSWORD = 1819;
    const ER_MUST_CHANGE_PASSWORD = 1820;
    const ER_FK_NO_INDEX_CHILD = 1821;
    const ER_FK_NO_INDEX_PARENT = 1822;
    const ER_FK_FAIL_ADD_SYSTEM = 1823;
    const ER_FK_CANNOT_OPEN_PARENT = 1824;
    const ER_FK_INCORRECT_OPTION = 1825;
    const ER_FK_DUP_NAME = 1826;
    const ER_PASSWORD_FORMAT = 1827;
    const ER_FK_COLUMN_CANNOT_DROP = 1828;
    const ER_FK_COLUMN_CANNOT_DROP_CHILD = 1829;
    const ER_FK_COLUMN_NOT_NULL = 1830;
    const ER_DUP_INDEX = 1831;
    const ER_FK_COLUMN_CANNOT_CHANGE = 1832;
    const ER_FK_COLUMN_CANNOT_CHANGE_CHILD = 1833;
    const ER_FK_CANNOT_DELETE_PARENT = 1834;
    const ER_UNUSED5 = 1834;
    const ER_MALFORMED_PACKET = 1835;
    const ER_READ_ONLY_MODE = 1836;
    const ER_GTID_NEXT_TYPE_UNDEFINED_GROUP = 1837;
    const ER_VARIABLE_NOT_SETTABLE_IN_SP = 1838;
    const ER_CANT_SET_GTID_PURGED_WHEN_GTID_MODE_IS_OFF = 1839;
    const ER_CANT_SET_GTID_PURGED_WHEN_GTID_EXECUTED_IS_NOT_EMPTY = 1840;
    const ER_CANT_SET_GTID_PURGED_WHEN_OWNED_GTIDS_IS_NOT_EMPTY = 1841;
    const ER_GTID_PURGED_WAS_CHANGED = 1842;
    const ER_GTID_EXECUTED_WAS_CHANGED = 1843;
    const ER_BINLOG_STMT_MODE_AND_NO_REPL_TABLES = 1844;
    const ER_ALTER_OPERATION_NOT_SUPPORTED = 1845;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON = 1846;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_COPY = 1847;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_PARTITION = 1848;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_FK_RENAME = 1849;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_COLUMN_TYPE = 1850;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_FK_CHECK = 1851;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_IGNORE = 1852;
    const ER_UNUSED6 = 1852;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_NOPK = 1853;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_AUTOINC = 1854;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_HIDDEN_FTS = 1855;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_CHANGE_FTS = 1856;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_FTS = 1857;
    const ER_SQL_SLAVE_SKIP_COUNTER_NOT_SETTABLE_IN_GTID_MODE = 1858;
    const ER_DUP_UNKNOWN_IN_INDEX = 1859;
    const ER_IDENT_CAUSES_TOO_LONG_PATH = 1860;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_NOT_NULL = 1861;
    const ER_MUST_CHANGE_PASSWORD_LOGIN = 1862;
    const ER_ROW_IN_WRONG_PARTITION = 1863;
    const ER_MTS_EVENT_BIGGER_PENDING_JOBS_SIZE_MAX = 1864;
    const ER_INNODB_NO_FT_USES_PARSER = 1865;
    const ER_BINLOG_LOGICAL_CORRUPTION = 1866;
    const ER_WARN_PURGE_LOG_IN_USE = 1867;
    const ER_WARN_PURGE_LOG_IS_ACTIVE = 1868;
    const ER_AUTO_INCREMENT_CONFLICT = 1869;
    const WARN_ON_BLOCKHOLE_IN_RBR = 1870;
    const ER_SLAVE_MI_INIT_REPOSITORY = 1871;
    const ER_SLAVE_RLI_INIT_REPOSITORY = 1872;
    const ER_ACCESS_DENIED_CHANGE_USER_ERROR = 1873;
    const ER_INNODB_READ_ONLY = 1874;
    const ER_STOP_SLAVE_SQL_THREAD_TIMEOUT = 1875;
    const ER_STOP_SLAVE_IO_THREAD_TIMEOUT = 1876;
    const ER_TABLE_CORRUPT = 1877;
    const ER_TEMP_FILE_WRITE_FAILURE = 1878;
    const ER_INNODB_FT_AUX_NOT_HEX_ID = 1879;
    const ER_OLD_TEMPORALS_UPGRADED = 1880;
    const ER_INNODB_FORCED_RECOVERY = 1881;
    const ER_AES_INVALID_IV = 1882;
    const ER_PLUGIN_CANNOT_BE_UNINSTALLED = 1883;
    const ER_GTID_UNSAFE_BINLOG_SPLITTABLE_STATEMENT_AND_GTID_GROUP = 1884;
    const ER_FILE_CORRUPT = 1885;
    const ER_ERROR_ON_MASTER = 1886;
    const ER_INCONSISTENT_ERROR = 1887;
    const ER_STORAGE_ENGINE_NOT_LOADED = 1888;
    const ER_GET_STACKED_DA_WITHOUT_ACTIVE_HANDLER = 1889;
    const ER_WARN_LEGACY_SYNTAX_CONVERTED = 1890;
    const ER_BINLOG_UNSAFE_FULLTEXT_PLUGIN = 1891;
    const ER_CANNOT_DISCARD_TEMPORARY_TABLE = 1892;
    const ER_FK_DEPTH_EXCEEDED = 1893;
    const ER_COL_COUNT_DOESNT_MATCH_PLEASE_UPDATE_V2 = 1894;
    const ER_WARN_TRIGGER_DOESNT_HAVE_CREATED = 1895;
    const ER_REFERENCED_TRG_DOES_NOT_EXIST = 1896;
    const ER_EXPLAIN_NOT_SUPPORTED = 1897;
    const ER_INVALID_FIELD_SIZE = 1898;
    const ER_MISSING_HA_CREATE_OPTION = 1899;
    const ER_ENGINE_OUT_OF_MEMORY = 1900;
    const ER_PASSWORD_EXPIRE_ANONYMOUS_USER = 1901;
    const ER_SLAVE_SQL_THREAD_MUST_STOP = 1902;
    const ER_NO_FT_MATERIALIZED_SUBQUERY = 1903;
    const ER_INNODB_UNDO_LOG_FULL = 1904;
    const ER_INVALID_ARGUMENT_FOR_LOGARITHM = 1905;
    const ER_SLAVE_IO_THREAD_MUST_STOP = 1906;
    const ER_SLAVE_CHANNEL_IO_THREAD_MUST_STOP = 1906;
    const ER_WARN_OPEN_TEMP_TABLES_MUST_BE_ZERO = 1907;
    const ER_WARN_ONLY_MASTER_LOG_FILE_NO_POS = 1908;
    const ER_QUERY_TIMEOUT = 1909;
    const ER_NON_RO_SELECT_DISABLE_TIMER = 1910;
    const ER_DUP_LIST_ENTRY = 1911;
    const ER_SQL_MODE_NO_EFFECT = 1912;
    const ER_AGGREGATE_ORDER_FOR_UNION = 1913;
    const ER_AGGREGATE_ORDER_NON_AGG_QUERY = 1914;
    const ER_SLAVE_WORKER_STOPPED_PREVIOUS_THD_ERROR = 1915;
    const ER_DONT_SUPPORT_SLAVE_PRESERVE_COMMIT_ORDER = 1916;
    const ER_SERVER_OFFLINE_MODE = 1917;
    const ER_GIS_DIFFERENT_SRIDS = 1918;
    const ER_GIS_UNSUPPORTED_ARGUMENT = 1919;
    const ER_GIS_UNKNOWN_ERROR = 1920;
    const ER_GIS_UNKNOWN_EXCEPTION = 1921;
    const ER_GIS_INVALID_DATA = 1922;
    const ER_BOOST_GEOMETRY_EMPTY_INPUT_EXCEPTION = 1923;
    const ER_BOOST_GEOMETRY_CENTROID_EXCEPTION = 1924;
    const ER_BOOST_GEOMETRY_OVERLAY_INVALID_INPUT_EXCEPTION = 1925;
    const ER_BOOST_GEOMETRY_TURN_INFO_EXCEPTION = 1926;
    const ER_BOOST_GEOMETRY_SELF_INTERSECTION_POINT_EXCEPTION = 1927;
    const ER_BOOST_GEOMETRY_UNKNOWN_EXCEPTION = 1928;
    const ER_STD_BAD_ALLOC_ERROR = 1929;
    const ER_STD_DOMAIN_ERROR = 1930;
    const ER_STD_LENGTH_ERROR = 1931;
    const ER_STD_INVALID_ARGUMENT = 1932;
    const ER_STD_OUT_OF_RANGE_ERROR = 1933;
    const ER_STD_OVERFLOW_ERROR = 1934;
    const ER_STD_RANGE_ERROR = 1935;
    const ER_STD_UNDERFLOW_ERROR = 1936;
    const ER_STD_LOGIC_ERROR = 1937;
    const ER_STD_RUNTIME_ERROR = 1938;
    const ER_STD_UNKNOWN_EXCEPTION = 1939;
    const ER_GIS_DATA_WRONG_ENDIANESS = 1940;
    const ER_CHANGE_MASTER_PASSWORD_LENGTH = 1941;
    const ER_USER_LOCK_WRONG_NAME = 1942;
    const ER_USER_LOCK_DEADLOCK = 1943;
    const ER_REPLACE_INACCESSIBLE_ROWS = 1944;
    const ER_ALTER_OPERATION_NOT_SUPPORTED_REASON_GIS = 1945;
    const ER_ILLEGAL_USER_VAR = 1946;
    const ER_GTID_MODE_OFF = 1947;
    const ER_UNSUPPORTED_BY_REPLICATION_THREAD = 1948;
    const ER_INCORRECT_TYPE = 1949;
    const ER_FIELD_IN_ORDER_NOT_SELECT = 1950;
    const ER_AGGREGATE_IN_ORDER_NOT_SELECT = 1951;
    const ER_INVALID_RPL_WILD_TABLE_FILTER_PATTERN = 1952;
    const ER_NET_OK_PACKET_TOO_LARGE = 1953;
    const ER_INVALID_JSON_DATA = 1954;
    const ER_INVALID_GEOJSON_MISSING_MEMBER = 1955;
    const ER_INVALID_GEOJSON_WRONG_TYPE = 1956;
    const ER_INVALID_GEOJSON_UNSPECIFIED = 1957;
    const ER_DIMENSION_UNSUPPORTED = 1958;
    const ER_SLAVE_CHANNEL_DOES_NOT_EXIST = 1959;
    const ER_SLAVE_MULTIPLE_CHANNELS_HOST_PORT = 1960;
    const ER_SLAVE_CHANNEL_NAME_INVALID_OR_TOO_LONG = 1961;
    const ER_SLAVE_NEW_CHANNEL_WRONG_REPOSITORY = 1962;
    const ER_SLAVE_CHANNEL_DELETE = 1963;
    const ER_SLAVE_MULTIPLE_CHANNELS_CMD = 1964;
    const ER_SLAVE_MAX_CHANNELS_EXCEEDED = 1965;
    const ER_SLAVE_CHANNEL_MUST_STOP = 1966;
    const ER_SLAVE_CHANNEL_NOT_RUNNING = 1967;
    const ER_SLAVE_CHANNEL_WAS_RUNNING = 1968;
    const ER_SLAVE_CHANNEL_WAS_NOT_RUNNING = 1969;
    const ER_SLAVE_CHANNEL_SQL_THREAD_MUST_STOP = 1970;
    const ER_SLAVE_CHANNEL_SQL_SKIP_COUNTER = 1971;
}