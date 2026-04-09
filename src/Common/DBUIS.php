<?php
class DBUIS {

	protected $has_errors = false;
	protected $err_msg;
    protected $connection;
	protected $query;
    protected $show_errors = TRUE;
    protected $query_closed = TRUE;
	protected $hasrows = false;
	public $query_count = 0;
	public $in_transaction = false;

	public function __construct($dbhost = 'localhost', $dbuser = 'root', $dbpass = '', $dbname = '', $charset = 'utf8') {
		$this->connection = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
		if ($this->connection->connect_error) {
			$this->error('Failed to connect to MySQL - ' . $this->connection->connect_error);
		}
		$this->connection->set_charset($charset);
	}

    public function query($query) {
		if (!$this->connection) {
			$this->error('Failed to connect to MySQL - Undefined variable ');
		}
		$this->has_errors = false;
        if (!$this->query_closed) {
            $this->query->close();
        }
		if ($this->query = $this->connection->prepare($query)) {
            if (func_num_args() > 1) {
                $x = func_get_args();
                $args = array_slice($x, 1);
				$types = '';
                $args_ref = array();
                foreach ($args as $k => &$arg) {
					if (is_array($args[$k])) {
						foreach ($args[$k] as $j => &$a) {
							$types .= $this->_gettype($args[$k][$j]);
							$args_ref[] = &$a;
						}
					} else {
	                	$types .= $this->_gettype($args[$k]);
	                    $args_ref[] = &$arg;
					}
                }
				array_unshift($args_ref, $types);
                call_user_func_array(array($this->query, 'bind_param'), $args_ref);
            }
            $this->query->execute();
           	if ($this->query->errno) {
				$this->error('Unable to process MySQL query (check your params) - ' . $this->query->error);
           	}
            $this->query_closed = FALSE;
			$this->query_count++;
        } else {
            $this->error('Unable to prepare MySQL statement (check your syntax) - ' . $this->connection->error . ' ' . $query);
        }
		return $this;
    }


	public function fetchAll($callback = null) {
		if (!$this->connection) {
			$this->error('Failed to connect to MySQL - Undefined variable ');
		}
		$this->has_errors = false;
	    $params = array();
        $row = array();
	    $meta = $this->query->result_metadata();
	    while ($field = $meta->fetch_field()) {
	        $params[] = &$row[$field->name];
	    }
	    call_user_func_array(array($this->query, 'bind_result'), $params);
		$this->hasrows = false;
        $result = array();
        while ($this->query->fetch()) {
            $r = array();
            foreach ($row as $key => $val) {
                $r[$key] = $val;
            }
            if ($callback != null && is_callable($callback)) {
                $value = call_user_func($callback, $r);
                if ($value == 'break') break;
            } else {
                $result[] = $r;
            }
			$this->hasrows = true;
        }
        $this->query->close();
        $this->query_closed = TRUE;
		return $result;
	}

	public function fetchArray() {
		if (!$this->connection) {
			$this->error('Failed to connect to MySQL - Undefined variable ');
		}
		$this->has_errors = false;
		$result = array();
	    $params = array();
        $row = array();
	    $meta = $this->query->result_metadata();
		if (!is_object($meta)) return $result;
	    while ($field = $meta->fetch_field()) {
	        $params[] = &$row[$field->name];
	    }
	    call_user_func_array(array($this->query, 'bind_result'), $params);
		$this->hasrows = false;
        $result = array();
		while ($this->query->fetch()) {
			foreach ($row as $key => $val) {
				$result[$key] = $val;
			}
			$this->hasrows = true;
		}
        $this->query->close();
        $this->query_closed = TRUE;
		return $result;
	}

	public function fetchRow() {
		if ($this->query_closed == TRUE) {
			return $this->hasrows;
		} else {
			return FALSE;
		}	
	}

	public function close() {
		$this->hasrows = false;
		return $this->connection->close();
	}

    public function numRows() {
		$this->query->store_result();
		return $this->query->num_rows;
	}

	public function affectedRows() {
		return $this->query->affected_rows;
	}

    public function lastInsertID() {
    	return $this->connection->insert_id;
    }

    public function error($error) {
        if ($this->show_errors) {
            exit($error);
        } else {
			$this->has_errors = true; 
			$this->err_msg = $error;
		}
    }

	public function isError() {
		return $this->has_errors;
	}

	public function setShowErr($flg) {
		$this->show_errors = $flg;
	}

	public function  getMessage() {
		return $this->err_msg;
	}

	private function _gettype($var) {
	    if (is_string($var)) return 's';
	    if (is_float($var)) return 'd';
	    if (is_int($var)) return 'i';
	    return 'b';
	}

	public function beginTransaction() {
		mysqli_begin_transaction($this->connection, MYSQLI_TRANS_START_READ_ONLY);
		$this->in_transaction = true;
	}

	public function commit() {
		mysqli_commit($this->connection);
		$this->query_count = 0;
		$this->in_transaction = false;
	}

	public function rollback() {
		mysqli_rollback($this->connection);
		$this->query_count = 0;
		$this->in_transaction = false;
	}

	public function isConnected() {
		if (!$this->connection) {
			return false;
		} else if ($this->connection->connect_errno) {
			return false;
		} else if (!$this->connection->ping()) {
			return false;
		} else {
			return true;
		}
	}
}
?>