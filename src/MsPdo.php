<?php namespace PdoPlus;
use PDO;

/**
 * @method PdoPlusStatement prepare(string $statement, array $driver_options = array()) Prepares a statement for execution and returns a statement object
 */
class MsPdo extends PdoPlus{

    function __construct($host, $database_name, $username, $password, $options = null){
        if ($options === null) {
            $options = array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STATEMENT_CLASS    => array('PdoPlusStatement', array()),
            );
        }
        $dsn = "dblib:host=$host;dbname=$database_name";
        parent::__construct($dsn, $username, $password, $options);
    }

    public static function quote_identifier($identifier, $strict = false){
        return $strict
            ?'['.str_replace(']', ']]', $identifier).']'
            :implode('.', array_map(function ($id){
                return '['.str_replace(']', ']]', $id).']';
            }, explode('.', $identifier)));
    }

    public function select_all($table, $fields = '*', $limit = null){
        $sql = 'SELECT ';
        $field_str = static::build_columns($fields);
        if ($limit !== null) {
            $sql .= "TOP $limit ";
        }
        $table_str = $table;//static::quote_identifier($table);
        $sql .= "$field_str FROM $table_str";
        return $this->query($sql);
    }

    /**
     * Quotes a string for use in a query.
     *
     * @param mixed $value The value to be quoted.
     * @param null $paramtype
     * @return string Returns a quoted string that is theoretically safe to pass into an SQL statement.
     */
    public function quote($value, $paramtype = null){
        if (is_null($value)) {
            return 'NULL';
        } elseif (is_bool($value)) {
            return $value?'1':'0';
        } elseif (is_int($value) || is_float($value)) {
            return $value;
        }
        return parent::quote($value);
    }

    public function prepare_insert($table, $columns, $options = 0){
        $table_sql = static::quote_identifier($table);
        $column_sql = implode(', ', array_map(['static', 'quote_identifier'], $columns));
        $placeholder_arr = [];
        foreach ($columns as $col) {
            $placeholder_arr[] = ":$col";
        }
        $placeholder_sql = implode(', ', $placeholder_arr);
        $sql = 'INSERT ';//($options & static::INS_REPLACE) ? 'REPLACE ' :
//        if($options & static::INS_LOW_PRIORITY) $sql .= 'LOW_PRIORITY ';
//        elseif($options & static::INS_DELAYED) $sql .= 'DELAYED ';
//        elseif($options & static::INS_HIGH_PRIORITY) $sql .= 'HIGH_PRIORITY ';
//        if($options & static::INS_IGNORE) $sql .= 'IGNORE ';
        $sql .= "INTO $table_sql ($column_sql) VALUES ($placeholder_sql)";
        return $this->prepare($sql);
    }
}
