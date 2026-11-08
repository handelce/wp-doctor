CREATE TABLE wpd_digits (d int NOT NULL);
INSERT INTO wpd_digits (d) VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9);

CREATE TABLE wptest_orders (
	id bigint unsigned NOT NULL AUTO_INCREMENT,
	ref varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	PRIMARY KEY (id),
	KEY ref (ref)
);
CREATE TABLE wptest_order_items (
	id bigint unsigned NOT NULL AUTO_INCREMENT,
	order_ref varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
	order_id varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	PRIMARY KEY (id),
	KEY order_ref (order_ref),
	KEY order_id (order_id)
);
CREATE TABLE wptest_order_notes (
	id bigint unsigned NOT NULL AUTO_INCREMENT,
	order_ref varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
	PRIMARY KEY (id),
	KEY order_ref (order_ref)
);

CREATE TABLE wptest_legacy_refunds (
	id bigint unsigned NOT NULL AUTO_INCREMENT,
	order_ref varchar(20) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL,
	PRIMARY KEY (id),
	KEY order_ref (order_ref)
);

INSERT INTO wptest_orders (ref) SELECT CONCAT('R', a.d, b.d, c.d) FROM wpd_digits a, wpd_digits b, wpd_digits c;
INSERT INTO wptest_order_items (order_ref, order_id) SELECT CONCAT('R', a.d, b.d, c.d), CONCAT(a.d, b.d, c.d) FROM wpd_digits a, wpd_digits b, wpd_digits c;
INSERT INTO wptest_order_notes (order_ref) SELECT CONCAT('R', a.d, b.d, c.d) FROM wpd_digits a, wpd_digits b, wpd_digits c;

INSERT INTO wptest_legacy_refunds (order_ref) SELECT CONCAT('R', a.d, b.d, c.d) FROM wpd_digits a, wpd_digits b, wpd_digits c;

ANALYZE TABLE wptest_orders, wptest_order_items, wptest_order_notes, wptest_legacy_refunds;
DROP TABLE wpd_digits;
