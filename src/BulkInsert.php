<?php namespace PdoPlus;

class BulkInsert {
    /** @var PdoPlus */
    protected $pdo;
    protected $table;
    protected $fields;
    protected $data;
    protected $batch_size;
    protected $stmt;
    protected $stmt_size;
    protected $row_count;
    protected $ignore;

    function __construct(PdoPlus $pdo, $table, $batch_size = null, $ignore = false) {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->fields = null;
        $this->batch_size = $batch_size;
        $this->data = [];
        $this->row_count = 0;
        $this->stmt = null;
        $this->stmt_size = null;
        $this->ignore = $ignore;
    }

    protected function estimateMaxBatchSize($row) {
        $max_allowed_packet = (int)$this->pdo->query("SELECT @@max_allowed_packet")->fetchColumn();
        return (int)($max_allowed_packet/(array_sum(array_map('strlen',$row))*4));
    }

    public function push($data) {
        if($this->fields === null) {
            $this->fields = array_keys($data);
        } else {
            $fields = array_keys($data);
            $extra_fields = array_diff($fields, $this->fields);
            if($extra_fields) {
                $this->flush();
                $this->fields = $fields;
            }
        }
        if($this->batch_size === null) {
            $this->batch_size = $this->estimateMaxBatchSize($data);
        }

        foreach($this->fields as $field) {
            $this->data[] = isset($data[$field]) ? $data[$field] : null;
        }
        //$data = \WXU::array_get_keys($data, $this->fields, true);
        //array_push($this->data, ...array_values($data));
        //$this->data = array_merge($this->data, array_values($data));
        ++$this->row_count;
        if($this->row_count >= $this->batch_size) $this->flush();
    }

    public function flush() {
        if($this->row_count > 0) {
            if($this->stmt_size !== $this->row_count) {
                $sql = 'INSERT ';
                if($this->ignore) $sql .= 'IGNORE ';
                $sql .= 'INTO ' . MyPdo::quote_identifier($this->table) . ' (';
                $sql .= implode(',', array_map([MyPdo::class, 'quote_identifier'], $this->fields));
                $sql .= ') VALUES ';
                $values = '(' . implode(',', array_fill(0, count($this->fields), '?')) . ')';
                $sql .= implode(',', array_fill(0, $this->row_count, $values));
                $this->stmt = $this->pdo->prepare($sql);
                $this->stmt_size = $this->row_count;
            }
            $this->stmt->execute($this->data);
            $this->data = [];
            $this->row_count = 0;
        }
    }

    function ignore($bool = true){
        $this->ignore = (bool)$bool;
        return $this;
    }

    function __destruct() {
        $this->flush();
    }


}