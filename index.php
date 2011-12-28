<?php

/* Load and check config */
require_once(dirname(__FILE__) . "/config.php");
if(!defined("CONFIG_FILLED_OUT"))
	die("Config file not filled out.");

/* Include more files... */
require_once(dirname(__FILE__) . "/stupid_template_engine.php");
require_once(dirname(__FILE__) . "/db.php");
require_once(dirname(__FILE__) . "/models.php");
require_once(dirname(__FILE__) . "/urlprocess.php");
require_once(dirname(__FILE__) . "/pwhash.php");

/* init STE */
$tpl_basedir = dirname(__FILE__). "/templates";
$ste = new \ste\STECore(new \ste\FilesystemStorageAccess("$tpl_basedir/src", "$tpl_basedir/transc"));

/* start session and check auth. */
session_start();


?>
