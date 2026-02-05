# PHP Replicated Memory Database Server
Serveur TCP JSON en PHP qui conserve des documents en memoire et replique les ecritures vers d'autres serveurs en temps reel.

## Prerequis
- Systeme Unix/Linux (utilise /sbin/ifconfig et sockets TCP).
- PHP >= 5.3.

## Demarrage rapide
1) Copier les fichiers sur le serveur.
2) Configurer [configs.php](configs.php).
3) Lancer le serveur:

```bash
php server.php
```

4) Repeter l'operation sur chaque serveur replica.

## Configuration
Dans [configs.php](configs.php):
- `server_path`: chemin absolu du dossier qui contient [server.php](server.php).
- `ip`: adresse d'ecoute (0.0.0.0 pour toutes les interfaces).
- `port`: port d'ecoute.
- `replicas`: liste des serveurs de replication (ip, port).

## Concepts
- Un document = une "table" en memoire.
- Chaque document est un tableau associatif d'entrees indexees par une cle.
- Les requetes et reponses sont des lignes JSON.
- Envoyer la commande texte `quit` pour fermer la connexion.

## Protocole
Chaque requete est un JSON avec au minimum `action`. Exemple:

```json
{"action": "list"}
```

Les reponses sont des JSON avec `status` = `success` ou `error`.

## Actions
### create
Creer un document vide.

```json
{"action": "create", "document": "users"}
```

### insert
Inserer des entrees. Par defaut, l'auto-increment est actif (ajout en fin de tableau).

```json
{"action": "insert", "document": "users", "data": [{"name": "John"}, {"name": "Andrew"}]}
```

Avec cles manuelles:

```json
{"action": "insert", "document": "users", "data": {"John": {"Gender": "Male"}, "Andrew": {"Gender": "Male"}}, "auto-increment": false}
```

Notes:
- Si le document contient deja des entrees, les nouvelles doivent avoir les memes cles que la premiere entree.

### get
Recuperer un document complet, une entree par id, ou filtrer par query.

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
Retourne les cles de la premiere entree du document.

```json
{"action": "getkeys", "document": "users"}
```

### update
Mettre a jour une entree par id, ou remplacer tout le document.

```json
{"action": "update", "document": "users", "id": 0, "data": {"name": "Johnny"}}
```

```json
{"action": "update", "document": "users", "data": [{"name": "John"}, {"name": "Andrew"}]}
```

### delete
Supprimer une entree par id.

```json
{"action": "delete", "document": "users", "id": 0}
```

### count
Compter les entrees, avec ou sans query.

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
Recuperer toutes les donnees en memoire.

```json
{"action": "getall"}
```

## Replication
- La replication est automatique si `replicas` est configure.
- Les requetes replicables acceptent le flag `replicate: false` pour eviter les boucles.
- Au demarrage, le serveur tente un `getall` sur les replicas pour initialiser ses documents.

## Exemples de reponses
Succes:

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
- Memoire uniquement: aucune persistence disque.
- `getkeys` suppose qu'il existe au moins une entree dans le document.
- `update` sans `id` remplace le document complet.
- Les schemas ne sont pas formalises: la validation se base sur la premiere entree du document.
- Le serveur est mono-process et utilise des sockets non bloquants.

## Securite et bonnes pratiques
- Ne pas exposer publiquement le port; utiliser un pare-feu/VPN.
- Ajouter un proxy TCP ou un tunnel (SSH/stunnel) si besoin de chiffrement.
- Mettre les replicas sur un reseau prive pour eviter les boucles ou injections.
- Monitorer la memoire et redemarrer le service si besoin.

## Troubleshooting
- Le serveur ne demarre pas: verifier `server_path` et que PHP a l'extension sockets activee.
- Aucun replica ne se connecte: verifier `replicas` (ip/port) et la connectivite reseau.
- `getkeys` retourne une erreur: inserer au moins une entree dans le document.
- Erreur JSON: s'assurer d'envoyer une ligne JSON complete terminee par un saut de ligne.
- Donnees non replicables: verifier que `replicate` n'est pas force a `false`.
