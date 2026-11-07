CREATE TABLE wpd_digits (d int NOT NULL);
INSERT INTO wpd_digits (d) VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9);

CREATE TABLE wptest_postmeta (id bigint unsigned NOT NULL AUTO_INCREMENT, meta_value longtext, PRIMARY KEY (id));
CREATE TABLE wptest_posts (id bigint unsigned NOT NULL AUTO_INCREMENT, post_content longtext, PRIMARY KEY (id));
CREATE TABLE wptest_security_log (id bigint unsigned NOT NULL AUTO_INCREMENT, message text, PRIMARY KEY (id));
CREATE TABLE other_app_data (id bigint unsigned NOT NULL AUTO_INCREMENT, payload longtext, PRIMARY KEY (id));

INSERT INTO wptest_postmeta (meta_value) SELECT REPEAT('m', 2000) FROM wpd_digits a, wpd_digits b, wpd_digits c;
INSERT INTO wptest_posts (post_content) SELECT REPEAT('p', 2000) FROM wpd_digits a, wpd_digits b;
INSERT INTO wptest_security_log (message) SELECT REPEAT('l', 200) FROM wpd_digits a;
INSERT INTO other_app_data (payload) SELECT REPEAT('o', 2000) FROM wpd_digits a, wpd_digits b, wpd_digits c, wpd_digits e;

ANALYZE TABLE wptest_postmeta, wptest_posts, wptest_security_log, other_app_data;
DROP TABLE wpd_digits;
