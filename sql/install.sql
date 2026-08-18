CREATE TABLE IF NOT EXISTS `PREFIX_vatnumber_check` (
    `id_vatnumber_check` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `vat_number`         VARCHAR(32)  NOT NULL,
    `country_code`       VARCHAR(2)   NOT NULL,
    `status`             VARCHAR(16)  NOT NULL,
    `source`             VARCHAR(16)  NOT NULL,
    `vies_request_id`    VARCHAR(64)  NOT NULL DEFAULT '',
    `trader_name`        VARCHAR(255) NOT NULL DEFAULT '',
    `trader_address`     VARCHAR(255) NOT NULL DEFAULT '',
    `id_address`         INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `date_add`           DATETIME     NOT NULL,
    `date_upd`           DATETIME     NOT NULL,
    PRIMARY KEY (`id_vatnumber_check`),
    UNIQUE KEY `vat_number` (`vat_number`),
    KEY `status` (`status`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE COLLATE=COLLATE_TYPE
