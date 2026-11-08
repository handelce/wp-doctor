CREATE TABLE wptest_options (
	option_id bigint unsigned NOT NULL AUTO_INCREMENT,
	option_name varchar(191) NOT NULL DEFAULT '',
	option_value longtext NOT NULL,
	autoload varchar(20) NOT NULL DEFAULT 'yes',
	PRIMARY KEY (option_id),
	UNIQUE KEY option_name (option_name)
);

INSERT INTO wptest_options (option_name, option_value) VALUES
	('home', 'https://example.test'),
	('siteurl', 'https://example.test');

CREATE TABLE wptest_posts (
	ID bigint unsigned NOT NULL AUTO_INCREMENT,
	post_type varchar(20) NOT NULL DEFAULT 'post',
	post_status varchar(20) NOT NULL DEFAULT 'publish',
	post_title text NOT NULL,
	post_content longtext NOT NULL,
	post_modified_gmt datetime NOT NULL,
	PRIMARY KEY (ID)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wptest_posts (ID, post_type, post_status, post_title, post_content, post_modified_gmt) VALUES
	(1, 'post', 'publish', 'Own script', '<script src="https://example.test/a.js"></script>', '2026-01-01 00:00:00'),
	(2, 'post', 'publish', 'Foreign script', '<p>Hi</p><SCRIPT src="https://cdn.bad.invalid/a.js"></SCRIPT>', '2026-01-01 00:00:00'),
	(3, 'post', 'draft', 'Draft with a script', '<script src="https://cdn.bad.invalid/b.js"></script>', '2026-01-01 00:00:00'),
	(4, 'revision', 'inherit', 'Revision', '<script src="https://cdn.bad.invalid/c.js"></script>', '2026-01-01 00:00:00'),
	(5, 'page', 'publish', 'HACKED BY example', 'Nothing else', '2026-11-07 10:00:00'),
	(6, 'page', 'publish', 'Hacked by long ago', 'Old news', '2025-01-01 00:00:00');
