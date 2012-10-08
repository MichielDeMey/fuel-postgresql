<?php

/**
 * PostgreSQL database connection.
 *
 * @package    PostgreSQL/Database
 * @category   Drivers
 * @author     Kohana Team
 * @author     Jon Doane <jrdoane@google.com>
 * @author     Michiel De Mey <michieldemey.be>
 * @copyright  (c) 2008-2009 Kohana Team
 * @license    http://kohanaphp.com/license
 */

namespace Pgsql;

class Database_Pgsql_Connection extends \Fuel\Core\Database_Connection {

    // Database in use by each connection
    protected static $_current_databases = array();
    // Use SET NAMES to set the character set
    protected static $_set_names;
    // Identifier for this connection within the PHP driver
    protected $_connection_id;
    // MySQL uses a backtick for identifiers
    protected $_identifier = '"';
    // Allows transactions

    protected $_trans_enabled = FALSE;
    // transaction errors
    public $trans_errors = FALSE;
    // Know which kind of DB is used
    public $_db_type = 'pgsql';

    public function connect() {
        if ($this->_connection)
            return;

        // Extract the connection parameters, adding required variabels
        extract($this->_config['connection'] + array(
            'database' => '',
            'hostname' => '',
            'port' => '',
            'username' => '',
            'password' => '',
            'persistent' => FALSE,
        ));

        // Prevent this information from showing up in traces
        unset($this->_config['connection']['username'], $this->_config['connection']['password']);

        try {
            $conn_string = "";
            $conn_string .=!empty($hostname) ? " host=$hostname " : '';
            $conn_string .=!empty($port) ? " port=$port " : '';
            $conn_string .= " user=$username password=$password";
            $conn_string .= " dbname=$database";


            // TODO: Do this manually. $conn_string .= !empty($this->_config['charset']) ?
            if ($persistent) {
                // Create a persistent connection
                if (!function_exists('pg_pconnect')) {
                    if (!function_exists('pg_connect')) {
                        throw new Exception("Missing PostgreSQL connect functions.");
                    }
                    throw new Exception("Persistant connection unsupported.");
                }
                if (!function_exists('pg_connect')) {
                    throw new Exception("Missing PostgreSQL connect functions.");
                }
                $this->_connection = pg_pconnect($conn_string);
            } else {
                // Create a connection and force it to be a new link
                $this->_connection = pg_connect($conn_string);
            }
        } catch (ErrorException $e) {
            // No connection exists
            $this->_connection = NULL;

            throw new \Database_Exception("Error connecting to postgresql database.");
        }

        // \xFF is a better delimiter, but the PHP driver uses underscore
        $this->_connection_id = sha1($hostname . '_' . $username . '_' . $password);
    }

    public function disconnect() {
        try {
            // Database is assumed disconnected
            $status = TRUE;

            if (is_resource($this->_connection)) {
                if ($status = pg_close($this->_connection)) {
                    // Clear the connection
                    $this->_connection = NULL;
                }
            }
        } catch (Exception $e) {
            // Database is probably not disconnected
            $status = !is_resource($this->_connection);
        }

        return $status;
    }

    public function set_charset($charset) {
        if (!pg_set_client_encoding($this->_connection, $charset)) {
            throw new \Database_Exception("Error setting client encoding.");
        }
        return true;
    }

