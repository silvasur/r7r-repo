<?php
/*
 * File: ratatoeskr/sys/db.php
 * 
 * Helper functions for dealing with MySQL.
 * 
 * License:
 * This file is part of Ratatöskr.
 * Ratatöskr is licensed unter the MIT / X11 License.
 * See "ratatoeskr/licenses/ratatoeskr" for more information.
 */

require_once(dirname(__FILE__) . "/../config.php");
require_once(dirname(__FILE__) . "/utils.php");

/*
 * Function: db_connect
 *
 * Establish a connection to the MySQL database.
 */
function db_connect()
{
	global $config;
	$db_connection = mysql_pconnect(
		$config["mysql"]["server"],
		$config["mysql"]["user"],
		$config["mysql"]["passwd"]);
	if(!$db_connection)
		die("Could not connect to database server. " . mysql_error());
	
	if(!mysql_select_db($config["mysql"]["db"], $db_connection))
		die("Could not open database. " . mysql_error());

	mysql_query("SET NAMES 'utf8'", $db_connection);
}

function sqlesc($str)
{
	return mysql_real_escape_string($str);
}

/*
 * Function: qdb_vfmt
 * Like <qdb_fmt>, but needs arguments as single array. 
 * 
 * Parameters:
 * 	$args - The arguments as an array.
 * 
 * Returns:
 * 	The formatted string.
 */
function qdb_vfmt($args)
{
	global $config;
	
	if(count($args) < 1)
		throw new InvalidArgumentException('Need at least one parameter');
	
	$query = $args[0];
	
	$data = array_map(function($x) { return is_string($x) ? sqlesc($x) : $x; }, array_slice($args, 1));
	$query = str_replace("PREFIX_", $config["mysql"]["prefix"], $query);
	
	return vsprintf($query, $data);
}

/*
 * Function: qdb_fmt
 * Formats a string like <qdb>, that means it replaces "PREFIX_" and <sqlesc>'s everything before sends everything to vsprintf.
 * 
 * Returns:
 * 	The formatted string.
 */
function qdb_fmt()
{
	return qdb_vfmt(func_get_args());
}


/*
 * Function: qdb
 * Query Database.
 * 
 * This function replaces mysql_query and should eliminate SQL-Injections.
 * Use it like this:
 * 
 * $result = qdb("SELECT `foo` FROM `bar` WHERE `id` = %d AND `baz` = '%s'", 100, "lol");
 * 
 * It will also replace "PREFIX_" with the prefix defined in 'config.php'.
 */
function qdb()
{
	$query = qdb_vfmt(func_get_args());
	$rv = mysql_query($query);
	if($rv === false)
		throw new MySQLException(mysql_errno() . ': ' . mysql_error() . (__DEBUG__ ? ("[[FULL QUERY: " . $query . "]]") : "" ));
	return $rv;
}

/*
 * Class: MySQLException
 * Will be thrown by qdb*, if the query induced an MySQL error.
 */
class MySQLException extends Exception { }

?>
