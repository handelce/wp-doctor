CREATE TABLE wptest_options (
	option_id bigint unsigned NOT NULL AUTO_INCREMENT,
	option_name varchar(191) NOT NULL DEFAULT '',
	option_value longtext NOT NULL,
	autoload varchar(20) NOT NULL DEFAULT 'yes',
	PRIMARY KEY (option_id),
	UNIQUE KEY option_name (option_name)
);

INSERT INTO wptest_options (option_name, option_value, autoload) VALUES
	('_site_transient_update_plugins', 'O:8:"stdClass":1:{s:8:"response";a:0:{}}', 'yes'),
	('_site_transient_update_themes', 'O:8:"stdClass":1:{s:8:"response";a:0:{}}', 'yes'),
	('_site_transient_update_core', 'O:8:"stdClass":1:{s:7:"updates";a:1:{i:0;O:8:"stdClass":2:{s:8:"response";s:6:"latest";s:7:"current";s:5:"6.5.2";}}}', 'yes');
