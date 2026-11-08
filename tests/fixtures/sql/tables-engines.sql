CREATE TABLE wptest_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (option_id)) ENGINE=InnoDB;
CREATE TABLE wptest_old_log (id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=MyISAM;
CREATE TABLE wptest_terms (term_id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (term_id)) ENGINE=MyISAM;
CREATE TABLE other_app_data (id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) ENGINE=MyISAM;
CREATE VIEW wptest_option_names AS SELECT option_id FROM wptest_options;
