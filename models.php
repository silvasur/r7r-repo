<?php

require_once(dirname(__FILE__) . "/db.php");
require_once(dirname(__FILE__) . "/utils.php");
db_connect();

/* Exceptions copied from Ratatöskr */
class DoesNotExistError extends Exception { }
class AlreadyExistsError extends Exception { }
class NotAllowedError extends Exception { }

/* copied from Ratatöskr: */
abstract class BySQLRowEnabled
{
	protected function __construct() {  }
	
	abstract protected function populate_by_sqlrow($sqlrow);
	
	protected static function by_sqlrow($sqlrow)
	{
		$obj = new static();
		$obj->populate_by_sqlrow($sqlrow);
		return $obj;
	}
}

/* SettingsIterator ans Settings copied from Ratatöskr */

class SettingsIterator implements Iterator
{
	private $index;
	private $keys;
	private $settings_obj;
	
	public function __construct($settings_obj, $keys)
	{
		$this->index = 0;
		$this->settings_obj = $settings_obj;
		$this->keys = $keys;
	}
	
	/* Iterator implementation */
	public function current() { return $this->settings_obj[$this->keys[$this->index]]; }
	public function key()     { return $this->keys[$this->index]; }
	public function next()    { ++$this->index; }
	public function rewind()  { $this->index = 0; }
	public function valid()   { return $this->index < count($this->keys); }
}

/*
 * Class: Settings
 * A class that holds the Settings of Ratatöskr.
 * You can access settings like an array.
 */
class Settings implements ArrayAccess, IteratorAggregate, Countable
{
	/* Singleton implementation */
	private function __copy() {}
	private static $instance = NULL;
	/*
	 * Constructor: get_instance
	 * Get an instance of this class.
	 * All instances are equal (ie. this is a singleton), so you can also use
	 * the global <$ratatoeskr_settings> instance.
	 */
	public static function get_instance()
	{
		if(self::$instance === NULL)
			self::$instance = new self;
		return self::$instance;
	}
	
	private $buffer;
	private $to_be_deleted;
	private $to_be_created;
	private $to_be_updated;
	
	private function __construct()
	{
		$this->buffer = array();
		$result = qdb("SELECT `key`, `value` FROM `PREFIX_settings_kvstorage` WHERE 1");
		while($sqlrow = mysql_fetch_assoc($result))
			$this->buffer[$sqlrow["key"]] = unserialize(base64_decode($sqlrow["value"]));
		
		$this->to_be_created = array();
		$this->to_be_deleted = array();
		$this->to_be_updated = array();
	}
	
	public function save()
	{
		foreach($this->to_be_deleted as $k)
			qdb("DELETE FROM `PREFIX_settings_kvstorage` WHERE `key` = '%s'", $k);
		foreach($this->to_be_updated as $k)
			qdb("UPDATE `PREFIX_settings_kvstorage` SET `value` = '%s' WHERE `key` = '%s'", base64_encode(serialize($this->buffer[$k])), $k);
		foreach($this->to_be_created as $k)
			qdb("INSERT INTO `PREFIX_settings_kvstorage` (`key`, `value`) VALUES ('%s', '%s')", $k, base64_encode(serialize($this->buffer[$k])));
		$this->to_be_created = array();
		$this->to_be_deleted = array();
		$this->to_be_updated = array();
	}
	
	/* ArrayAccess implementation */
	public function offsetExists($offset)
	{
		return isset($this->buffer[$offset]);
	}
	public function offsetGet($offset)
	{
		return $this->buffer[$offset];
	}
	public function offsetSet ($offset, $value)
	{
		if(!$this->offsetExists($offset))
		{
			if(in_array($offset, $this->to_be_deleted))
			{
				$this->to_be_updated[] = $offset;
				unset($this->to_be_deleted[array_search($offset, $this->to_be_deleted)]);
			}
			else
				$this->to_be_created[] = $offset;
		}
		elseif((!in_array($offset, $this->to_be_created)) and (!in_array($offset, $this->to_be_updated)))
			$this->to_be_updated[] = $offset;
		$this->buffer[$offset] = $value;
	}
	public function offsetUnset($offset)
	{
		if(in_array($offset, $this->to_be_created))
			unset($this->to_be_created[array_search($offset, $this->to_be_created)]);
		else
			$this->to_be_deleted[] = $offset;
		unset($this->buffer[$offset]);
	}
	
	/* IteratorAggregate implementation */
	public function getIterator() { return new SettingsIterator($this, array_keys($this->buffer)); }
	
	/* Countable implementation */
	public function count() { return count($this->buffer); }
}

$settings = Settings::get_instance();

