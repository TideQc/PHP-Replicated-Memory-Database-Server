# PHP Replicated Memory Database Server
Serveur TCP JSON en PHP qui conserve des documents en mémoire et réplique les écritures vers d'autres serveurs en temps réel.

## Prérequis
- Système Unix/Linux (utilise /sbin/ifconfig et sockets TCP).
- PHP >= 5.3.
- Extension sockets activée (extension=sockets dans php.ini).

## Demarrage rapide
1) Copier les fichiers sur le serveur.
2) Configurer [configs.php](configs.php).
3) Lancer le serveur:

```bash
php server.php
```

V2 (version optimisee):

```bash
php server_v2.php
```

4) Répéter l'opération sur chaque serveur réplique.

## Configuration
Dans [configs.php](configs.php):
- `server_path`: chemin absolu du dossier qui contient [server.php](server.php).
- `ip`: adresse d'ecoute (0.0.0.0 pour toutes les interfaces).
- `port`: port d'ecoute.
- `replicas`: liste des serveurs de replication (ip, port).

## Concepts
- Un document = une "table" en mémoire.
- Chaque document est un tableau associatif d'entrées indexées par une clé.
- Les requêtes et réponses sont des lignes JSON.
- Envoyer la commande texte `quit` pour fermer la connexion.

## Protocole
Chaque requete est un JSON avec au minimum `action`. Exemple:

```json
{"action": "list"}
```

Les réponses sont des JSON avec `status` = `success` ou `error`.

## Actions
### create
Créer un document vide.

```json
{"action": "create", "document": "users"}
```

### insert
Insérer des entrées. Par défaut, l'auto-increment est actif (ajout en fin de tableau).

```json
{"action": "insert", "document": "users", "data": [{"name": "John"}, {"name": "Andrew"}]}
```

Avec clés manuelles:

```json
{"action": "insert", "document": "users", "data": {"John": {"Gender": "Male"}, "Andrew": {"Gender": "Male"}}, "auto-increment": false}
```

Notes:
- Si le document contient déjà des entrées, les nouvelles doivent avoir les mêmes clés que la première entrée.

### get
Récupérer un document complet, une entrée par id, ou filtrer par query.

```json
{"action": "get", "document": "users"}
```

```json
{"action": "get", "document": "users", "id": 0}
```

```json
{"action": "get", "document": "users", "query": {"Gender": "Male"}}
```

### getkeys
Retourne les clés de la première entrée du document.

```json
{"action": "getkeys", "document": "users"}
```

### update
Mettre à jour une entrée par id, ou remplacer tout le document.

```json
{"action": "update", "document": "users", "id": 0, "data": {"name": "Johnny"}}
```

```json
{"action": "update", "document": "users", "data": [{"name": "John"}, {"name": "Andrew"}]}
```

### delete
Supprimer une entrée par id.

```json
{"action": "delete", "document": "users", "id": 0}
```

### count
Compter les entrées, avec ou sans query.

```json
{"action": "count", "document": "users"}
```

```json
{"action": "count", "document": "users", "query": {"Gender": "Male"}}
```

### empty
Vider un document.

```json
{"action": "empty", "document": "users"}
```

### drop
Supprimer un document.

```json
{"action": "drop", "document": "users"}
```

### list
Lister les documents.

```json
{"action": "list"}
```

### getall
Récupérer toutes les données en mémoire.

```json
{"action": "getall"}
```

## Réplication
- La réplication est automatique si `replicas` est configuré.
- Les requêtes réplicables acceptent le flag `replicate: false` pour éviter les boucles.
- Au démarrage, le serveur tente un `getall` sur les répliques pour initialiser ses documents.

## Exemples de reponses
Succès:

```json
{"status": "success", "documents": ["users"]}
```

Erreur:

```json
{"status": "error", "message": "No document provided."}
```

## Exemples de clients
### Telnet

```bash
telnet 127.0.0.1 8888
```

Puis envoyer une ligne JSON, par exemple:

```json
{"action": "list"}
```

### Netcat

```bash
printf '{"action":"list"}\n' | nc 127.0.0.1 8888
```

### PHP (socket TCP)

```php
<?php
$socket = fsockopen("127.0.0.1", 8888, $errno, $errstr, 2);
if (!$socket) {
	die("Connect error: $errstr ($errno)\n");
}
$payload = json_encode(array("action" => "list")) . "\n";
fwrite($socket, $payload);
echo fgets($socket);
fclose($socket);
?>
```

### Test client (rapport)

```bash
php test_client.php
```

Mode JSON:

```bash
php test_client.php --json
```

Exemple de sortie:

```text
Test Report
===========
Latency (ms): 1.23
Replicas configured: 2
Replicas reachable: 1
Replica 1.2.3.4:8888: OK
Replica 1.2.3.5:8888: FAIL - Connection refused (111)
Latency by action (ms):
- list: avg 0.90, min 0.70, max 1.10
- getall: avg 1.40, min 1.20, max 1.70
01) PASS - list returns response
02) PASS - create document
...
-----------
Passed: 15
Failed: 0
```

### Python

```python
import socket
import json

sock = socket.create_connection(("127.0.0.1", 8888), timeout=2)
payload = json.dumps({"action": "list"}) + "\n"
sock.sendall(payload.encode("utf-8"))
print(sock.recv(4096).decode("utf-8"))
sock.close()
```

## Limites et notes techniques
- Mémoire uniquement: aucune persistance disque.
- `getkeys` suppose qu'il existe au moins une entrée dans le document.
- `update` sans `id` remplace le document complet.
- Les schémas ne sont pas formalisés: la validation se base sur la première entrée du document.
- Le serveur est mono-process et utilise des sockets non bloquants.

## Sécurité et bonnes pratiques
- Ne pas exposer publiquement le port; utiliser un pare-feu/VPN.
- Ajouter un proxy TCP ou un tunnel (SSH/stunnel) si besoin de chiffrement.
- Mettre les répliques sur un réseau privé pour éviter les boucles ou injections.
- Monitorer la mémoire et redémarrer le service si besoin.

## Troubleshooting
- Le serveur ne démarre pas: vérifier `server_path` et que PHP a l'extension sockets activée.
- Aucune réplique ne se connecte: vérifier `replicas` (ip/port) et la connectivité réseau.
- `getkeys` retourne une erreur: insérer au moins une entrée dans le document.
- Erreur JSON: s'assurer d'envoyer une ligne JSON complète terminée par un saut de ligne.
- Données non réplicables: vérifier que `replicate` n'est pas forcé à `false`.

## Crédits
Fait avec amour par Michael Tétreault.
