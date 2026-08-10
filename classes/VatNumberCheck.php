<?php
/**
 * Copyright (C) 2017-2026 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @copyright 2017-2026 thirty bees
 * @license   Academic Free License (AFL 3.0)
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Local record of a VAT number check. One row per VAT number, holding the
 * latest known state. This is the local cache that keeps the shop working
 * when VIES is down, and the audit trail (consultation ID) that proves to
 * the tax authorities that a number was verified.
 */
class VatNumberCheck extends ObjectModel
{
    const STATUS_VALID = 'valid';
    const STATUS_INVALID = 'invalid';
    const STATUS_UNVERIFIED = 'unverified';

    const SOURCE_FORMAT = 'format';
    const SOURCE_VIES = 'vies';

    /** Days a VIES 'valid' answer is served from cache without a new lookup. */
    const CACHE_DAYS = 30;

    /** Days after which a 'valid' number is re-verified by the cron. */
    const RECHECK_DAYS = 180;

    /** @var string Full normalized VAT number including country prefix. */
    public $vat_number;

    /** @var string VIES country code, e.g. 'NL' or 'EL'. */
    public $country_code;

    /** @var string One of the STATUS_* constants. */
    public $status;

    /** @var string One of the SOURCE_* constants. */
    public $source;

    /** @var string VIES consultation ID, proof that the check happened. */
    public $vies_request_id;

    /** @var string Trader name as registered with the tax authorities. */
    public $trader_name;

    /** @var string Trader address as registered with the tax authorities. */
    public $trader_address;

    /** @var string */
    public $date_add;

    /** @var string Timestamp of the latest check. */
    public $date_upd;

    /**
     * @var array
     */
    public static $definition = [
        'table'   => 'vatnumber_check',
        'primary' => 'id_vatnumber_check',
        'fields'  => [
            'vat_number'      => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 32],
            'country_code'    => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 2],
            'status'          => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 16],
            'source'          => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 16],
            'vies_request_id' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64],
            'trader_name'     => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'size' => 255],
            'trader_address'  => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'size' => 255],
            'date_add'        => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd'        => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * Normalize a VAT number for storage and comparison: uppercase, strip
     * everything that is not a letter or digit (spaces, dots, dashes).
     *
     * @param string $vatNumber
     *
     * @return string
     */
    public static function normalize($vatNumber)
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $vatNumber));
    }

    /**
     * Find the check record for a VAT number.
     *
     * @param string $normalizedNumber Normalized VAT number with prefix.
     *
     * @return VatNumberCheck|null
     *
     * @throws PrestaShopException
     */
    public static function findByNumber($normalizedNumber)
    {
        $id = (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            (new DbQuery())
                ->select('id_vatnumber_check')
                ->from('vatnumber_check')
                ->where('vat_number = \''.pSQL($normalizedNumber).'\'')
        );
        if (!$id) {
            return null;
        }

        $check = new VatNumberCheck($id);
        if (!Validate::isLoadedObject($check)) {
            throw new PrestaShopException('Failed to load VatNumberCheck '.$id);
        }

        return $check;
    }

    /**
     * Create or update the record for a VAT number with a fresh result.
     *
     * @param string $normalizedNumber Normalized VAT number with prefix.
     * @param string $countryCode VIES country code.
     * @param string $status One of the STATUS_* constants.
     * @param string $source One of the SOURCE_* constants.
     * @param string $requestId VIES consultation ID, if any.
     * @param string $traderName
     * @param string $traderAddress
     *
     * @return VatNumberCheck
     *
     * @throws PrestaShopException
     */
    public static function recordResult($normalizedNumber, $countryCode, $status, $source, $requestId = '', $traderName = '', $traderAddress = '')
    {
        $check = static::findByNumber($normalizedNumber);
        if (!$check) {
            $check = new VatNumberCheck();
            $check->vat_number = $normalizedNumber;
        }
        $check->country_code = strtoupper((string) $countryCode);
        $check->status = $status;
        $check->source = $source;
        $check->vies_request_id = (string) $requestId;
        $check->trader_name = (string) $traderName;
        $check->trader_address = (string) $traderAddress;
        $check->date_upd = date('Y-m-d H:i:s');
        if (!$check->save()) {
            throw new PrestaShopException('Failed to save VAT number check result for '.$normalizedNumber);
        }

        return $check;
    }

    /**
     * True when this record is a VIES confirmed 'valid' younger than
     * CACHE_DAYS. Such numbers are not re-checked on address save.
     *
     * @return bool
     */
    public function isFreshValid()
    {
        return $this->status === static::STATUS_VALID
            && $this->source === static::SOURCE_VIES
            && strtotime($this->date_upd) > strtotime('-'.static::CACHE_DAYS.' days');
    }

    /**
     * Numbers that still await VIES confirmation (accepted while the service
     * was down).
     *
     * @param int $limit
     *
     * @return array Rows with all table columns.
     *
     * @throws PrestaShopException
     */
    public static function getPending($limit = 50)
    {
        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            (new DbQuery())
                ->select('*')
                ->from('vatnumber_check')
                ->where('status = \''.pSQL(static::STATUS_UNVERIFIED).'\'')
                ->orderBy('date_upd ASC')
                ->limit((int) $limit)
        );

        return is_array($result) ? $result : [];
    }

    /**
     * Count of numbers that still await VIES confirmation.
     *
     * @return int
     *
     * @throws PrestaShopException
     */
    public static function countPending()
    {
        return (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            (new DbQuery())
                ->select('COUNT(*)')
                ->from('vatnumber_check')
                ->where('status = \''.pSQL(static::STATUS_UNVERIFIED).'\'')
        );
    }

    /**
     * Valid numbers whose last check is older than RECHECK_DAYS. VAT
     * registrations expire, so long-standing customers get re-verified.
     *
     * @param int $limit
     *
     * @return array Rows with all table columns.
     *
     * @throws PrestaShopException
     */
    public static function getStale($limit = 50)
    {
        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            (new DbQuery())
                ->select('*')
                ->from('vatnumber_check')
                ->where('status = \''.pSQL(static::STATUS_VALID).'\'')
                ->where('date_upd < \''.pSQL(date('Y-m-d H:i:s', strtotime('-'.static::RECHECK_DAYS.' days'))).'\'')
                ->orderBy('date_upd ASC')
                ->limit((int) $limit)
        );

        return is_array($result) ? $result : [];
    }
}
