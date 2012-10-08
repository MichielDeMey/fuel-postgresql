<?php
/**
 * @package    PostgreSQL
 * @version    1.0
 * @author     Michiel De Mey
 * @license    MIT License
 * @link       http://michieldemey.be
 */
Autoloader::add_core_namespace('Pgsql');

Autoloader::add_classes(array(
	'Pgsql\\Database_Pgsql_Connection'	=> __DIR__.'/classes/database/pgsql/connection.php',
	'Pgsql\\Database_Pgsql_Result'          => __DIR__.'/classes/database/pgsql/result.php',
));