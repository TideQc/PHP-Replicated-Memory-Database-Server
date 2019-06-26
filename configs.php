<?php

	/*
	 * PHP Memory Database Server Configurations
	*/	
	return array(
		// The path of the server.php file
		"server_path" => "/path/of/the/server",
		
		// Local host
		"ip" => "0.0.0.0", // use 0.0.0.0 to listen on all local IPs.
		"port" => 8888,
		
		/* 
		 * Remote host(s) runing the server for replication
		 * ip and port in arrays
		*/
		"replicas" => array(
			array(
				"ip" => "1.2.3.4",
				"port" => 8888
			),
			array(
				"ip" => "1.2.3.5",
				"port" => 8888
			)
		)
	);
?>
