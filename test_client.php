<?php
// Test client for the memory database server.
$host = "127.0.0.1";
$port = 8888;
$timeout = 2;
$configs = include("configs.php");
$latency_iterations = 3;

function connect_to_server($host, $port, $timeout) {
    $socket = fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        die("Connect error: $errstr ($errno)\n");
    }
    stream_set_timeout($socket, $timeout);
    return $socket;
}

function read_response($socket, $timeout) {
    $response = "";
    while (!feof($socket)) {
        $line = fgets($socket);
        if ($line === false) {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta["timed_out"])) {
                break;
            }
            continue;
        }
        $response .= $line;
        if (strpos($line, "\n") !== false) {
            break;
        }
    }
    return $response;
}

function send_action($socket, $payload, $timeout) {
    $line = json_encode($payload) . "\r\n";
    $start = microtime(true);
    fwrite($socket, $line);
    $raw = read_response($socket, $timeout);
    $elapsed = (microtime(true) - $start) * 1000.0;
    if ($raw === "") {
        return array(
            "ok" => false,
            "error" => "No response",
            "raw" => "",
            "latency_ms" => $elapsed
        );
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array(
            "ok" => false,
            "error" => "Invalid JSON response",
            "raw" => $raw,
            "latency_ms" => $elapsed
        );
    }
    return array(
        "ok" => true,
        "data" => $decoded,
        "raw" => $raw,
        "latency_ms" => $elapsed
    );
}

function measure_latency_ms($socket, $timeout) {
    $start = microtime(true);
    $resp = send_action($socket, array("action" => "list"), $timeout);
    $elapsed = (microtime(true) - $start) * 1000.0;
    return array(
        "ok" => $resp["ok"],
        "ms" => $elapsed
    );
}

function check_replica_connectivity($replicas, $timeout) {
    $report = array(
        "count" => 0,
        "reachable" => 0,
        "details" => array()
    );
    if (!is_array($replicas)) {
        return $report;
    }
    $report["count"] = count($replicas);
    foreach ($replicas as $replica) {
        $ip = isset($replica["ip"]) ? $replica["ip"] : "";
        $port = isset($replica["port"]) ? $replica["port"] : "";
        $ok = false;
        $err = "";
        if ($ip !== "" && $port !== "") {
            $socket = @fsockopen($ip, $port, $errno, $errstr, $timeout);
            if ($socket) {
                $ok = true;
                fclose($socket);
            } else {
                $err = $errstr . " (" . $errno . ")";
            }
        }
        if ($ok) {
            $report["reachable"]++;
        }
        $report["details"][] = array(
            "ip" => $ip,
            "port" => $port,
            "ok" => $ok,
            "error" => $err
        );
    }
    return $report;
}

function assert_true($condition, $message) {
    if ($condition) {
        return array("pass" => true, "message" => $message);
    }
    return array("pass" => false, "message" => $message);
}

function add_latency_sample(&$metrics, $action, $ms) {
    if (!isset($metrics["actions"])) {
        $metrics["actions"] = array();
    }
    if (!isset($metrics["actions"][$action])) {
        $metrics["actions"][$action] = array();
    }
    $metrics["actions"][$action][] = $ms;
}

function measure_action_latency($socket, $payload, $timeout, $iterations) {
    $samples = array();
    for ($i = 0; $i < $iterations; $i++) {
        $resp = send_action($socket, $payload, $timeout);
        $samples[] = $resp["latency_ms"];
    }
    return $samples;
}

$socket = connect_to_server($host, $port, $timeout);
$results = array();
$document = "users_test";
$metrics = array();

// Latency
$lat = measure_latency_ms($socket, $timeout);
$metrics["latency_ms"] = $lat["ms"];
$results[] = assert_true($lat["ok"], "latency probe (list) returns response");

// Replicas connectivity
$replicas = isset($configs["replicas"]) ? $configs["replicas"] : array();
$replica_report = check_replica_connectivity($replicas, $timeout);
$metrics["replicas_configured"] = $replica_report["count"];
$metrics["replicas_reachable"] = $replica_report["reachable"];

// 1) List documents
$resp = send_action($socket, array("action" => "list"), $timeout);
add_latency_sample($metrics, "list", $resp["latency_ms"]);
$results[] = assert_true($resp["ok"], "list returns response");

// 2) Drop if exists (ignore error)
$resp = send_action($socket, array("action" => "drop", "document" => $document), $timeout);
add_latency_sample($metrics, "drop", $resp["latency_ms"]);

// 3) Create document
$resp = send_action($socket, array("action" => "create", "document" => $document), $timeout);
add_latency_sample($metrics, "create", $resp["latency_ms"]);
$results[] = assert_true($resp["ok"] && $resp["data"]["status"] === "success", "create document");

// 4) Insert entries
$data = array(
    array("name" => "John", "role" => "Dev", "gender" => "Male"),
    array("name" => "Anna", "role" => "QA", "gender" => "Female")
);
$resp = send_action($socket, array("action" => "insert", "document" => $document, "data" => $data), $timeout);
add_latency_sample($metrics, "insert", $resp["latency_ms"]);
$results[] = assert_true($resp["ok"] && $resp["data"]["status"] === "success", "insert entries");

// 5) Get document
$resp = send_action($socket, array("action" => "get", "document" => $document), $timeout);
add_latency_sample($metrics, "get", $resp["latency_ms"]);
$count = 0;
if ($resp["ok"] && isset($resp["data"]["data"]) && is_array($resp["data"]["data"])) {
    $count = count($resp["data"]["data"]);
}
$results[] = assert_true($count === 2, "get document returns 2 entries");

