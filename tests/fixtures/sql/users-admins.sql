CREATE TABLE wptest_users (
	ID bigint unsigned NOT NULL AUTO_INCREMENT,
	user_login varchar(60) NOT NULL DEFAULT '',
	user_email varchar(100) NOT NULL DEFAULT '',
	user_url varchar(100) NOT NULL DEFAULT '',
	user_registered datetime NOT NULL,
	display_name varchar(250) NOT NULL DEFAULT '',
	PRIMARY KEY (ID)
);

CREATE TABLE wptest_usermeta (
	umeta_id bigint unsigned NOT NULL AUTO_INCREMENT,
	user_id bigint unsigned NOT NULL DEFAULT 0,
	meta_key varchar(255) DEFAULT NULL,
	meta_value longtext,
	PRIMARY KEY (umeta_id)
);

CREATE TABLE wptest_posts (
	ID bigint unsigned NOT NULL AUTO_INCREMENT,
	post_author bigint unsigned NOT NULL DEFAULT 0,
	post_type varchar(20) NOT NULL DEFAULT 'post',
	PRIMARY KEY (ID)
);

INSERT INTO wptest_users (ID, user_login, user_email, user_url, user_registered, display_name) VALUES
	(1, 'owner', 'owner@example.test', 'https://example.test', '2020-01-01 10:00:00', 'Site Owner'),
	(2, 'new_one', 'one@example.test', '', '2024-05-01 01:00:00', 'New One'),
	(3, 'new_two', 'two@example.test', '', '2024-05-01 02:30:00', 'New Two'),
	(4, 'new_three', 'three@example.test', '', '2024-05-02 00:30:00', 'New Three'),
	(5, 'writer', 'writer@example.test', '', '2024-05-01 03:00:00', 'Writer'),
	(6, 'visitor', 'visitor@example.test', '', '2024-05-01 04:00:00', 'Visitor'),
	(7, 'wpenginer', 'wpenginer@example.test', '', '2024-09-01 00:00:00', 'wpenginer');

INSERT INTO wptest_usermeta (user_id, meta_key, meta_value) VALUES
	(1, 'wptest_capabilities', 'a:1:{s:13:"administrator";b:1;}'),
	(2, 'wptest_capabilities', 'a:1:{s:13:"administrator";b:1;}'),
	(3, 'wptest_capabilities', 'a:1:{s:13:"administrator";b:1;}'),
	(4, 'wptest_capabilities', 'a:1:{s:13:"administrator";b:1;}'),
	(5, 'wptest_capabilities', 'a:1:{s:6:"editor";b:1;}'),
	(6, 'wptest_capabilities', 'a:1:{s:10:"subscriber";b:1;}'),
	(6, 'other_capabilities', 'a:1:{s:13:"administrator";b:1;}'),
	(7, 'wptest_capabilities', 'a:1:{s:13:"administrator";b:1;}');

INSERT INTO wptest_posts (ID, post_author, post_type) VALUES
	(1, 1, 'post'),
	(2, 1, 'page'),
	(3, 5, 'post');
