CREATE TABLE wptest_options (
	option_id bigint unsigned NOT NULL AUTO_INCREMENT,
	option_name varchar(191) NOT NULL DEFAULT '',
	option_value longtext NOT NULL,
	autoload varchar(20) NOT NULL DEFAULT 'yes',
	PRIMARY KEY (option_id),
	UNIQUE KEY option_name (option_name)
);

INSERT INTO wptest_options (option_name, option_value, autoload) VALUES
	('_site_transient_update_plugins', 'O:8:"stdClass":3:{s:12:"last_checked";i:1700000000;s:8:"response";a:2:{s:15:"alpha/alpha.php";O:8:"stdClass":3:{s:2:"id";s:15:"w.org/plugins/x";s:6:"plugin";s:15:"alpha/alpha.php";s:11:"new_version";s:5:"1.5.1";}s:13:"zeta/zeta.php";O:8:"stdClass":3:{s:2:"id";s:15:"w.org/plugins/x";s:6:"plugin";s:13:"zeta/zeta.php";s:11:"new_version";s:3:"2.0";}}s:9:"no_update";a:1:{s:15:"other/other.php";O:8:"stdClass":0:{}}}', 'yes'),
	('_site_transient_update_themes', 'O:8:"stdClass":1:{s:8:"response";a:1:{s:16:"twentytwentyfour";a:2:{s:5:"theme";s:16:"twentytwentyfour";s:11:"new_version";s:3:"1.3";}}}', 'yes'),
	('_site_transient_update_core', 'O:8:"stdClass":1:{s:7:"updates";a:2:{i:0;O:8:"stdClass":2:{s:8:"response";s:7:"upgrade";s:7:"current";s:5:"6.5.3";}i:1;O:8:"stdClass":2:{s:8:"response";s:6:"latest";s:7:"current";s:5:"6.5.2";}}}', 'yes'),
	('active_plugins', 'a:1:{i:0;s:15:"alpha/alpha.php";}', 'yes');