// 6) Get by id
$resp = send_action($socket, array("action" => "get", "document" => $document, "id" => 0), $timeout);
add_latency_sample($metrics, "get_by_id", $resp["latency_ms"]);
$name = "";
if ($resp["ok"] && isset($resp["data"]["data"][0]["name"])) {
    $name = $resp["data"]["data"][0]["name"];
}
$results[] = assert_true($name === "John", "get by id returns John");

// 7) Get keys
$resp = send_action($socket, array("action" => "getkeys", "document" => $document), $timeout);
add_latency_sample($metrics, "getkeys", $resp["latency_ms"]);
$keys_ok = false;
if ($resp["ok"] && isset($resp["data"]["document_keys"])) {
    $keys = $resp["data"]["document_keys"];
    $keys_ok = in_array("name", $keys, true) && in_array("role", $keys, true) && in_array("gender", $keys, true);
}
$results[] = assert_true($keys_ok, "getkeys includes name/role/gender");

// 8) Query
$resp = send_action($socket, array("action" => "get", "document" => $document, "query" => array("gender" => "Male")), $timeout);
add_latency_sample($metrics, "query", $resp["latency_ms"]);
$query_count = 0;
if ($resp["ok"] && isset($resp["data"]["data"]) && is_array($resp["data"]["data"])) {
    $query_count = count($resp["data"]["data"]);
}
$results[] = assert_true($query_count === 1, "query returns 1 entry");

// 9) Count
$resp = send_action($socket, array("action" => "count", "document" => $document), $timeout);
add_latency_sample($metrics, "count", $resp["latency_ms"]);
$count_val = -1;
if ($resp["ok"] && isset($resp["data"]["count"])) {
    $count_val = intval($resp["data"]["count"]);
}
$results[] = assert_true($count_val === 2, "count returns 2");

// 10) Update whole document (via id + array)
$new_data = array(
    array("name" => "John", "role" => "Lead", "gender" => "Male"),
    array("name" => "Andrew", "role" => "Dev", "gender" => "Male")
);
$resp = send_action($socket, array("action" => "update", "document" => $document, "id" => 0, "data" => $new_data), $timeout);
add_latency_sample($metrics, "update", $resp["latency_ms"]);
$results[] = assert_true($resp["ok"] && $resp["data"]["status"] === "success", "update whole document");

// 11) Delete
$resp = send_action($socket, array("action" => "delete", "document" => $document, "id" => 0), $timeout);
add_latency_sample($metrics, "delete", $resp["latency_ms"]);
$results[] = assert_true($resp["ok"] && $resp["data"]["status"] === "success", "delete entry");

// 12) Count after delete
$resp = send_action($socket, array("action" => "count", "document" => $document), $timeout);
add_latency_sample($metrics, "count_after_delete", $resp["latency_ms"]);
$count_val = -1;
if ($resp["ok"] && isset($resp["data"]["count"])) {
    $count_val = intval($resp["data"]["count"]);
}
$results[] = assert_true($count_val === 1, "count after delete returns 1");

// 13) Empty
$resp = send_action($socket, array("action" => "empty", "document" => $document), $timeout);
add_latency_sample($metrics, "empty", $resp["latency_ms"]);
$results[] = assert_true($resp["ok"] && $resp["data"]["status"] === "success", "empty document");

// 14) Drop
$resp = send_action($socket, array("action" => "drop", "document" => $document), $timeout);
add_latency_sample($metrics, "drop_final", $resp["latency_ms"]);
$results[] = assert_true($resp["ok"] && $resp["data"]["status"] === "success", "drop document");

// 15) Getall
$resp = send_action($socket, array("action" => "getall"), $timeout);
add_latency_sample($metrics, "getall", $resp["latency_ms"]);
$results[] = assert_true($resp["ok"], "getall returns response");

// Additional latency sampling per action
$action_samples = array(
    "list" => array("action" => "list"),
    "getall" => array("action" => "getall"),
    "count" => array("action" => "count", "document" => $document)
);
foreach ($action_samples as $action_name => $payload) {
    $samples = measure_action_latency($socket, $payload, $timeout, $latency_iterations);
    foreach ($samples as $sample) {
        add_latency_sample($metrics, $action_name . "_sample", $sample);
    }
}

fclose($socket);

// Report
$pass = 0;
$fail = 0;
echo "Test Report\n";
echo "===========\n";
echo "Latency (ms): " . number_format($metrics["latency_ms"], 2) . "\n";
if (isset($metrics["actions"]) && is_array($metrics["actions"])) {
    echo "Latency by action (ms):\n";
    foreach ($metrics["actions"] as $action => $samples) {
        if (!is_array($samples) || count($samples) == 0) {
            continue;
        }
        $min = min($samples);
        $max = max($samples);
        $avg = array_sum($samples) / count($samples);
        echo "- " . $action . ": avg " . number_format($avg, 2) . ", min " . number_format($min, 2) . ", max " . number_format($max, 2) . "\n";
    }
}
echo "Replicas configured: " . $metrics["replicas_configured"] . "\n";
echo "Replicas reachable: " . $metrics["replicas_reachable"] . "\n";
if (isset($replica_report["details"]) && count($replica_report["details"]) > 0) {
    foreach ($replica_report["details"] as $replica) {
        $status = $replica["ok"] ? "OK" : "FAIL";
        $target = $replica["ip"] . ":" . $replica["port"];
        $err = $replica["ok"] ? "" : " - " . $replica["error"];
        echo "Replica " . $target . ": " . $status . $err . "\n";
    }
}
foreach ($results as $index => $result) {
    $label = $result["pass"] ? "PASS" : "FAIL";
    if ($result["pass"]) {
        $pass++;
    } else {
        $fail++;
    }
    echo sprintf("%02d) %s - %s\n", $index + 1, $label, $result["message"]);
}
echo "-----------\n";
echo "Passed: $pass\n";
echo "Failed: $fail\n";
?>
