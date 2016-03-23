<?php namespace PdoPlus;


use Exception;

class AccessDeniedException extends MyPdoException {
    public function __construct($message = "", $code = MyPdo::ER_ACCESS_DENIED_ERROR, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }

}