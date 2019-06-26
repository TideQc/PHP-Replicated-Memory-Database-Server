# PHP-Replicated-Memory-Database-Server
An easy to use PHP TCP JSON Memory Database server that has the ability to Replicate on multiple other servers in real time.

# Pre-Requisis
1) The server must RUN on a Unix based system (RedHat, CentOS, Fedora, Ubuntu).
2) PHP >= 5.3

# FYI
  The requests and responses are all in JSON format.

# Installation
1) Copy the files anywhere you want in your server.
2) Edit the configs.php file
  "ip": The ip to listen to, 0.0.0.0 by deault to listen on all interfaces.
  "port": The port to listen to.
  "replicas": The other servers IPs and Ports that the data will be replicated.
3) Go to your files path and run the server.php in command line.
  "php server.php"
4) Do the same on the other servers if you need to use replication.

# Description
  1) A database is known as a document.
  2) Each documents are storing their values/arrays/objects in a unique id (key). 

# Usage
  // Create Document
    {"action": "create", "document": "users"}

  // Insert entries with automatic keys ("auto-increment" is true by default)
    {"action": "insert", "document": "users", "data": [{"name": "John"}, {"name": "Andrew"}], "auto-increment": true}

  // Insert entries with dynamic keys
    {"action": "insert", "document": "users", "data": {"John": {"Gender": "Male", "Job": "Developper"}, "Andrew": {"Gender": "Male", "Job": "Developper"}}, "auto-increment": false}

  // Get all entries of a document
    {"action": "get", "document": "users"}

  // Get document keys
    {"action": "getkeys", "document": "users"}

  // Get entry by id (key)
    {"action": "get", "document": "users", "id": 0}

  // Query entries
    {"action": "get", "document": "users", "query": {"Gender": "Male"}}

  // Update a document entry
    {"action": "update", "document": "users", "id": 0, "data": {"name": "Johnny"}}

  // Update a whole document
    {"action": "update", "document": "users", "data": [{"name": "John"}, {"name": "Andrew"}]}

  // Delete single entry
    {"action": "delete", "document": "users", "id": 1000}

  // Count document entries
    {"action": "count", "document": "users"}

  // Count document entries with query
    {"action": "count", "document": "users", "query": {"Gender": "Male"}}

  // Drop/delete entire document
    {"action": "drop", "document": "users"}

  // Empty/truncate entire document
    {"action": "empty", "document": "users"}

  // List documents
    {"action": "list"}

  // Get all data from all documents
    {"action": "getall"}