/* users */
class User extends BySQLRowEnabled
{
	private $name;
	private $id;
	
	public $pwhash;
	public $isadmin;
	
	protected function __construct() {}
	
	protected function populate_by_sqlrow($sqlrow)
	{
		$this->id      = $sqlrow["id"];
		$this->name    = $sqlrow["name"];
		$this->pwhash  = $sqlrow["pwhash"];
		$this->isadmin = $sqlrow["isadmin"] == 1;
	}
	
	function get_id()   { return $this->id;   }
	function get_name() { return $this->name; }
	
	public static function create($name)
	{
		try
		{
			self::by_name($name);
			throw new AlreadyExistsError();
		}
		catch(DoesNotExistError $e)
		{
			$obj          = new self;
			$obj->name    = $name;
			$obj->pwhash  = "";
			$obj->isadmin = False;
			qdb("INSERT INTO `PREFIX_users` (`name`, `pwhash`, isadmin`) VALUES ('%s', '', 0)", $name);
			$this->id = mysql_insert_id();
			return $obj;
		}
	}
	
	public static function by_id($id)
	{
		$result = qdb("SELECT `id`, `name`, `pwhash`, `isadmin` FROM `PREFIX_users` WHERE `id` = %d", $id);
		$sqlrow = mysql_fetch_assoc($result);
		if($sqlrow === False)
			throw new DoesNotExistError();
		return self::by_sqlrow($sqlrow);
	}
	
	public static function by_name($name)
	{
		$result = qdb("SELECT `id`, `name`, `pwhash`, `isadmin` FROM `PREFIX_users` WHERE `name` = '%s'", $name);
		$sqlrow = mysql_fetch_assoc($result);
		if($sqlrow === False)
			throw new DoesNotExistError();
		return self::by_sqlrow($sqlrow);
	}
	
	public static function all()
	{
		$rv = array();
		$result = qdb("SELECT `id`, `name`, `pwhash`, `isadmin` FROM `PREFIX_users` WHERE 1");
		while($sqlrow = mysql_fetch_assoc($result))
			$rv[] = self::by_sqlrow($sqlrow);
		return $rv;
	}
	
	public function get_packages()
	{
		$rv = array();
		$result = qdb("SELECT `id`, `name`, `user`, `lastversion`, `description`, `lastupdate`, `txtversion` FROM `PREFIX_packages` WHERE `user` = %d", $this->id);
		while($sqlrow = mysql_fetch_assoc($result))
			$rv[] = Package::by_sqlrow($result);
		return $rv;
	}
	
	public function save()
	{
		qdb("UPDATE `PREFIX_users` SET `isadmin` = %d, `pwhash` = '%s' WHERE `id` = %d", ($this->isadmin ? 1 : 0), $this->pwhash, $this->id);
	}
	
	public function delete()
	{
		qdb("DELETE FROM `PREFIX_users` WHERE `id` = %d", $this->id);
	}
}

class Package extends BySQLRowEnabled
{
	private $id;
	private $name;
	private $user;
	
	public $lastversion;
	public $description;
	public $lastupdate;
	public $txtversion;
	
	public function get_id()   { return $id;   }
	public function get_name() { return $name; }
	public function get_user() { return $user; }
	
	protected function __construct() {}
	
	protected function populate_by_sqlrow($sqlrow)
	{
		$this->id          = $sqlrow["id"];
		$this->name        = $sqlrow["name"];
		$this->user        = User::by_id($sqlrow["user"]);
		$this->lastversion = $sqlrow["lastversion"];
		$this->description = $sqlrow["description"];
		$this->lastupdate  = $sqlrow["lastupdate"];
		$this->txtversion  = $sqlrow["txtversion"];
	}
	
	public static function create($name, $user)
	{
		if(preg_match("/^[0-9a-zA-Z_\\-]+$/", $name) != 1)
			throw new InvalidArgumentException("Invalid package name (must be min 1 char, 0-9A-Za-z_-");
		try
		{
			self::by_name($name);
			throw new AlreadyExistsError();
		}
		catch(DoesNotExistError $e)
		{
			$obj = new self;
			$obj->name        = $name;
			$obj->user        = $user;
			$obj->lastupdate  = time();
			$obj->lastversion = 0;
			$obj->txtversion  = "";
			$obj->description = "";
			
			qdb("INSERT INTO `PREFIX_packages` (`name`, `user`, `lastupdate`, `lastversion`, `txtversion`, description`, `description`) VALUES ('%s', %d, UNIX_TIMESTAMP(), 0, '', '')", $name, $user->get_id());
			$obj->id = mysql_insert_id();
			
			mkdir(dirname(__FILE__) . "/packages/" . $this->name);
			
			return $obj;
		}
	}
	
