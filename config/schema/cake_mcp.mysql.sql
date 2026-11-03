-- Same tables as config/Migrations/20261103000000_CreateCakeMcpTables.php, for applying by hand.

CREATE TABLE `mcp_clients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` varchar(64) NOT NULL,
  `secret_hash` varchar(64) DEFAULT NULL,
  `client_name` varchar(255) NOT NULL,
  `redirect_uris` text NOT NULL,
  `grant_types` varchar(255) NOT NULL,
  `auth_method` varchar(32) NOT NULL,
  `application_type` varchar(16) DEFAULT NULL,
  `scope` varchar(255) DEFAULT NULL,
  `metadata` text,
  `created` datetime NOT NULL,
  `last_used` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `mcp_grants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mcp_client_id` int NOT NULL,
  `user_id` varchar(64) NOT NULL,
  `scope` varchar(255) NOT NULL,
  `resource` varchar(255) NOT NULL,
  `fingerprint` varchar(64) DEFAULT NULL,
  `created` datetime NOT NULL,
  `last_used` datetime DEFAULT NULL,
  `revoked` datetime DEFAULT NULL,
  `revoked_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mcp_client_id` (`mcp_client_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `mcp_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mcp_grant_id` int NOT NULL,
  `type` varchar(16) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires` datetime NOT NULL,
  `used` datetime DEFAULT NULL,
  `revoked` datetime DEFAULT NULL,
  `data` text,
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `mcp_grant_id` (`mcp_grant_id`),
  KEY `expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
