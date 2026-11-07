CREATE TABLE wptest_options (
	option_id bigint unsigned NOT NULL AUTO_INCREMENT,
	option_name varchar(191) NOT NULL DEFAULT '',
	option_value longtext NOT NULL,
	autoload varchar(20) NOT NULL DEFAULT 'yes',
	PRIMARY KEY (option_id),
	UNIQUE KEY option_name (option_name)
);

CREATE TABLE wpd_digits (d int NOT NULL);
INSERT INTO wpd_digits (d) VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9);

-- 100 expired transients, 100 expired site transients, and one that has not expired.
INSERT INTO wptest_options (option_name, option_value, autoload)
SELECT CONCAT('_transient_timeout_example_', a.d + 10 * b.d), '1600000000', 'no' FROM wpd_digits a, wpd_digits b;
INSERT INTO wptest_options (option_name, option_value, autoload)
SELECT CONCAT('_transient_example_', a.d + 10 * b.d), 'cached', 'no' FROM wpd_digits a, wpd_digits b;
INSERT INTO wptest_options (option_name, option_value, autoload)
SELECT CONCAT('_site_transient_timeout_example_', a.d + 10 * b.d), '1600000000', 'no' FROM wpd_digits a, wpd_digits b;
INSERT INTO wptest_options (option_name, option_value, autoload)
SELECT CONCAT('_site_transient_example_', a.d + 10 * b.d), 'cached', 'no' FROM wpd_digits a, wpd_digits b;
INSERT INTO wptest_options (option_name, option_value, autoload) VALUES
	('_transient_timeout_fresh', '4000000000', 'no'),
	('_transient_fresh', 'cached', 'no');

DROP TABLE wpd_digits;
