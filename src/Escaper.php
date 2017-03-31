<?php namespace PdoPlus;

use _DbLiteral;
use DateTime;
use mysqli;
use PDO;

// TODO: this might actually make more sense if we make Escaper abstract (or an interface) and then extend it for Pdo, mysql, mysqli etc with a generic quote() function!

class Escaper {
    /** @var PDO|mysqli|resource Connection used for escaping values  */
    public $connection = null;

    /**
     * @param PDO|mysqli|resource $connection Connection used for escaping values
     */
    function __construct($connection=null) {
        $this->connection = $connection;
    }


    /**
     * Escapes special characters for use in a LIKE clause.
     * @param string $str
     * @return string
     */
    public function escapeLike($str) {
        return str_replace(['%', '_'], ['\\%', '\\_'], $str);
    }

    /**
     * @param mixed $value
     * @return string
     * @throws \Exception
     */
    public function quote($value) {
        if(is_null($value)) return 'NULL';
        elseif(is_bool($value)) return $value ? '1' : '0';
//        elseif($value instanceof _DbLiteral) return $value->data;
        elseif(is_int($value) || is_float($value) || $value instanceof RawSql) return (string)$value;
        elseif($value instanceof DateTime) return "'" . $value->format('Y-m-d H:i:s') . "'";
        elseif(is_array($value)) {
            if(self::isAssoc($value)) {
                $pairs = [];
                foreach($value as $k => $v) {
                    $pairs[] = self::escapeId($k) . '=' . self::quote($v);
                }
                return implode(', ', $pairs);
            }
            return '(' . implode(', ', array_map(__METHOD__, $value)) . ')';
        } elseif(is_string($value)) {
            if($this->connection instanceof PDO) return $this->connection->quote($value, PDO::PARAM_STR);
            if($this->connection instanceof mysqli) $this->connection->real_escape_string($value);
            if(self::isMySqlLink($this->connection)) return "'" . mysql_real_escape_string($value, $this->connection) . "'";
            if($this->connection === null) {
                // WARNING: this is not safe if NO_BACKSLASH_ESCAPES is enabled or if the server character set is one of big5, cp932, gb2312, bgk or sjis; see http://stackoverflow.com/a/12118602/65387 for details
                return "'" . str_replace(["'", '\\', "\0", "\t", "\n", "\r", "\x08", "\x1a"], ["''", '\\\\', '\\0', '\\t', '\\n', '\\r', '\\b', '\\Z'], $value) . "'";
            }
            throw new \Exception('conn', 'PDO|mysqli');
        }
        throw new \Exception('value', 'string');
    }

    /**
     * Tests if an object is a "mysql link".
     *
     * @param mixed $resource
     * @return bool
     */
    public static function isMySqlLink($resource) {
        return is_resource($resource) && get_resource_type($resource) === 'mysql link';
    }

    /**
     * Returns a "literal" or "raw" value which will not be escaped by Sql::quote
     *
     * @param string $str Raw value (should be valid SQL)
     * @return RawSql
     */
    public static function raw($str) {
        return new RawSql($str);
    }

    public static function bin($str) {
        return $str === null || $str === '' ? $str : new RawSql('0x' . bin2hex($str));
    }

    /**
     * Escape an identifier.
     *
     * @param string $id Identifier such as column or table name.
     * @param bool $forbidQualified If true, identifiers containing dots will be treated as a single unqualified identifier (e.g. `table.column`). If false, the $id string will be split into a qualified identifier (e.g. `table`.`column`).
     * @return mixed|string
     */
    public function escapeId($id, $forbidQualified = false) {
        if($id instanceof RawSql) return (string)$id;
        if(is_array($id)) {
            return implode(',', array_map(function ($x) use ($forbidQualified) {
                return $this->escapeId($x, $forbidQualified);
            }, $id));
        }
        $ret = '`' . str_replace('`', '``', $id) . '`';
        return $forbidQualified ? $ret : str_replace('.', '`.`', $ret);
    }

    public function format($query, $params = null) {
        if($params === null) {
            return $query;
        }

        $patt = '~
            [`\'"?:]
            (?:
                (?<=`) [^`\\\\]*+ (?s:\\\\.[^`\\\\]*|``[^`\\\\]*)*+ `
                (*SKIP) (*F)
              |
                (?<=\') [^\'\\\\]*+ (?s:\\\\.[^\'\\\\]*|\'\'[^\'\\\\]*)*+ \'
                (*SKIP) (*F)
              |
                (?<=") [^"\\\\]*+ (?s:\\\\.[^"\\\\]*|""[^"\\\\]*)*+ "
                (*SKIP) (*F)
              |
                (?<=\?) \??
              |
                (?<=:) :?
                ([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)
            )
        ~x';

        $result = preg_replace_callback($patt, function ($matches) use (&$params) {
            if($matches[0] === '?') {
                if(!$params) {
                    throw new \DomainException("Not enough params");
                }
                if(strlen($matches[0]) === 2) {
                    return $this->escapeId(array_shift($params));
                }
                return $this->quote(array_shift($params));
            } else {
                if(!array_key_exists($matches[1],$params)) {
                    throw new \DomainException("\"$matches[1]\" param not provided");
                }
                if(substr($matches[0],0,2) === '::') {
                    return $this->escapeId($params[$matches[1]]);
                }
                return $this->quote($params[$matches[1]]);
            }
        }, $query);

        if($result === null) {
            $errorCode = preg_last_error();
            if(isset(self::$pregErrors[$errorCode])) {
                $errorCode = self::$pregErrors[$errorCode];
            }
            throw new \Exception("preg error: $errorCode");
        }

        return $result;
    }

    private static $pregErrors = [
        PREG_NO_ERROR => 'PREG_NO_ERROR',
        PREG_INTERNAL_ERROR => 'PREG_INTERNAL_ERROR',
        PREG_BACKTRACK_LIMIT_ERROR => 'PREG_BACKTRACK_LIMIT_ERROR',
        PREG_RECURSION_LIMIT_ERROR => 'PREG_RECURSION_LIMIT_ERROR',
        PREG_BAD_UTF8_ERROR => 'PREG_BAD_UTF8_ERROR',
        PREG_BAD_UTF8_OFFSET_ERROR => 'PREG_BAD_UTF8_OFFSET_ERROR',
        6 => 'PREG_JIT_STACKLIMIT_ERROR',
    ];

    public static function datetime($timestamp = null) {
        if($timestamp === null) $timestamp = time();
        elseif(is_string($timestamp) && !is_numeric($timestamp)) $timestamp = strtotime($timestamp);
        return date('Y-m-d H:i:s', $timestamp);
    }

    public static function date($timestamp = null) {
        if($timestamp === null) $timestamp = time();
        elseif(is_string($timestamp) && !is_numeric($timestamp)) $timestamp = strtotime($timestamp);
        return date('Y-m-d', $timestamp);
    }

    /**
     * Determines if an array is "associative" (like a dictionary or hash map). True if at least one index is "out of place".
     *
     * @param array $arr
     * @return bool
     */
    private static function isAssoc(array $arr) {
        $i = 0;
        foreach($arr as $k => $v) {
            if($k !== $i) return true;
            ++$i;
        }
        return false;
    }

}