	public static function by_id($id)
	{
		$result = qdb("SELECT `id`, `name`, `user`, `lastversion`, `description`, `lastupdate`, `txtversion` FROM `PREFIX_packages` WHERE `id` = %d", $id);
		$sqlrow = mysql_fetch_assoc($result);
		if($sqlrow === False)
			throw new DoesNotExistError();
		return self::by_sqlrow($sqlrow);
	}
	
	public static function by_name($name)
	{
		$result = qdb("SELECT `id`, `name`, `user`, `lastversion`, `description`, `lastupdate`, `txtversion` FROM `PREFIX_packages` WHERE `name` = '%s'", $name);
		$sqlrow = mysql_fetch_assoc($result);
		if($sqlrow === False)
			throw new DoesNotExistError();
		return self::by_sqlrow($sqlrow);
	}
	
	public static function update_lists()
	{
		$packagelist = array();
		$result = qdb("SELECT `id`, `name`, `user`, `lastversion`, `description`, `lastupdate`, `txtversion` FROM `PREFIX_packages` WHERE 1");
		while($sqlrow = mysql_fetch_assoc($result))
			$packagelist[] = array($sqlrow["name"], $sqlrow["lastversion"], $sqlrow["description"]);
		file_put_contents(dirname(__FILE__) . "/packagelist", serialize($packagelist));
	}
	
	public static function all()
	{
		$rv = array();
		$result = qdb("SELECT `id`, `name`, `user`, `lastversion`, `description`, `lastupdate`, `txtversion` FROM `PREFIX_packages` WHERE 1");
		while($sqlrow = mysql_fetch_assoc($result))
			$rv[] = self::by_sqlrow($sqlrow);
		return $rv;
	}
	
	public static function latest()
	{
		$rv = array();
		$result = qdb("SELECT `id`, `name`, `user`, `lastversion`, `description`, `lastupdate`, `txtversion` FROM `PREFIX_packages` WHERE 1 ORDER BY `lastupdate` DESC LIMIT 0,15");
		while($sqlrow = mysql_fetch_assoc($result))
			$rv[] = self::by_sqlrow($sqlrow);
		return $rv;
	}
	
	public static function search($search)
	{
		$rv = array();
		$result = qdb("SELECT `id`, `name`, `user`, `lastversion`, `description`, `lastupdate`, `txtversion` FROM `PREFIX_packages` WHERE `name` LIKE '%%%s%%' OR `description` LIKE '%%%s%%'", $search, $search);
		while($sqlrow = mysql_fetch_assoc($result))
			$rv[] = self::by_sqlrow($sqlrow);
		return $rv;
	}
	
	public function newversion($pgk)
	{
		global $settings;
		if($pkg->name != $this->name)
			throw new NotAllowedError("Package name not equal.");
		if($pkg->versioncount <= $this->lastversion)
			throw new NotAllowedError("Older or same version.");
		$pkg->updatepath = $settings["root_url"] . "/packages/" . urlencode($this->name) . "/update";
		
		$pkg_ser = $pkg->save();
		file_put_contents(dirname(__FILE__) . "/packages/" . urlencode($this>name) . "/versions/" . $pkg->versioncount, $pkg_ser);
		file_put_contents(dirname(__FILE__) . "/packages/" . urlencode($this>name) . "/versions/current", $pkg_ser);
		$meta = $pkg->extract_meta();
		file_put_contents(dirname(__FILE__) . "/packages/" . urlencode($this>name) . "/meta", serialize($meta));
		
		$this->lastversion = $pkg->versioncount;
		$this->txtversion  = $pkg->versiontext;
		$this->description = $pkg->short_description;
		$this->lastupdate  = time();
		$this->save();
		
		$update_info = array(
			"current-version" => $this->lastversion,
			"dl-path"         => $settings["root_url"] . "/packages/" . urlencode($this->name) . "/versions/" . $this->lastversion;
		);
		
		file_put_contents(dirname(__FILE__) . "/packages/" . urlencode($this>name) . "/update", serialize($update_info));
		
		self::update_lists();
	}
	
	public function save()
	{
		qdb("UPDATE `PREFIX_packages` SET `lastversion` = %d, `lastupdate` = %d, `txtversion` = '%s', `description` = '%s' WHERE `id` = %d", $this->lastversion, $this->lastupdate, $this->txtversion, $this->description, $this->id);
	}
	
	public function delete()
	{
		qdb("DELETE FROM `PREFIX_packages` WHERE `id` = %d", $this->id);
		delete_directory(dirname(__FILE__) . "/packages/" . $this->name);
		self::update_lists();
	}
}

?>
