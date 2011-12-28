<?php
/*
 * File: ratatoeskr/sys/urlprocess.php
 * 
 * Providing functions / classes to handle URLs
 * 
 * License:
 * This file is part of Ratatöskr.
 * Ratatöskr is licensed unter the MIT / X11 License.
 * See "ratatoeskr/licenses/ratatoeskr" for more information.
 */

/*
 * Function: url_action_simple
 * Generate an action in a more simple way.
 * 
 * Parameters:
 * 	$function - A callback that gets the $data var as an input and returns the new $data var. Can throw an <Redirect> Exception.
 * 
 * Returns:
 * 	A callback that can be used as an url action.
 */
function url_action_simple($function)
{
	return function(&$data, $url_now, &$url_next) use ($function)
	{
		try
		{
			$data = call_user_func($function, $data);
			$url_next = array();
		}
		catch(Redirect $e)
		{
			$url_next = $e->nextpath;
		}
	};
}

/*
 * Function: url_action_subactions
 * Generate an action that contains subactions. Subactions can redirect to ".." to go to the level above.
 * 
 * Parameters:
 * 	$actions - Associative array of actions.
 * 
 * Returns:
 * 	A callback that can be used as an url action.
 */
function url_action_subactions($actions)
{
	return function(&$data, $url_now, &$url_next) use ($actions)
	{
		$result = url_process($url_next, $actions, $data);
		if($result !== NULL)
			$url_next = $result;
		else
			$url_next = array();
	};
}

/*
 * Function: url_action_alias
 * Generate an action that is an alias for another one (i.e. redirects).
 * 
 * Parameters:
 * 	$for - Path (array) of the action this one should be an alias of.
 *
 * Returns:
 * 	A callback that can be used as an url action.
 */
function url_action_alias($for)
{
	return function(&$data, $url_now, &$url_next) use($for)
	{
		$url_next = array_merge($for, $url_next);
	};
}

/*
 * Function: url_process
 * Choose an appropiate action for the given URL.
 * 
 * Parameters:
 * 	$url - Either an array containing the URL components or the URL (both relative).
 * 	$actions - Associative array of actions.
 * 	           Key is the name (anything alphanumeric, should usually not start with '_', reserved for special URL names, see beneath).
 * 	           Value is a callback of the form: function(&$data, $url_now, &$url_next). $data can be used for shared data between subactions. $url_next can be modified in order to redirect to another action / stop the routing.
 * 
 * Special actions:
 * 	_index - If name is empty, the index will be called.
 * 	_default - If nothing was found, this is the default.
 * 	_notfound - If not even _default exists or NotFoundError was thrown.
 * 	_prelude - If existant, will be executed before everything else.
 * 	_epilog - If existant, will be executed after evrything else.
 */
function url_process($url, $actions, &$data)
{
	$epilog_running = 0;
	if(is_string($url))
		$url = explode("/", $url);
	$url = array_filter($url, function($x) { return !empty($x); });
	if(count($url) == 0)
		$url = array("_index");
	
	if(isset($actions["_prelude"]))
		$url = array_merge(array("_prelude"), $url);
	
	$url_now  = $url[0];
	$url_next = array_slice($url, 1);
	
	while(is_string($url_now) and ($url_now != "") and ($url_now != ".."))
	{
		$cb = NULL;
		if(empty($url_now))
			$url_now = "_index";
		if(isset($actions[$url_now]))
			$cb = $actions[$url_now];
		else if(isset($actions["_default"]))
			$cb = $actions["_default"];
		else if(isset($actions["_notfound"]))
			$cb = $actions["_notfound"];
		else
			throw new NotFoundError();
		
		try
		{
			$cb($data, $url_now, $url_next);
		}
		catch(NotFoundError $e)
		{
			if(isset($actions["_notfound"]))
				$url_next = array("_notfound");
			else
				throw $e;
		}
		
		if(count($url_next) > 0)
		{
			$url_now  = $url_next[0];
			$url_next = array_slice($url_next, 1);
		}
		else if(isset($actions["_epilog"]) and ($epilog_running <= 0))
		{
			$epilog_running = 2;
			$url_now        = "_epilog";
		}
		else
			$url_now = "";
		
		--$epilog_running;
	}
	
	if($url_now == "..")
		return $url_next;
	else
		return NULL;
}

/*
 * Class: Redirect
 * Exception that can be thrown inside an <url_action_simple>.
 * throw new Redirect(array("..", "foo")); will redirect to "../foo" and won't touch $data.
 */
class Redirect extends Exception
{
	public $nextpath;
	public function __construct($nextpath)
	{
		$this->nextpath = $nextpath;
		parent::__construct();
	}
}
/*
 * Class: NotFoundError
 * An Exception
 */
class NotFoundError extends Exception { }

?>
