CREATE TABLE wptest_posts (
	ID bigint unsigned NOT NULL AUTO_INCREMENT,
	post_parent bigint unsigned NOT NULL DEFAULT 0,
	post_type varchar(20) NOT NULL DEFAULT 'post',
	post_title text NOT NULL,
	PRIMARY KEY (ID)
);

INSERT INTO wptest_posts (ID, post_parent, post_type, post_title) VALUES
	(1, 0, 'post', 'First example'),
	(2, 0, 'page', 'About'),
	(3, 0, 'post', 'Never edited'),
	(4, 1, 'revision', 'First example'),
	(5, 1, 'revision', 'First example'),
	(6, 2, 'revision', 'About'),
	(7, 2, 'revision', 'About'),
	(8, 2, 'revision', 'About'),
	(9, 2, 'revision', 'About'),
	(10, 2, 'attachment', 'photo.jpg');
