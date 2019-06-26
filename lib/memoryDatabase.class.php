<?php	
	/* This is a TCP Socket Memory Database server PHP Class.
	 * This file holds associatives arrays in memory 
	 * that can be shared in JSON to clients requesters over TCP sockets.
	 *
	 * Clients Commands
	 * quit (to close the connection)
	 *
	 * Request must be JSON encoded strings.
	 *
	 * Available data documents in memory are stored in $database
	 *
	 * @author Michael Tétreault
	 * @date 2019-06-26
	 * @package socket
	 * @subpackage API
	*/
	class MemoryDatabaseServer {
		/*
		 * Configs
		*/
		public $configs;
		
		/*
		 * Local Server
		*/
		public $ip;
		public $port;
		public $socket;
		public $clients;
		public $localip;
		
		/*
		 * Replication sockets
		*/
		public $replicas_sockets;
		
		/*
		 * Memory Database holding dynamic documents
		*/
		public $database = array();
		
		/*
		 * Pub/Sub channels ( TO DO )
		*/
		public $channels = array(
			"public_channel" => array()
		);
		
		function __construct() {
			$configs = include("configs.php");
			$this->configs = $configs;
			$this->ip 	= $this->configs["ip"];
			$this->port = $this->configs["port"];
			$this->localip = exec("/sbin/ifconfig eth0 | grep 'inet addr:' | cut -d: -f2 | awk '{ print $1}'");
			$this->replicas_sockets = array();
			
			
			// Connect to replicas and keep connections open
			if(count($this->configs["replicas"]) > 0) {
				$this->console("Local IP: " . $this->localip . ".");
				foreach($this->configs["replicas"] AS $k => $replica) {
					if(isset($replica["ip"]) && isset($replica["port"]) && $replica["ip"] != $this->localip) {
						if($replica["ip"] != "" && $replica["port"] != "") {
							$this->replicas_sockets[$replica["ip"]] = stream_socket_client("tcp://" . $replica["ip"] . ":" . $replica["port"]);
							if(!$this->replicas_sockets[$replica["ip"]]) {
								$this->console("Could not connect to replica " . $replica["ip"] . " on port " . $replica["port"] . ".\n");
							} else {
								$this->console("Connected to replica " . $replica["ip"] . " on port " . $replica["port"] . ".\n");
								$documents = stream_socket_sendto($this->replicas_sockets[$replica["ip"]], json_encode(array(
									"action" => "getall",
									"replicate" => false
								)));
								$response = "";
								while($line = fgets($this->replicas_sockets[$replica["ip"]])) {
									$response .= $line;
									if(strstr($line, PHP_EOL)) {
										break;
									}
								}
								$documents = $this->json_validate($response);
								if($documents["status"] == "success") {
									if(isset($documents["result"]["documents"])) {
										foreach($documents["result"]["documents"] AS $document => $data) {
											if(!isset($this->database[$document])) {
												$this->database[$document] = $data;
												$this->console("Replicated document " . $document . ".");
											}
										}
										$this->console("Documents received from replica " . $replica["ip"] . " and stored successfully.");
									} else {
										$this->console("No documents key received from replica " . $replica["ip"] . ".");
									}
								} else {
									$this->console("getall error from replica: " . $replica["ip"] . ". Reason: " . $documents["message"] . "\n");
								}
							}
						}
					}
				}
			}
			$this->clients = array();
			$this->start();
		}
		
		function __destruct() {
			if(is_resource($this->socket)) {
				socket_close($this->socket);
			}
			foreach($this->replicas_sockets AS $k => $replica_socket) {
				if($replica_socket) {
					stream_socket_shutdown($replica_socket, STREAM_SHUT_RDWR);
				}
			}
		}
		
		/*
		* Function to start the Memory Database Server
		*/
		private function start() {
			set_time_limit(0);
			ob_implicit_flush();
			ignore_user_abort(true);

			// Create socket & pool
			$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			$request = null;
			
			// action => method to execute
			$actions = array(
				"list" => "listDocuments",
				"getall" => "getDocuments",
				"get" => "getDocumentData",
				"getkeys" => "getDocumentKeys",
				"insert" => "insertDocumentData", 
				"update" => "updateDocumentData", 
				"delete" => "deleteDocumentData", 
				"create" => "createDocument",
				"empty" => "emptyDocument",
				"drop" => "dropDocument",
				"count" => "countDocument"
			);
			
			// Set reuse address socket option
			if(socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1) === false) {
				$this->socket_error("socket_set_option", $this->socket);
			}

			// Set nonblocking mode for file descriptor
			if(socket_set_nonblock($this->socket) === false) {
				$this->socket_error("socket_set_nonblock", $this->socket);
			}

			// Bind socket to port
			if(socket_bind($this->socket, $this->ip, $this->port) === false) {
				$this->socket_error("socket_bind", $this->socket);
			}

			// Start listening for connections
			if(socket_listen($this->socket, SOMAXCONN) === false) {
				$this->socket_error("socket_listen", $this->socket);
			}
			
			// Loop continuously
			while(true) {
				
				// Accept a connection on created socket
				if($spawn = @socket_accept($this->socket)) {
					if(is_resource($spawn)) {
						$clients_count = count($this->clients);
						$client_id = $clients_count+1;
						
						//$this->console("New client " . $client_id . " connected.");
						
						$this->clients[$client_id] = $spawn;
					}
				}
				
				// Serve clients
				if(count($this->clients)) {
					foreach($this->clients AS $k => $client) {
						if(!defined("MSG_DONTWAIT")) {
							define("MSG_DONTWAIT", 0x40);
						}
						if(@socket_recv($client, $request, 1048576, MSG_DONTWAIT) === 0) {
							$this->disconnectClient($k);
						} else {
							
							// Response array
							$resp = array(
								"status" => "success"
							);
							
							if($request) {
								
								// Write client request in console
								//$this->console("Client: " . $k . " sent: " . $request);
								$request = trim($request);
								
								// Check if user has requested to quit
								if($request !== "quit") {
									// Validate request is in json
									$request = $this->json_validate($request);
									
									// Valid JSON?
									if($request["status"] == "success") {
										
										// Now get the request
										$request = $request["result"];
											
										// Validate if action exist
										if(!isset($request["action"])) {
											$resp["status"] = "error";
											$resp["message"] = "No action key provided.";
											socket_write($client, json_encode($resp) . "\n\r", strlen(json_encode($resp) . "\n\r")).chr(0);
										} else {
											
											// Validate if action is valid
											if(!isset($actions[$request["action"]])) {
												$resp["status"] = "error";
												$resp["message"] = "The action '" . $request["action"] . "' provided is not valid.";
												socket_write($client, json_encode($resp) . "\n\r", strlen(json_encode($resp) . "\n\r")).chr(0);
											} else {
												$params = array();
												
												if(isset($request["document"])) {
													$params["document"] = $request["document"];
												}
												if(isset($request["id"])) {
													$params["id"] = $request["id"];
												}
												if(isset($request["query"])) {
													$params["query"] = $request["query"];
												}
												if(isset($request["data"])) {
													$params["data"] = $request["data"];
												}
												if(isset($request["auto-increment"])) {
													$params["auto-increment"] = $request["auto-increment"];
												}
												if(isset($request["replicate"])) {
													$params["replicate"] = $request["replicate"];
												}
												$resp = $this->$actions[$request["action"]]($params);
												socket_write($client, json_encode($resp) . "\n\r", strlen(json_encode($resp) . "\n\r")).chr(0);
												/*
												if(isset($resp["replicated"]) && !$resp["replicated"]) {
													$this->disconnectClient($k);
												}
												*/
											}
										}
									} else {
										$resp["status"] = "error";
										$resp["message"] = $request["message"];
										socket_write($client, json_encode($resp) . "\n\r", strlen(json_encode($resp) . "\n\r")).chr(0);
									}
								} else {
									$this->disconnectClient($k);
								}
							}
						}
					}
				}
				// No clients, flush and sleep a sec
				flush();
				usleep(1000);
			}
			socket_close($this->socket);
		}
		
		// Console messages
		function console($string) {
			echo date("Y-m-d H:i:s") . ": " . $string . "\n";
		}
		
		// For Debugging
		function socket_error($error, $socket) {
			$errMsg = socket_strerror(socket_last_error($socket));
			echo $errMsg . "\n";
		}
		
		// Disconnect client
		function disconnectClient($clientKey) {
			//$this->console("Server unsetting client: " . $this->clients[$clientKey] . " and closing socket.");
			socket_close($this->clients[$clientKey]);
			unset($this->clients[$clientKey]);
		}
		
		// getall - get all documents available in database
		function getDocuments($params) {
			$resp = array(
				"status" => "success",
				"documents" => $this->database
			);
			
			if(!isset($params["replicate"]) || $params["replicate"]) {
				$resp["replicated"] = true;
			} else {
				$resp["replicated"] = false;
			}
			
			return $resp;
		}
		
		// list - list documents available in database
		function listDocuments($params) {
			$resp = array(
				"status" => "success",
				"documents" => array()
			);
			
			foreach($this->database AS $k => $document) {
				$resp["documents"][] = $k;
			}
			return $resp;
		}
		
		// get - Get a document data method
		function getDocumentData($params) {
			$resp = array(
				"status" => "success"
			);
			
			// Validate required parameters
			if(!isset($params["document"])) {
				$resp["status"] = "error";
				$resp["message"] = "No document provided.";
				return $resp;
			}
			
			if(isset($this->database[$params["document"]])) {
				if(isset($params["id"]) && is_numeric($params["id"])) {
					// By key
					if(isset($this->database[$params["document"]][intval($params["id"])])) {
						$resp["data"][intval($params["id"])] = $this->database[$params["document"]][intval($params["id"])];
					}
				} else {
					// Query fields
					if(isset($params["query"])) {
						$matched_keys = array();
						foreach($params["query"] AS $field => $value) {
							foreach($this->database[$params["document"]] AS $k => $obj) {
								if(isset($obj[$field]) && $obj[$field] == $value) {
									if(!isset($matched_keys[$k])) {
										$matched_keys[$k] = 1;
									} else {
										$matched_keys[$k]++;
									}
								}
							}
						}
						foreach($matched_keys AS $key => $match_count) {
							if($match_count == count($params["query"])) {
								$resp["data"][$key] = $this->database[$params["document"]][$key];
							}
						}
					} else {
						// Whole document
						$resp["data"] = $this->database[$params["document"]];
					}
				}
			} else {
				$resp["status"] = "error";
				$resp["message"] = "Document provided does not exist.";
			}
			return $resp;
		}
		
		// getkeys - Get a document keys method
		function getDocumentKeys($params) {
			$resp = array(
				"status" => "success"
			);
			
			// Validate required parameters
			if(!isset($params["document"])) {
				$resp["status"] = "error";
				$resp["message"] = "No document provided.";
				return $resp;
			}
			
			if(isset($this->database[$params["document"]])) {
				$document_keys = array_keys($this->database[$params["document"]]);
				$firstKey = $document_keys[0];
				$resp["document_keys"] = array_keys($this->database[$params["document"]][$firstKey]);
				$resp["document_first_key"] = $firstKey;
			} else {
				$resp["status"] = "error";
				$resp["message"] = "Document provided does not exist.";
			}
			return $resp;
		}
		
		// insert - Add data into a document - applies to replicas if any
		function insertDocumentData($params) {
			$resp = array(
				"status" => "success"
			);
			
			// Validate required parameters
			if(!isset($params["document"])) {
				$resp["status"] = "error";
				$resp["message"] = "No document provided.";
				return $resp;
			}
			if(!isset($params["data"])) {
				$resp["status"] = "error";
				$resp["message"] = "No data provided.";
				return $resp;
			}
			
			if(isset($this->database[$params["document"]])) {
				if(is_array($params["data"])) {
					foreach($params["data"] AS $key => $obj) {
						$document_keys = array();
						if(count($this->database[$params["document"]]) > 0) {
							$document_keys = array_keys($this->database[$params["document"]][0]);
						}
						if(count($document_keys) > 0) {
							$data_keys = array_keys($obj);
							$diff = array_diff($data_keys, $document_keys);
							if(count($diff) == 0) {
								if(isset($params["auto-increment"])) {
									if($params["auto-increment"]) {
										$this->database[$params["document"]][] = $obj;
										$resp["data_interted"][] = $obj;
									} else {
										$this->database[$params["document"]][$key] = $obj;
										$resp["data_interted"][$key] = $obj;
									}
								} else {
									$this->database[$params["document"]][] = $obj;
									$resp["data_interted"][] = $obj;
								}
							} else {
								$resp["status"] = "error";
								$resp["message"] = "The required keys for this document are: " . implode(", ", $document_keys) . ", received: " . implode(", ", $data_keys) . ".";
								return $resp;
							}
						} else {
							if(isset($params["auto-increment"])) {
								if($params["auto-increment"]) {
									$this->database[$params["document"]][] = $obj;
									$resp["data_interted"][] = $obj;
								} else {
									$this->database[$params["document"]][$key] = $obj;
									$resp["data_interted"][$key] = $obj;
								}
							} else {
								$this->database[$params["document"]][] = $obj;
								$resp["data_interted"][] = $obj;
							}
						}
					}
				} else {
					$resp["status"] = "error";
					$resp["message"] = "Data must be provided in array.";
					return $resp;
				}
				//$this->database[$params["document"]] = array_values($this->database[$params["document"]]);
				
				if(!isset($params["replicate"]) || $params["replicate"]) {
					if(count($this->configs["replicas"]) > 0) {
						foreach($this->configs["replicas"] AS $k => $replica) {
							if(isset($replica["ip"]) && isset($replica["port"]) && $replica["ip"] != $this->localip) {
								if($replica["ip"] != "" && $replica["port"] != "") {
									if(!$this->replicas_sockets[$replica["ip"]]) {
										$this->replicas_sockets[$replica["ip"]] = stream_socket_client("tcp://" . $replica["ip"] . ":" . $replica["port"]);
									}	
									if(!$this->replicas_sockets[$replica["ip"]]) {
										$this->console("Could not sync to replica " . $replica["ip"] . " on port " . $replica["port"] . ".\n");
									} else {
										$request = array(
											"action" => "insert",
											"document" => $params["document"],
											"data" => $params["data"],
											"replicate" => false
										);
										if(isset($params["auto-increment"])) {
											$request["auto-increment"] = $params["auto-increment"];
										}
										$insert = stream_socket_sendto($this->replicas_sockets[$replica["ip"]], json_encode($request));
										$response = "";
										while($line = fgets($this->replicas_sockets[$replica["ip"]])) {
											$response .= $line;
											if(strstr($line, PHP_EOL)) {
												break;
											}
										}
										$insert = $this->json_validate($response);
										if($insert["status"] != "success") {
											$this->console("Could not insert entry in document on replica " . $replica["ip"] . " on port " . $replica["port"] . ". Reason:" . $insert["message"] . " \n");
										}
									}
								}
							}
						}
						$resp["replicated"] = true;
					}
				} else {
					$resp["replicated"] = false;
				}
				
			} else {
				$resp["status"] = "error";
				$resp["message"] = "Document provided does not exist.";
			}
			return $resp;
		}
		
		// update - Update document entry - applies to replicas if any
		function updateDocumentData($params) {
			$resp = array(
				"status" => "success"
			);
			
			// Validate required parameters
			if(!isset($params["document"])) {
				$resp["status"] = "error";
				$resp["message"] = "No document provided.";
				return $resp;
			}
			if(!isset($params["data"])) {
				$resp["status"] = "error";
				$resp["message"] = "No data provided.";
				return $resp;
			}
			
			if(isset($this->database[$params["document"]])) {
				if(isset($this->database[$params["document"]][intval($params["id"])])) {
					$document_keys = array();
					if(count($this->database[$params["document"]]) > 0) {
						$document_keys = array_keys($this->database[$params["document"]][0]);
					}
					$resp["document_keys"] = $document_keys;
					if(is_array($params["data"])) {
						if(!isset($params["id"]) || !is_numeric($params["id"])) {
							$updated_data = array();
							$data_keys = array_keys($params["data"]);
							$diff = array_diff($data_keys, $document_keys);
							if(count($diff) == 0) {
								foreach($params["data"] AS $key => $obj) {
									if($this->database[$params["document"]][intval($params["id"])][$key] != $obj) {
										$this->database[$params["document"]][intval($params["id"])][$key] = $obj;
										$updated_data[intval($params["id"])][$key] = $obj;
									}
								}
							} else {
								$resp["status"] = "error";
								$resp["message"] = "The required keys for this document are: " . implode(", ", $document_keys) . ", received: " . implode(", ", $data_keys) . ".";
								return $resp;
							}
						} else {
							$this->database[$params["document"]] = $params["data"];
						}
						
						if(!isset($params["replicate"]) || $params["replicate"]) {
							if(count($this->configs["replicas"]) > 0) {
								foreach($this->configs["replicas"] AS $k => $replica) {
									if(isset($replica["ip"]) && isset($replica["port"]) && $replica["ip"] != $this->localip) {
										if($replica["ip"] != "" && $replica["port"] != "") {
											if(!$this->replicas_sockets[$replica["ip"]]) {
												$this->replicas_sockets[$replica["ip"]] = stream_socket_client("tcp://" . $replica["ip"] . ":" . $replica["port"]);
											}
											if(!$this->replicas_sockets[$replica["ip"]]) {
												$this->console("Could not sync to replica " . $replica["ip"] . " on port " . $replica["port"] . ".\n");
											} else {
												$request = array(
													"action" => "update",
													"document" => $params["document"],
													"data" => $params["data"],
													"replicate" => false
												);
												
												if(isset($params["id"])) {
													$request["id"] = intval($params["id"]);
												}
												
												$update = stream_socket_sendto($this->replicas_sockets[$replica["ip"]], json_encode($request));
												$response = "";
												while($line = fgets($this->replicas_sockets[$replica["ip"]])) {
													$response .= $line;
													if(strstr($line, PHP_EOL)) {
														break;
													}
												}
												$response = $this->json_validate($response);
												if($response["status"] != "success") {
													$this->console("Could not update document entry on replica " . $replica["ip"] . " on port " . $replica["port"] . ". Reason:" . $update["message"] . " \n");
												}
											}
										}
									}
								}
								$resp["replicated"] = true;
							}
						} else {
							$resp["replicated"] = false;
						}
						$resp["updated_data"] = $updated_data;
					} else {
						$resp["status"] = "error";
						$resp["message"] = "Data must be provided in array.";
						return $resp;
					}
				} else {
					$resp["status"] = "error";
					$resp["message"] = "Key:id provided does not exist in document.";
				}
			} else {
				$resp["status"] = "error";
				$resp["message"] = "Document provided does not exist.";
			}
			return $resp;
		}
		
		// delete - Delete document entry - applies to replicas if any
		function deleteDocumentData($params) {
			$resp = array(
				"status" => "success"
			);
			
			// Validate required parameters
			if(!isset($params["document"])) {
				$resp["status"] = "error";
				$resp["message"] = "No document provided.";
				return $resp;
			}
			if(!isset($params["id"]) || !is_numeric($params["id"])) {
				$resp["status"] = "error";
				$resp["message"] = "No id provided or not numeric value.";
				return $resp;
			}
			
			if(isset($this->database[$params["document"]])) {
				if(isset($this->database[$params["document"]][intval($params["id"])])) {
					unset($this->database[$params["document"]][intval($params["id"])]);
					$this->database[$params["document"]] = array_values($this->database[$params["document"]]);
					
					if(!isset($params["replicate"]) || $params["replicate"]) {
						if(count($this->configs["replicas"]) > 0) {
							foreach($this->configs["replicas"] AS $k => $replica) {
								if(isset($replica["ip"]) && isset($replica["port"]) && $replica["ip"] != $this->localip) {
									if($replica["ip"] != "" && $replica["port"] != "") {
										if(!$this->replicas_sockets[$replica["ip"]]) {
											$this->replicas_sockets[$replica["ip"]] = stream_socket_client("tcp://" . $replica["ip"] . ":" . $replica["port"]);
										}
										if(!$this->replicas_sockets[$replica["ip"]]) {
											$this->console("Could not sync to replica " . $replica["ip"] . " on port " . $replica["port"] . ".\n");
										} else {
											$delete = stream_socket_sendto($this->replicas_sockets[$replica["ip"]], json_encode(array(
												"action" => "delete",
												"document" => $params["document"],
												"id" => intval($params["id"]),
												"replicate" => false
											)));
											$response = "";
											while($line = fgets($this->replicas_sockets[$replica["ip"]])) {
												$response .= $line;
												if(strstr($line, PHP_EOL)) {
													break;
												}
											}
											$delete = $this->json_validate($response);
											if($delete["status"] != "success") {
												$this->console("Could not delete entry from replica " . $replica["ip"] . " on port " . $replica["port"] . ". Reason:" . $delete["message"] . " \n");
											}
										}
									}
								}
							}
							$resp["replicated"] = true;
						}
					} else {
						$resp["replicated"] = false;
					}
				} else {
					$resp["status"] = "error";
					$resp["message"] = "Key:id provided does not exist in document.";
				}
			} else {
				$resp["status"] = "error";
				$resp["message"] = "Document provided does not exist.";
			}
			return $resp;
		}
		
		// create - Create database document - applies to replicas if any
		function createDocument($params) {
			$resp = array(
				"status" => "success"
			);
			
			// Validate required parameters
			if(!isset($params["document"])) {
				$resp["status"] = "error";
				$resp["message"] = "No document provided.";
				return $resp;
			}
			
			if(!isset($this->database[$params["document"]])) {
				$this->database[$params["document"]] = array();
				
				if(!isset($params["replicate"]) || $params["replicate"]) {
					if(count($this->configs["replicas"]) > 0) {
						foreach($this->configs["replicas"] AS $k => $replica) {
							if(isset($replica["ip"]) && isset($replica["port"]) && $replica["ip"] != $this->localip) {
								if($replica["ip"] != "" && $replica["port"] != "") {
									if(!$this->replicas_sockets[$replica["ip"]]) {
										$this->replicas_sockets[$replica["ip"]] = stream_socket_client("tcp://" . $replica["ip"] . ":" . $replica["port"]);
									}
									if(!$this->replicas_sockets[$replica["ip"]]) {
										$this->console("Could not sync to replica " . $replica["ip"] . " on port " . $replica["port"] . ".\n");
									} else {
										$create = stream_socket_sendto($this->replicas_sockets[$replica["ip"]], json_encode(array(
											"action" => "create",
											"document" => $params["document"],
											"replicate" => false
										)));
										$response = "";
										while($line = fgets($this->replicas_sockets[$replica["ip"]])) {
											$response .= $line;
											if(strstr($line, PHP_EOL)) {
												break;
											}
										}
										$create = $this->json_validate($response);
										if($create["status"] != "success") {
											$this->console("Could not create document on replica " . $replica["ip"] . " on port " . $replica["port"] . ". Reason: " . $create["message"] . " \n");
											$this->console($response . "\n");
										}
									}
								}
							}
						}
						$resp["replicated"] = true;
					}
				} else {
					$resp["replicated"] = false;
				}
				
			} else {
				$resp["status"] = "error";
				$resp["message"] = "Document provided already exist.";
			}
			return $resp;
		}
		
		// drop - remove document from database - applies to replicas if any
		function dropDocument($params) {
			$resp = array(
				"status" => "success"
			);
			
			// Validate required parameters
			if(!isset($params["document"])) {
				$resp["status"] = "error";
				$resp["message"] = "No document provided.";
				return $resp;
			}
			
			if(isset($this->database[$params["document"]])) {
				unset($this->database[$params["document"]]);
				
				if(!isset($params["replicate"]) || $params["replicate"]) {
					if(count($this->configs["replicas"]) > 0) {
						foreach($this->configs["replicas"] AS $k => $replica) {
							if(isset($replica["ip"]) && isset($replica["port"]) && $replica["ip"] != $this->localip) {
								if($replica["ip"] != "" && $replica["port"] != "") {
									if(!$this->replicas_sockets[$replica["ip"]]) {
										$this->replicas_sockets[$replica["ip"]] = stream_socket_client("tcp://" . $replica["ip"] . ":" . $replica["port"]);
									}
									if(!$this->replicas_sockets[$replica["ip"]]) {
										$this->console("Could not sync to replica " . $replica["ip"] . " on port " . $replica["port"] . ".\n");
									} else {
										$drop = stream_socket_sendto($this->replicas_sockets[$replica["ip"]], json_encode(array(
											"action" => "drop",
											"document" => $params["document"],
											"replicate" => false
										)));
										$response = "";
										while($line = fgets($this->replicas_sockets[$replica["ip"]])) {
											$response .= $line;
											if(strstr($line, PHP_EOL)) {
												break;
											}
										}
										$drop = $this->json_validate($response);
										if($drop["status"] != "success") {
											$this->console("Could not drop document on replica " . $replica["ip"] . " on port " . $replica["port"] . ". Reason:" . $drop["message"] . " \n");
										}
									}
								}
							}
						}
						$resp["replicated"] = true;
					}
				} else {
					$resp["replicated"] = false;
				}
				
			} else {
				$resp["status"] = "error";
				$resp["message"] = "Document provided already exist.";
			}
			return $resp;
		}
		
		// count - count document entries
		function countDocument($params) {
			$resp = array(
				"status" => "success",
				"count" => 0
			);
			
			// Validate required parameters
			if(!isset($params["document"])) {
				$resp["status"] = "error";
				$resp["message"] = "No document provided.";
				return $resp;
			}
			
			if(isset($this->database[$params["document"]])) {
				// Query fields
				if(isset($params["query"])) {
					$count = 0;
					$matched_keys = array();
					foreach($params["query"] AS $field => $value) {
						foreach($this->database[$params["document"]] AS $k => $obj) {
							if(isset($obj[$field]) && $obj[$field] == $value) {
								if(!isset($matched_keys[$k])) {
									$matched_keys[$k] = 1;
								} else {
									$matched_keys[$k]++;
								}
							}
						}
					}
					foreach($matched_keys AS $key => $match_count) {
						if($match_count == count($params["query"])) {
							$count++;
						}
					}
					$resp["count"] = $count;
				} else {
					// Whole document count
					$resp["count"] = count($this->database[$params["document"]]);
				}
			}
			return $resp;
		}
		
		// empty - empty/truncate document entries
		function emptyDocument($params) {
			$resp = array(
				"status" => "success"
			);
			
			// Validate required parameters
			if(!isset($params["document"])) {
				$resp["status"] = "error";
				$resp["message"] = "No document provided.";
				return $resp;
			}
			
			if(isset($this->database[$params["document"]])) {
				$this->database[$params["document"]] = array();
				if(!isset($params["replicate"]) || $params["replicate"]) {
					if(count($this->configs["replicas"]) > 0) {
						foreach($this->configs["replicas"] AS $k => $replica) {
							if(isset($replica["ip"]) && isset($replica["port"]) && $replica["ip"] != $this->localip) {
								if($replica["ip"] != "" && $replica["port"] != "") {
									if(!$this->replicas_sockets[$replica["ip"]]) {
										$this->replicas_sockets[$replica["ip"]] = stream_socket_client("tcp://" . $replica["ip"] . ":" . $replica["port"]);
									}
									if(!$this->replicas_sockets[$replica["ip"]]) {
										$this->console("Could not sync to replica " . $replica["ip"] . " on port " . $replica["port"] . ".\n");
									} else {
										$drop = stream_socket_sendto($this->replicas_sockets[$replica["ip"]], json_encode(array(
											"action" => "empty",
											"document" => $params["document"],
											"replicate" => false
										)));
										$response = "";
										while($line = fgets($this->replicas_sockets[$replica["ip"]])) {
											$response .= $line;
											if(strstr($line, PHP_EOL)) {
												break;
											}
										}
										$drop = $this->json_validate($response);
										if($drop["status"] != "success") {
											$this->console("Could not empty document on replica " . $replica["ip"] . " on port " . $replica["port"] . ". Reason:" . $drop["message"] . " \n");
										}
									}
								}
							}
						}
						$resp["replicated"] = true;
					}
				} else {
					$resp["replicated"] = false;
				}
			}
			return $resp;
		}
		
		// Validate JSON function
		function json_validate($json) {
			// decode the JSON data into array
			$decode = json_decode($json, true);
			
			// Returned array
			$resp = array(
				"status" => "success"
			);
			
			// switch and check possible JSON errors
			switch(json_last_error()) {
				case JSON_ERROR_NONE:
					// JSON is valid
					$resp["result"] = $decode;
					break;
				case JSON_ERROR_DEPTH:
					$resp["status"] = "error";
					$resp["message"] = "Maximum stack depth exceeded.";
					break;
				case JSON_ERROR_STATE_MISMATCH:
					$resp["status"] = "error";
					$resp["message"] = "Underflow or the modes mismatch.";
					break;
				case JSON_ERROR_CTRL_CHAR:
					$resp["status"] = "error";
					$resp["message"] = "Unexpected control character found.";
					break;
				case JSON_ERROR_SYNTAX:
					$resp["status"] = "error";
					$resp["message"] = "Syntax error, malformed JSON. <" . $json . ">";
					break;
					// only PHP 5.3+
				case JSON_ERROR_UTF8:
					$resp["status"] = "error";
					$resp["message"] = "Malformed UTF-8 characters, possibly incorrectly encoded.";
					break;
				default:
					$resp["status"] = "error";
					$resp["message"] = "Unknown JSON error occured.";
					break;
			}
			return $resp;
		}

	}
?>
