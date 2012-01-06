<?php

/* Load and check config */
require_once(dirname(__FILE__) . "/config.php");
if(!defined("CONFIG_FILLED_OUT"))
	die("Config file not filled out.");

/* Include database functions... */
require_once(dirname(__FILE__) . "/db.php");
db_connect();

/* create tables */
if(!SKIP_TABLE_CREATION)
{
	$db_structure = "CREATE TABLE IF NOT EXISTS `PREFIX_packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` text COLLATE utf8_unicode_ci NOT NULL,
  `author` text COLLATE utf8_unicode_ci NOT NULL,
  `lastversion` int(11) NOT NULL,
  `user` int(11) NOT NULL,
  `description` text COLLATE utf8_unicode_ci NOT NULL,
  `lastupdate` bigint(20) NOT NULL,
  `txtversion` text COLLATE utf8_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
CREATE TABLE IF NOT EXISTS `PREFIX_settings_kvstorage` (
  `key` text COLLATE utf8_unicode_ci NOT NULL,
  `value` text COLLATE utf8_unicode_ci NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
CREATE TABLE IF NOT EXISTS `PREFIX_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` text COLLATE utf8_unicode_ci NOT NULL,
  `pwhash` text COLLATE utf8_unicode_ci NOT NULL,
  `isadmin` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
	$queries = explode(";", $db_structure);
	foreach($queries as $q)
	{
		if(!empty($q))
			qdb($q);
	}
}

/* Include more files... */
require_once(dirname(__FILE__) . "/stupid_template_engine.php");
require_once(dirname(__FILE__) . "/models.php");
require_once(dirname(__FILE__) . "/urlprocess.php");
require_once(dirname(__FILE__) . "/pwhash.php");
require_once(dirname(__FILE__) . "/pluginpackage.php");
require_once(dirname(__FILE__) . "/utils.php");

/* init STE */
$tpl_basedir = dirname(__FILE__). "/templates";
$ste = new \ste\STECore(new \ste\FilesystemStorageAccess("$tpl_basedir/src", "$tpl_basedir/transc"));

$ste->register_tag(
	"loremipsum",
	function($ste, $params, $sub)
	{
		$repeats = empty($params["repeat"]) ? 1 : $params["repeat"] + 0;
		return implode("\n\n", array_repeat("<p>Lorem ipsum dolor sit amet, consectetur adipisici elit, sed eiusmod tempor incidunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquid ex ea commodi consequat. Quis aute iure reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint obcaecat cupiditat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p>\n\n<p>Duis autem vel eum iriure dolor in hendrerit in vulputate velit esse molestie consequat, vel illum dolore eu feugiat nulla facilisis at vero eros et accumsan et iusto odio dignissim qui blandit praesent luptatum zzril delenit augue duis dolore te feugait nulla facilisi. Lorem ipsum dolor sit amet, consectetuer adipiscing elit, sed diam nonummy nibh euismod tincidunt ut laoreet dolore magna aliquam erat volutpat.</p>\n\n<p>Ut wisi enim ad minim veniam, quis nostrud exerci tation ullamcorper suscipit lobortis nisl ut aliquip ex ea commodo consequat. Duis autem vel eum iriure dolor in hendrerit in vulputate velit esse molestie consequat, vel illum dolore eu feugiat nulla facilisis at vero eros et accumsan et iusto odio dignissim qui blandit praesent luptatum zzril delenit augue duis dolore te feugait nulla facilisi.</p>\n\n<p>Nam liber tempor cum soluta nobis eleifend option congue nihil imperdiet doming id quod mazim placerat facer possim assum. Lorem ipsum dolor sit amet, consectetuer adipiscing elit, sed diam nonummy nibh euismod tincidunt ut laoreet dolore magna aliquam erat volutpat. Ut wisi enim ad minim veniam, quis nostrud exerci tation ullamcorper suscipit lobortis nisl ut aliquip ex ea commodo consequat.</p>\n\n<p>Duis autem vel eum iriure dolor in hendrerit in vulputate velit esse molestie consequat, vel illum dolore eu feugiat nulla facilisis.</p>\n\n<p>At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, At accusam aliquyam diam diam dolore dolores duo eirmod eos erat, et nonumy sed tempor et et invidunt justo labore Stet clita ea et gubergren, kasd magna no rebum. sanctus sea sed takimata ut vero voluptua. est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat.</p>\n\n<p>Consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquyam erat, sed diam voluptua. At vero eos et accusam et justo duo dolores et ea rebum. Stet clita kasd gubergren, no sea takimata sanctus est Lorem ipsum dolor sit amet.</p>", $repeats));
	}
);

/* start session and check auth. */
session_start();
$user = NULL;
if(isset($_SESSION["r7r_repo_login_name"]))
{
	try
	{
		$user = User::by_name($_SESSION["r7r_repo_login_name"]);
	}
	catch(DoesNotExistError $e)
	{
		unset($_SESSION["r7r_repo_login_name"]);
	}
}

function package_list($pkgs, $heading)
{
	global $ste;
	
	$ste->vars["list_heading"] = $heading;
	$ste->vars["pkgs"] = array_map(function($pkg) { return array(
		"name"        => $pkg->get_name(),
		"version"     => $pkg->txtversion,
		"author"      => $pkg->author,
		"description" => $pkg->description,
		"last_update" => $pkg->lastupdate
	); }, $pkgs);
	
	return $ste->exectemplate("package_list.html");
}

/* url handlers */
$url_handlers = array(
	"_prelude" => function(&$data, $url_now, &$url_next)
	{
		global $ste, $settings, $user;
		
		if(@$settings["setup_finished"])
			$ste->vars["repo"] = array(
				"name"        => $settings["repo_name"],
				"description" => $settings["repo_description"],
				"baseurl"     => $settings["repo_baseurl"],
				"public"      => ($settings["repo_mode"] == "public")
			);
		
		if($user === NULL)
			$ste->vars["user"] = array(
				"logged_in" => False,
				"admin"     => False
			);
		else
			$ste->vars["user"] = array(
				"logged_in" => True,
				"name"      => $user->get_name(),
				"admin"     => $user->isadmin
			);
	},
	"_notfound" => url_action_simple(function($data)
	{
		header("HTTP/1.1 404 Not Found");
		header("Content-Type: text/plain");
		echo "404 Not Found\nThe resource  \"{$_SERVER["REQUEST_URI"]}\" could not be found.\n";
	}),
	"_index" => url_action_alias(array("index")),
	"index" => function(&$data, $url_now, &$url_next)
	{
		global $ste;
		$url_next = array();
		
		$ste->vars["menu"] = "home";
		
		$latest = Package::latest();
		
		echo package_list($latest, "Latest Packages");
	},
	"login" => function(&$data, $url_now, &$url_next)
	{
		global $ste, $user;
		
		if(($user === NULL) and isset($_POST["login"]))
		{
			try
			{
				$user = User::by_name($_POST["username"]);
				if(PasswordHash::validate($_POST["password"], $user->pwhash))
				{
					$ste->vars["success"] = "Logged in successfully.";
					$_SESSION["r7r_repo_login_name"] = $user->get_name();
					$url_next = array("_prelude", "index");
				}
				else
				{
					$user = NULL;
					$ste->vars["error"] = "Username / Password wrong.";
					$url_next = array("index");
				}
			}
			catch(DoesNotExistError $e)
			{
				$user = NULL;
				$ste->vars["error"] = "Username / Password wrong.";
				$url_next = array("index");
			}
		}
		else
			$url_next = array("index");
	},
	"logout" => function(&$data, $url_now, &$url_next)
	{
		global $ste, $user;
		
		if($user === NULL)
		{
			$url_next = array("index");
			return;
		}
		
		$user = NULL;
		unset($_SESSION["r7r_repo_login_name"]);
		$ste->vars["success"] = "Logged out successfully.";
		$url_next = array("_prelude", "index");
	},
	"register" => function(&$data, $url_now, &$url_next)
	{
		global $ste, $user, $settings;
		
		if($settings["repo_mode"] == "private")
			throw new NotFoundError();
		
		if($user !== NULL)
		{
			$url_next = array("index");
			return;
		}
		
		$url_next = array();
		$ste->vars["menu"]  = "register";
		$ste->vars["title"] = "Register";
		
		if(isset($_POST["register"]))
		{
			if(empty($_POST["username"]) or empty($_POST["password"]))
				$ste->vars["error"] = "Formular not filled out.";
			else
			{
				try
				{
					$u = User::by_name($_POST["username"]);
					$ste->vars["error"] = "Username already exists.";
				}
				catch(DoesNotExistError $e)
				{
					$u = User::create($_POST["username"]);
					$u->isadmin = False;
					$u->pwhash = PasswordHash::create($_POST["password"]);
					$u->save();
					$ste->vars["success"] = "Account successfully created. You can now log in.";
				}
			}
		}
		
		echo $ste->exectemplate("register.html");
	},
	"admin" => function(&$data, $url_now, &$url_next)
	{
		global $settings, $ste, $user;
		
		if(($user === NULL) or (!$user->isadmin))
			throw new NotFoundError();
		
		$url_next = array();
		$ste->vars["menu"]  = "admin";
		$ste->vars["title"] = "Administration";
		
		if(isset($_POST["save_settings"]))
		{
			$settings["repo_name"]        = $_POST["repo_name"];
			$settings["repo_description"] = $_POST["repo_description"];
			$settings["repo_baseurl"]     = $_POST["repo_baseurl"];
			
			if($_POST["repo_mode"] == "public")
				$settings["repo_mode"] = "public";
			if($_POST["repo_mode"] == "private")
				$settings["repo_mode"] = "private";
			
			update_repometa();
			
			$ste->vars["success"] = "Settings saved.";
		}
		
		if(isset($_POST["new_user"]))
		{
			if(empty($_POST["username"]) or empty($_POST["password"]))
				$ste->vars["error"] = "Formular not filled out.";
			else
			{
				try
				{
					$u = User::by_name($_POST["username"]);
					$ste->vars["error"] = "Username already exists.";
				}
				catch(DoesNotExistError $e)
				{
					$u = User::create($_POST["username"]);
					$u->isadmin = False;
					$u->pwhash = PasswordHash::create($_POST["password"]);
					$u->save();
					$ste->vars["success"] = "Account successfully created.";
				}
			}
		}
		
		if(isset($_POST["delete_users"]) and ($_POST["really_delete"] == "yes"))
		{
			foreach($_POST["users_multiselect"] as $uid)
			{
				try
				{
					$u = User::by_id($uid);
					$u->delete();
				}
				catch(DoesNotExistError $e)
				{
					continue;
				}
			}
			
			$ste->vars["success"] = "Users deleted.";
		}
		
		if(isset($_POST["make_admin"]))
		{
			foreach($_POST["users_multiselect"] as $uid)
			{
				try
				{
					$u = User::by_id($uid);
					$u->isadmin = True;
					$u->save();
				}
				catch(DoesNotExistError $e)
				{
					continue;
				}
			}
			
			$ste->vars["success"] = "Okay.";
		}
		
		if(isset($_POST["make_normal_user"]))
		{
			foreach($_POST["users_multiselect"] as $uid)
			{
				try
				{
					$u = User::by_id($uid);
					$u->isadmin = False;
					$u->save();
				}
				catch(DoesNotExistError $e)
				{
					continue;
				}
			}
			
			$ste->vars["success"] = "Okay.";
		}
		
		/* Fill data */
		$ste->vars["repo"] = array(
			"name"        => $settings["repo_name"],
			"description" => $settings["repo_description"],
			"baseurl"     => $settings["repo_baseurl"],
			"public"      => ($settings["repo_mode"] == "public")
		);
		
		$users = User::all();
		$ste->vars["users"] = array_map(function($u) { return array(
			"id"    => $u->get_id(),
			"name"  => $u->get_name(),
			"admin" => $u->isadmin
		); }, $users);
		
		echo $ste->exectemplate("admin.html");
	},
	"account" => function(&$data, $url_now, &$url_next)
	{
		global $ste, $user;
		
		if($user === NULL)
			throw new NotFoundError();
		
		$url_next = array();
		$ste->vars["menu"]  = "account";
		$ste->vars["title"] = "My Account";
		
		if(isset($_POST["set_new_password"]))
		{
			if(empty($_POST["new_password"]))
				$ste->vars["error"] = "Password must not be empty.";
			else
			{
				$user->pwhash = PasswordHash::create($_POST["new_password"]);
				$user->save();
				$ste->vars["success"] = "Password set.";
			}
		}
		
		echo $ste->exectemplate("account.html");
	},
	"p" => function(&$data, $url_now, &$url_next)
	{
		global $ste, $user;
		
		list($pkgname) = $url_next;
		$url_next = array();
		
		if(empty($pkgname))
			throw new NotFoundError();
		
		try
		{
			$pkg = Package::by_name($pkgname);
		}
		catch(DoesNotExistError $e)
		{
			throw new NotFoundError();
		}
		
		$ste->vars["title"] = $pkg->get_name();
		$admin_mode = (($user !== NULL) and (($user->isadmin) or ($user->get_id() == $pkg->get_user()->get_id())));
		
		if($admin_mode)
		{
			if(isset($_POST["delete_package"]) and ($_POST["really_delete"] == "yes"))
			{
				$pkg->delete();
				$ste->vars["success"] = "Package deleted.";
				$url_next = array("index");
				return;
			}
			
			if(isset($_POST["new_version"]))
			{
				if(is_uploaded_file($_FILES["pkgfile"]["tmp_name"]))
				{
					$raw_pkg = @file_get_contents($_FILES["pkgfile"]["tmp_name"]);
					@unlink($_FILES["pkgfile"]["tmp_name"]);
					if($raw_pkg === False)
						$ste->vars["error"] = "Upload failed.";
					else
					{
						try
						{
							$newpkg = PluginPackage::load($raw_pkg);
							$pkg->newversion($newpkg);
							$ste->vars["success"] = "Successfully uploaded new version.";
						}
						catch(InvalidPackage $e)
						{
							$ste->vars["error"] = "Invalid package. Reason: " . $e->getMessage();
						}
						catch(NotAllowedError $e)
						{
							$ste->vars["error"] = "This is not allowed. Reason: " . $e->getMessage();
						}
					}
				}
				else
					$ste->vars["error"] = "Upload failed.";
			}
		}
		
		$ste->vars["package"] = array(
			"name"        => $pkg->get_name(),
			"description" => $pkg->description,
			"author"      => $pkg->author,
			"admin_mode"  => $admin_mode,
			"version"     => $pkg->txtversion
		);
		
		echo $ste->exectemplate("package.html");
	},
	"upload" => function(&$data, $url_now, &$url_next)
	{
		global $ste, $settings, $user;
		
		if(($user === NULL) or ((!$user->isadmin) and ($settings["repo_mode"] == "private")))
			throw new NotFoundError();
		
		$url_now = array();
		$ste->vars["menu"]  = "upload";
		$ste->vars["title"] = "Upload new package";
		
		if(isset($_POST["upload_package"]))
		{
			if(is_uploaded_file($_FILES["pkgfile"]["tmp_name"]))
			{
				$raw_pkg = @file_get_contents($_FILES["pkgfile"]["tmp_name"]);
				@unlink($_FILES["pkgfile"]["tmp_name"]);
				if($raw_pkg === False)
					$ste->vars["error"] = "Upload failed.";
				else
				{
					try
					{
						$newpkg = PluginPackage::load($raw_pkg);
						$pkg = Package::create($newpkg->name, $user);
						$pkg->newversion($newpkg);
						$ste->vars["success"] = "Successfully uploaded new package.";
						$url_next = array("p", $newpkg->name);
						return;
					}
					catch(InvalidPackage $e)
					{
						$ste->vars["error"] = "Invalid package. Reason: " . $e->getMessage();
					}
					catch(NotAllowedError $e)
					{
						$ste->vars["error"] = "This is not allowed. Reason: " . $e->getMessage();
					}
					catch(InvalidArgumentException $e)
					{
						$ste->vars["error"] = $e->getMessage();
					}
					catch(AlreadyExistsError $e)
					{
						$ste->vars["error"] = "A package with this name already exists.";
					}
				}
			}
			else
				$ste->vars["error"] = "Upload failed.";
		}
		
		echo $ste->exectemplate("upload.html");
	},
	"my_packages" => function(&$data, $url_now, &$url_next)
	{
		global $ste, $user;
		
		if($user === NULL)
			throw new NotFoundError();
		
		$ste->vars["menu"] = "my_packages";
		
		$my_packages = $user->get_packages();
		
		echo package_list($my_packages, "My Packages");
	},
	"setup" => function(&$data, $url_now, &$url_next)
	{
		global $settings, $ste;
		
		/* If initial setup was already finished, nobody should be allowed to access this. */
		if(@$settings["setup_finished"])
			throw new NotFoundError();
		
		$url_next = array();
		
		/* Test file permissions */
		$permissions_missing = array_filter(array("/packages", "/packagelist", "/repometa"), function($f) { return !@is_writable(dirname(__FILE__) . "/..$f"); });
		if(!empty($permissions_missing))
			$ste->vars["error"] = "No writing permissions on these files/directories: \"" . implode("\", \"", $permissions_missing) . "\"";
		else
		{
			/* Check input */
			if(!empty($_POST["send_data"]))
			{
				if(empty($_POST["admin_name"]) or empty($_POST["admin_password"]) or empty($_POST["repo_name"]) or empty($_POST["repo_description"]) or empty($_POST["repo_baseurl"]) or (($_POST["repo_mode"] != "public") and ($_POST["repo_mode"] != "private")))
					$ste->vars["error"] = "Form not filled out completely";
				else
				{
					/* Insert data */
					$admin = User::create($_POST["admin_name"]);
					$admin->pwhash = PasswordHash::create($_POST["admin_password"]);
					$admin->isadmin = True;
					$admin->save();
					$settings["repo_name"]        = $_POST["repo_name"];
					$settings["repo_description"] = $_POST["repo_description"];
					$settings["repo_baseurl"]     = $_POST["repo_baseurl"];
					$settings["repo_mode"]        = $_POST["repo_mode"];
					
					update_repometa();
					
					$settings["setup_finished"] = True;
					
					$url_next = array("index");
					return;
				}
			}
		}
		
		$ste->vars["baseurl_predicted"] = self_url();
		echo $ste->exectemplate("setup.html");
	}
);

/* bootstrapping... */
$urlpath = explode("/", $_GET["action"]);
$rel_path_to_root = implode("/", array_merge(array("."), array_repeat("..", count($urlpath) - 1)));
$GLOBALS["rel_path_to_root"] = $rel_path_to_root;
$data = array("rel_path_to_root" => $rel_path_to_root);
$ste->vars["rel_path_to_root"] = $rel_path_to_root;
/* Enforce setup */
if(!@$settings["setup_finished"])
	$urlpath = array("setup");
/*try
{*/
	url_process($urlpath, $url_handlers, $data);
/*}
catch(Exception $e)
{
	header("HTTP/1.1 500 Internal Server Error");
	header("Content-Type: text/plain");
	echo "Internal Server Error\nReason: " . get_class($e) . "(" . $e->getMessage() . ") thrown.\n";
}*/

/* save settings */
$settings->save();

?>
