<?php
	/*
	 * Optimized V2 version of MemoryDatabaseServer (no external dependencies).
	 * This is a TCP Socket Memory Database server PHP Class.
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
	 * @date 2026-02-06
	 * @package socket
	 * @subpackage API
	*/
	require_once(__DIR__ . "/memoryDatabase.class.php");

	class MemoryDatabaseServerV2 extends MemoryDatabaseServer {
			function __construct() {
				$configs = include("configs.php");
				$this->configs = $configs;
				$this->ip = $this->configs["ip"];
				$this->port = $this->configs["port"];
				$this->localip = $this->detectLocalIp();
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
				$this->startServer();
			}

			private function detectLocalIp() {
				$ip = "";
				if(stripos(PHP_OS, "WIN") === 0) {
					$ip = gethostbyname(gethostname());
				} else {
					$ip = exec("/sbin/ifconfig eth0 | grep 'inet addr:' | cut -d: -f2 | awk '{ print $1}'");
				}
				if(!$ip) {
					$ip = gethostbyname(gethostname());
				}
				if(!$ip) {
					$ip = "127.0.0.1";
				}
				return trim($ip);
			}

			/*
			 * V2 server loop (copied from base class because start() is private).
			 */
			private function startServer() {
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
							$client_id = $clients_count + 1;
							$this->clients[$client_id] = $spawn;
							$this->console("New client " . $client_id . " connected.");
						}
					}

					// Serve clients
					if(count($this->clients)) {
						foreach($this->clients AS $k => $client) {
							$request = "";
							$flags = 0;
							if(stripos(PHP_OS, "WIN") !== 0) {
								if(!defined("MSG_DONTWAIT")) {
									define("MSG_DONTWAIT", 0x40);
								}
								$flags = MSG_DONTWAIT;
							}
							$bytes = @socket_recv($client, $request, 1048576, $flags);
							if($bytes === false) {
								$err = socket_last_error($client);
								if(!defined("SOCKET_EWOULDBLOCK")) {
									define("SOCKET_EWOULDBLOCK", 10035);
								}
								if($err != SOCKET_EWOULDBLOCK && $err != 11) {
									// silent by default
								}
								socket_clear_error($client);
								continue;
							}
							if($bytes === 0) {
								$this->disconnectClient($k);
								continue;
							} else {
								$resp = array(
									"status" => "success"
								);

								if($request) {
									$request = trim($request);
									if($request !== "quit") {
										$request = $this->json_validate($request);
										if($request["status"] == "success") {
											$request = $request["result"];
											if(!isset($request["action"])) {
												$resp["status"] = "error";
												$resp["message"] = "No action key provided.";
												socket_write($client, json_encode($resp) . "\n\r", strlen(json_encode($resp) . "\n\r")).chr(0);
											} else {
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
													$method = $actions[$request["action"]];
													$resp = $this->$method($params);
													$response_json = json_encode($resp) . "\n\r";
													socket_write($client, $response_json, strlen($response_json));
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

			// Disconnect client (log connection close)
			function disconnectClient($clientKey) {
				if(isset($this->clients[$clientKey])) {
					$this->console("Client " . $clientKey . " disconnected.");
				}
				socket_close($this->clients[$clientKey]);
				unset($this->clients[$clientKey]);
			}
		// get - Get a document data method (optimized query loop)
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
					// Query fields (single pass)
					if(isset($params["query"])) {
						$query = $params["query"];
						foreach($this->database[$params["document"]] AS $k => $obj) {
							$match = true;
							foreach($query AS $field => $value) {
								if(!isset($obj[$field]) || $obj[$field] != $value) {
									$match = false;
									break;
								}
							}
							if($match) {
								$resp["data"][$k] = $obj;
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

		// insert - Add data into a document - applies to replicas if any (fewer schema key scans)
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
					$schema_keys = array();
					if(count($this->database[$params["document"]]) > 0 && isset($this->database[$params["document"]][0])) {
						$schema_keys = array_keys($this->database[$params["document"]][0]);
					}
					$use_auto_increment = true;
					if(isset($params["auto-increment"])) {
						$use_auto_increment = $params["auto-increment"] ? true : false;
					}

					foreach($params["data"] AS $key => $obj) {
						if(count($schema_keys) > 0) {
							$data_keys = array_keys($obj);
							$diff = array_diff($data_keys, $schema_keys);
							if(count($diff) != 0) {
								$resp["status"] = "error";
								$resp["message"] = "The required keys for this document are: " . implode(", ", $schema_keys) . ", received: " . implode(", ", $data_keys) . ".";
								return $resp;
							}
						}

						if($use_auto_increment) {
							$this->database[$params["document"]][] = $obj;
							$resp["data_interted"][] = $obj;
							if(count($schema_keys) == 0) {
								$schema_keys = array_keys($obj);
							}
						} else {
							$this->database[$params["document"]][$key] = $obj;
							$resp["data_interted"][$key] = $obj;
						}
					}
				} else {
					$resp["status"] = "error";
					$resp["message"] = "Data must be provided in array.";
					return $resp;
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

		// update - Update document entry - applies to replicas if any (fewer schema key scans)
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
					if(count($this->database[$params["document"]]) > 0 && isset($this->database[$params["document"]][0])) {
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

		// count - count document entries (single pass)
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
					$query = $params["query"];
					$count = 0;
					foreach($this->database[$params["document"]] AS $k => $obj) {
						$match = true;
						foreach($query AS $field => $value) {
							if(!isset($obj[$field]) || $obj[$field] != $value) {
								$match = false;
								break;
							}
						}
						if($match) {
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
	}
?>
