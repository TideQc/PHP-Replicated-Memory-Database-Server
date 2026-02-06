<?php
	/*
	 * TCP Socket and Memory Database server (V2)
	 */
	error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
	$configs = include("configs.php");
	$server_path = $configs["server_path"];
	if(!$server_path || $server_path === "/path/of/the/server") {
		$server_path = __DIR__;
	}
	set_include_path($server_path);
	require($server_path . "/lib/memoryDatabase.class.v2.php");
	$memDbServer = new MemoryDatabaseServerV2();
?>
