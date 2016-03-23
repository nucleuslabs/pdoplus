<?php namespace PdoPlus;

use Exception;

class CannotAddForeignKeyException extends MyPdoException {

    private $cafMessage;
    private $cafDetails;
    private $cafSql;

    public function __construct($message, $details, $sql, $code = MyPdo::ER_CANNOT_ADD_FOREIGN, Exception $previous = null) {
        $this->cafMessage = $message;
        $this->cafDetails = $details;
        $this->cafSql = $sql;
        $lines = [
            $message,
            "Details: ".(strlen($details) ? $details : "(insufficient privileges)"),
            "SQL: ".(strlen($sql) ? $sql : "(unknown)"),
        ];
        parent::__construct(implode("\n",$lines), $code, $previous);
    }
}