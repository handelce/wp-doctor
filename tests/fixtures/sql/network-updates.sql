CREATE TABLE wptest_sitemeta (
	meta_id bigint unsigned NOT NULL AUTO_INCREMENT,
	site_id bigint unsigned NOT NULL DEFAULT 0,
	meta_key varchar(255) DEFAULT NULL,
	meta_value longtext,
	PRIMARY KEY (meta_id),
	KEY meta_key (meta_key(191)),
	KEY site_id (site_id)
);

INSERT INTO wptest_sitemeta (site_id, meta_key, meta_value) VALUES
	(1, '_site_transient_update_plugins', 'O:8:"stdClass":1:{s:8:"response";a:1:{s:15:"alpha/alpha.php";O:8:"stdClass":2:{s:6:"plugin";s:15:"alpha/alpha.php";s:11:"new_version";s:5:"1.5.1";}}}'),
	(1, '_site_transient_update_themes', 'O:8:"stdClass":1:{s:8:"response";a:0:{}}'),
	(1, '_site_transient_update_core', 'O:8:"stdClass":1:{s:7:"updates";a:2:{i:0;O:8:"stdClass":2:{s:8:"response";s:10:"autoupdate";s:7:"current";s:5:"6.5.5";}i:1;O:8:"stdClass":2:{s:8:"response";s:6:"latest";s:7:"current";s:5:"6.5.2";}}}'),
	(2, '_site_transient_update_plugins', 'O:8:"stdClass":1:{s:8:"response";a:1:{s:13:"zeta/zeta.php";O:8:"stdClass":2:{s:6:"plugin";s:13:"zeta/zeta.php";s:11:"new_version";s:3:"9.9";}}}');
