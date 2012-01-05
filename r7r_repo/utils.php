<?php
/*
 * File: ratatoeskr/sys/utils.php
 * 
 * Various useful helper functions.
 * 
 * License:
 * This file is part of Ratatöskr.
 * Ratatöskr is licensed unter the MIT / X11 License.
 * See "ratatoeskr/licenses/ratatoeskr" for more information.
 */

/*
 * Function: array_repeat
 * 
 * Parameters:
 * 
 * 	$val - 
 * 	$n   - 
 * 
 * Returns:
 * 
 * 	An array with $val $n-times repeated.
 */
function array_repeat($val, $n)
{
	$rv = array();
	for($i = 0; $i < $n; ++$i)
		array_push($rv, $val);
	return $rv;
}

/*
 * Function: array_blend
 * 
 * Blend multiple arrays together.
 * 
 * Example:
 * 
 * 	array_blend(array(1,2,3), array(4,5,6), array(7,8,9));
 * 	will return array(1,4,7,2,5,8,3,6,9)
 */
function array_blend()
{
	$arrays = array_filter(func_get_args(), "is_array");
	
	switch(count($arrays))
	{
		case 0:  return array(); break;
		case 1:  return $arrays[0]; break;
		default:
			$rv = array();
			while(array_sum(array_map("count", $arrays)) > 0)
			{
				for($i = 0; $i < count($arrays); ++$i)
				{
					$val = array_shift($arrays[$i]);
					if($val === NULL)
						continue;
					array_push($rv, $val);
				}
			}
			return $rv;
			break;
	}
}

/*
 * Function: array_filter_empty
 * 
 * Filters all empty elements out of an array.
 *
 * Parameters:
 * 
 * 	$input - The input array
 *
 * Returns:
 * 
 * 	The $input without its empty elements.
 */
function array_filter_empty($input)
{
	return array_filter($input, function($x){return !empty($x);});
}

/*
 * Function: array_filter_keys
 * 
 * Like PHPs `array_filter`, but callback will get the key, not the value of the array element.
 */
function array_filter_keys($input, $callback)
{
	if(!is_array($input))
		throw new InvalidArgumentException("Argument 1 must be an array");
	if(empty($input))
		return array();
	$delete_keys = array_filter(array_keys($input), function ($x) use ($callback) { return !$callback($x);});
	foreach($delete_keys as $key)
		unset($input[$key]);
	return $input;
}

/*
 * Function: array_kvpairs_to_assoc
 * Convert array of key-value pairs to an associative array.
 * 
 * Parameters:
 * 	$input - Array of key-value pairs
 * 
 * Returns:
 * 	An associative array.
 */
function array_kvpairs_to_assoc($input)
{
	$rv = array();
	foreach($input as $kvpair)
		$rv[$kvpair[0]] = $kvpair[1];
	return $rv;
}

/*
 * Function: intcmp
 * Compare integers (equavilent to strcmp)
 */
function intcmp($a, $b)
{
	return ($a == $b) ? 0 : (($a < $b) ? -1 : 1);
}

/*
 * Function: ucount
 * 
 * Count elements of an array matching unser-defined rules.
 * 
 * Parameters:
 * 	$array    - The input array.
 * 	$callback - A callback function. It will be called with the current value as the only parameter. The value is counted, if callback returns TRUE.
 *
 * Returns:
 * 
 * 	Number of elements where $callback returned TRUE.
 */
function ucount($array, $callback)
{
	return count(array_filter($array, $callback));
}

/*
 * Function: vcount
 * 
 * Counts how often $value appears in $array.
 * 
 * Parameters:
 * 
 * 	$array - 
 * 	$value - 
 * 
 * Returns:
 * 
 * 	How often $value appears in $array.
 */
function vcount($array, $value)
{
	return ucount($array, function($x){return $x===$value;});
}

/*
 * Function: self_url
 * 
 * Gets current URL.
 * 
 * From: http://dev.kanngard.net/Permalinks/ID_20050507183447.html
 */
function self_url() {
	$s = empty($_SERVER["HTTPS"]) ? ''
		: ($_SERVER["HTTPS"] == "on") ? "s"
		: "";
	$protocol = strleft(strtolower($_SERVER["SERVER_PROTOCOL"]), "/").$s;
	$port = ($_SERVER["SERVER_PORT"] == "80") ? ""
		: (":".$_SERVER["SERVER_PORT"]);
	return $protocol."://".$_SERVER['SERVER_NAME'].$port.$_SERVER['REQUEST_URI'];
}
function strleft($s1, $s2) {
	return substr($s1, 0, strpos($s1, $s2));
}

/*
 * Function: htmlesc
 * Escape HTML (shorter than htmlentities($text, ENT_QUOTES, "UTF-8"))
 * 
 * Parameters:
 * 	$text - Input text.
 * 
 * Returns:
 * 	HTML
 */
function htmlesc($text)
{
	return htmlentities($text, ENT_QUOTES, "UTF-8");
}

/*
 * Function: delete_directory
 * Delete a directory and all of its content.
 */
function delete_directory($dir)
{
	$dir_content = scandir($dir);
	foreach($dir_content as $f)
	{
		if(($f == "..") or ($f == "."))
			continue;
		
		$f = "$dir/$f";
		
		if(is_dir($f))
			delete_directory($f);
		else
			unlink($f);
	}
	rmdir($dir);
}

/*
 * Constant: SITE_BASE_PATH
 * The Base path of this ratatoeskr site.
 */
define("SITE_BASE_PATH", dirname(dirname(dirname(__FILE__))));
?>