    public function query($type, $sql, $as_object) {
        // Make sure the database is connected
        $this->_connection or $this->connect();

        if (!empty($this->_config['profiling'])) {
            // Benchmark this query for the current instance
            $benchmark = Profiler::start("Database ({$this->_instance})", $sql);
        }

        // Execute the query
        if (($result = pg_query($this->_connection, $sql)) === FALSE) {
            if (isset($benchmark)) {
                // This benchmark is worthless
                Profiler::delete($benchmark);
            }

            if ($type !== \DB::SELECT && $this->_trans_enabled) {
                // If we are using transactions, throwing an exception would defeat the purpose
                // We need to log the failures for transaction status
                if (!is_array($this->trans_errors)) {
                    $this->trans_errors = array();
                }

                $this->trans_errors[] = "pgsql: Error running query.\n[ {$sql} ]\n";
            } else {
                throw new \Database_Exception("pgsql: " . pg_last_error($this->_connection) . ".\n[ {$sql} ]");
            }
        }

        if (isset($benchmark)) {
            Profiler::stop($benchmark);
        }

        // Set the last query
        $this->last_query = $sql;

        if ($type === \DB::SELECT) {
            // Return an iterator of results
            return new \Database_Pgsql_Result($result, $sql, $as_object);
        } elseif ($type === \DB::INSERT) {
            // Return a list of insert id and rows created
            // TODO! This is a little harder to do in postgresql. We need to 
            // find out which table we inserted into. What the primary key is, 
            // and return the current sequence value. The stuff we have to do to 
            // get a stupid ID! Oh yeah, PostgreSQL supports multiple 
            // incremented primary keys unlike MySQL.
            $dbname = pg_dbname($this->_connection); // We need this to narrow down which table.
            $table = ""; // some regex to get this string.
            $sql = "
		SELECT c.*
		FROM information_schema.columns c
		JOIN information_schema.table_constraints tc USING (table_name, table_catalog)
		WHERE c.table_name = '{$table}' AND c.table_catalog = '{$dbname}' AND
		tc.constraint_type = 'PRIMARY KEY'

		";
            $result = new \Database_Pgsql_Result(pg_query($this->_connection, $sql), $sql, true);
            $inserted_id_tuple = array();
            while ($pk = $result->current()) {
                $default = $pk->column_default; // This will contain the name of the sequence.
                $seq = preg_match("/^nextval\('(.*?)'::regclass/", $default, $matches); // strips the sequence out.
                if (!$seq) {
                    $inserted_id_tuple[$pk->column_name] = '';
                } else {
                    $seqname = $matches[1];
                    $sql = "SELECT last_value FROM $seqname";
                    $idresult = new \Database_Pgsql_Result(pg_query($this->_connection, $sql), $sql, true);
                    $id = $idresult->current();
                    if (!$id) {
                        throw new Exception("Unable to get value of pk sequence.\n[$sql]\n");
                    }
                    $inserted_id_tuple[$pk->column_name] = $id->last_value;
                }
            }
            // Now that some crazy way to find last inserted IDs is done, we 
            // will continue.
            // TODO: Cache sequence-pk relations.
            if (count($inserted_id_tuple) == 1) {
                $inserted_id_tuple = array_pop($inserted_id_tuple);
            }
            return array(
                $inserted_id_tuple,
                pg_affected_rows($result),
            );
        } else {
            // Return the number of rows affected
            return pg_affected_rows($result);
        }
    }

    // TODO: Update with pgsql datatypes.
    public function datatype($type) {
        static $types = array
    (
    'blob' => array('type' => 'string', 'binary' => TRUE, 'character_maximum_length' => '65535'),
    'bool' => array('type' => 'bool'),
    'bigint unsigned' => array('type' => 'int', 'min' => '0', 'max' => '18446744073709551615'),
    'datetime' => array('type' => 'string'),
    'decimal unsigned' => array('type' => 'float', 'exact' => TRUE, 'min' => '0'),
    'double' => array('type' => 'float'),
    'double precision unsigned' => array('type' => 'float', 'min' => '0'),
    'double unsigned' => array('type' => 'float', 'min' => '0'),
    'enum' => array('type' => 'string'),
    'fixed' => array('type' => 'float', 'exact' => TRUE),
    'fixed unsigned' => array('type' => 'float', 'exact' => TRUE, 'min' => '0'),
    'float unsigned' => array('type' => 'float', 'min' => '0'),
    'int unsigned' => array('type' => 'int', 'min' => '0', 'max' => '4294967295'),
    'integer unsigned' => array('type' => 'int', 'min' => '0', 'max' => '4294967295'),
    'longblob' => array('type' => 'string', 'binary' => TRUE, 'character_maximum_length' => '4294967295'),
    'longtext' => array('type' => 'string', 'character_maximum_length' => '4294967295'),
    'mediumblob' => array('type' => 'string', 'binary' => TRUE, 'character_maximum_length' => '16777215'),
    'mediumint' => array('type' => 'int', 'min' => '-8388608', 'max' => '8388607'),
    'mediumint unsigned' => array('type' => 'int', 'min' => '0', 'max' => '16777215'),
    'mediumtext' => array('type' => 'string', 'character_maximum_length' => '16777215'),
    'national varchar' => array('type' => 'string'),
    'numeric unsigned' => array('type' => 'float', 'exact' => TRUE, 'min' => '0'),
    'nvarchar' => array('type' => 'string'),
    'point' => array('type' => 'string', 'binary' => TRUE),
    'real unsigned' => array('type' => 'float', 'min' => '0'),
    'set' => array('type' => 'string'),
    'smallint unsigned' => array('type' => 'int', 'min' => '0', 'max' => '65535'),
    'text' => array('type' => 'string', 'character_maximum_length' => '65535'),
    'tinyblob' => array('type' => 'string', 'binary' => TRUE, 'character_maximum_length' => '255'),
    'tinyint' => array('type' => 'int', 'min' => '-128', 'max' => '127'),
    'tinyint unsigned' => array('type' => 'int', 'min' => '0', 'max' => '255'),
    'tinytext' => array('type' => 'string', 'character_maximum_length' => '255'),
    'year' => array('type' => 'string'),
        );

        $type = str_replace(' zerofill', '', $type);

        if (isset($types[$type]))
            return $types[$type];

        return parent::datatype($type);
    }

