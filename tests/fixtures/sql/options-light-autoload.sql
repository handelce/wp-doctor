CREATE TABLE wptest_options (
	option_id bigint unsigned NOT NULL AUTO_INCREMENT,
	option_name varchar(191) NOT NULL DEFAULT '',
	option_value longtext NOT NULL,
	autoload varchar(20) NOT NULL DEFAULT 'yes',
	PRIMARY KEY (option_id),
	UNIQUE KEY option_name (option_name)
);

INSERT INTO wptest_options (option_name, option_value, autoload) VALUES
	('siteurl', 'https://example.test', 'yes'),
	('blogname', 'Example', 'yes'),
	('example_cache_not_loaded', REPEAT('y', 3000000), 'no');
