<?php

/**
 * PostgreSQL database result.
 *
 * @package    PostgreSQL/Database
 * @category   Query/Result
 * @author     Kohana Team
 * @author     Michiel De Mey <michieldemey.be>
 * @copyright  (c) 2008-2009 Kohana Team
 * @license    http://kohanaphp.com/license
 */

namespace Pgsql;

class Database_Pgsql_Result extends \Fuel\Core\Database_Result {

    protected $_internal_row = 0;

    public function __construct($result, $sql, $as_object) {
        parent::__construct($result, $sql, $as_object);

        // Find the number of rows in the result
        $this->_total_rows = pg_num_rows($result);
    }

    public function __destruct() {
        if (is_resource($this->_result)) {
            pg_free_result($this->_result);
        }
    }

    public function seek($offset) {
        if ($this->offsetExists($offset) AND pg_result_seek($this->_result, $offset)) {
            // Set the current row to the offset
            $this->_current_row = $this->_internal_row = $offset;

            return TRUE;
        } else {
            return FALSE;
        }
    }

    public function current() {
        if ($this->_current_row !== $this->_internal_row AND !$this->seek($this->_current_row))
            return FALSE;

        // Increment internal row for optimization assuming rows are fetched in order
        $this->_internal_row++;

        if ($this->_as_object === TRUE) {
            // Return an stdClass
            return pg_fetch_object($this->_result);
        } elseif (is_string($this->_as_object)) {
            // Return an object of given class name
            // TODO Fix this.
            return pg_fetch_object($this->_result);
            // Old MySQL return query: return mysql_fetch_object($this->_result, $this->_as_object);
        } else {
            // Return an array of the row
            return pg_fetch_assoc($this->_result);
        }
    }

}