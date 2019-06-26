<?php	
	/*
	 * TCP Socket and Memory Database server
	*/
	error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
	$configs = include("configs.php");
	set_include_path($configs["server_path"]);
	require($configs["server_path"] . "/lib/memoryDatabase.class.php");
	$memDbServer = new MemoryDatabaseServer();
?>