    public function list_tables($like = NULL) {
        $dbname = pg_dbname($this->_connection);
        // This screams mysql, but we're pg so we're going to muck with this a bit.`
        if (is_string($like)) {
            // Search for table names
            $result = $this->query(\DB::SELECT, "
		SELECT table_name
		FROM information_schema.tables
		WHERE table_name ILIKE '%" . $this->quote($like) . "%' AND
		table_catalog = '{$dbname}'
		", true);
        } else {
            // Find all table names
            $result = $this->query(\DB::SELECT, "
		SELECT table_name
		FROM information_schema.tables
		WHERE table_catalog = '{$dbname}'
		", true);
        }

        $tables = array();
        foreach ($result as $row) {
            $tables[] = $row->table_name;
        }

        return $tables;
    }

    public function list_columns($table, $like = NULL) {
        // Quote the table name
        $table = $this->quote_table($table);

        if (is_string($like)) {
            // Search for column names
            $result = $this->query(\DB::SELECT, "
		SELECT *
		FROM information_schema.columns
		WHERE table_name = '$table' AND
		column_name ILIKE '%$like%'
		", false);
        } else {
            // Find all column names
            $result = $this->query(\DB::SELECT, "
		SELECT *
		FROM information_schema.columns
		WHERE table_name = '$table'
		", false);
        }

        $count = 0;
        $columns = array();
        foreach ($result as $row) {
            list($type, $length) = $this->_parse_type($row['data_type']);

            $column = $this->datatype($type);

            $column['name'] = $row['column_name'];
            $column['default'] = $row['column_default'];
            $column['data_type'] = $type;
            $column['null'] = ($row['is_nullable'] == 'YES');
            $column['ordinal_position'] = $row['ordinal_position'];

            switch ($column['type']) {
                case 'float':
                    if (isset($length)) {
                        list($column['numeric_precision'], $column['numeric_scale']) = explode(',', $length);
                    }
                    break;
                case 'int':
                    if (isset($length)) {
                        // MySQL attribute
                        $column['numeric_precision'] = $length;
                    }
                    break;
                case 'string':
                    switch ($column['data_type']) {
                        case 'binary':
                        case 'varbinary':
                            $column['character_maximum_length'] = $length;
                            break;

                        case 'char':
                        case 'varchar':
                            $column['character_maximum_length'] = $length;
                        case 'text':
                            $column['collation_name'] = $row['Collation'];
                            break;
                    }
                    break;
            }

            // MySQL attributes
            // TODO: Is this the primary key? PostgreSQL supports multiple. How 
            // should we do this? CARP CARP CARP!
            $column['key'] = $row['Key'];

            $columns[$row['Field']] = $column;
        }

        return $columns;
    }

    public function escape($value) {
        // Make sure the database is connected
        $this->_connection or $this->connect();

        if (($value = pg_escape_string($this->_connection, (string) $value)) === FALSE) {
            throw new \Database_Exception('pg_escape_string exploded. No idea what happened here. Value: {' . var_export($value, true) . '}');
        }

        // SQL standard is to use single-quotes for all values
        return "'$value'";
    }

    public function transactional($use_trans = TRUE) {
        if (is_bool($use_trans)) {
            $this->_trans_enabled = $use_trans;
        }
    }

    public function start_transaction() {
        $this->transactional();
        $this->query(0, 'BEGIN', false);
    }

    public function commit_transaction() {
        $this->query(0, 'COMMIT', false);
    }

    public function rollback_transaction() {
        $this->query(0, 'ROLLBACK', false);
    }

    public function in_transaction() {
        return $this->_trans_enabled;
    }

}
