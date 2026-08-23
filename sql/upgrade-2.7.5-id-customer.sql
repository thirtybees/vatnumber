ALTER TABLE `PREFIX_vatnumber_check` ADD COLUMN `id_customer` INT(11) UNSIGNED NOT NULL DEFAULT 0, ADD KEY `customer_date` (`id_customer`, `date_add`)
