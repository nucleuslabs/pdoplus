<?php namespace PdoPlus;

use Exception;

class NoSuchTableException extends MyPdoException {
    /** @var string */
    protected $table;

    public function __construct($message = "", $table, $code = MyPdo::ER_NO_SUCH_TABLE, Exception $previous = null) {
        $this->table = $table;
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return string
     */
    public function getTable() {
        return $this->table;
    }
}