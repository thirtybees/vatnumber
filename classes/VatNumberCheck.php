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

    /**
     * Seconds a VIES 'invalid' answer is served from cache. Short on
     * purpose: a freshly registered number should not stay locked out, but
     * repeated saves of the same wrong number must not hammer VIES either.
     */
    const INVALID_RETRY_SECONDS = 3600;

    /**
     * Days after which 'unverified' and 'invalid' rows that no address
     * references anymore are purged.
     */
    const PURGE_DAYS = 90;

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

    /** @var int Last address this number was seen on, 0 when unknown. */
    public $id_address;

    /** @var string */
    public $date_add;

    /** @var string Timestamp of the latest check. */
    public $date_upd;

    /** @var array Per-request memo for getStatus(), avoids repeated queries. */
    protected static $statusCache = [];

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
            'id_address'      => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'date_add'        => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd'        => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * Normalize a VAT number for storage and comparison: uppercase, strip
     * everything that is not a letter or digit (spaces, dots, dashes), and
     * translate to the canonical VIES form: the common 'GR' spelling for
     * Greece becomes 'EL', and 9 digit legacy Belgian numbers get their
     * leading zero restored.
     *
     * @param string $vatNumber
     *
     * @return string
     */
    public static function normalize($vatNumber)
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $vatNumber));
        if (substr($normalized, 0, 2) === 'GR') {
            $normalized = 'EL'.substr($normalized, 2);
        }
        if (substr($normalized, 0, 2) === 'BE' && preg_match('/^BE[0-9]{9}$/', $normalized)) {
            $normalized = 'BE0'.substr($normalized, 2);
        }

        return $normalized;
    }

    /**
     * Find the check record for a VAT number. Reads the master database:
     * this is used in read-then-write flows, where a stale replica answer
     * would cause duplicate key failures.
     *
     * @param string $normalizedNumber Normalized VAT number with prefix.
     *
     * @return VatNumberCheck|null
     *
     * @throws PrestaShopException
     */
    public static function findByNumber($normalizedNumber)
    {
        $id = (int) Db::getInstance()->getValue(
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
     * Lightweight status lookup with a per-request memo. Used by the tax
     * manager, which can be called several times per page; a full
     * ObjectModel load per call would be wasteful.
     *
     * @param string $normalizedNumber Normalized VAT number with prefix.
     *
     * @return string|null One of the STATUS_* constants, or null when the
     *                     number has no record.
     *
     * @throws PrestaShopException
     */
    public static function getStatus($normalizedNumber)
    {
        if (!array_key_exists($normalizedNumber, static::$statusCache)) {
            $status = Db::getInstance()->getValue(
                (new DbQuery())
                    ->select('status')
                    ->from('vatnumber_check')
                    ->where('vat_number = \''.pSQL($normalizedNumber).'\'')
            );
            static::$statusCache[$normalizedNumber] = ($status === false) ? null : (string) $status;
        }

        return static::$statusCache[$normalizedNumber];
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
     * @param int $idAddress Address the number was seen on, if known.
     *
     * @return VatNumberCheck
     *
     * @throws PrestaShopException
     */
    public static function recordResult($normalizedNumber, $countryCode, $status, $source, $requestId = '', $traderName = '', $traderAddress = '', $idAddress = 0)
    {
        $check = static::findByNumber($normalizedNumber);
        if (!$check) {
            $check = new VatNumberCheck();
            $check->vat_number = $normalizedNumber;
            $check->id_address = 0;
        }
        $check->country_code = strtoupper((string) $countryCode);
        $check->status = $status;
        $check->source = $source;
        $check->vies_request_id = (string) $requestId;
        $check->trader_name = (string) $traderName;
        $check->trader_address = (string) $traderAddress;
        if ((int) $idAddress) {
            $check->id_address = (int) $idAddress;
        }
        if (!$check->save()) {
            throw new PrestaShopException('Failed to save VAT number check result for '.$normalizedNumber);
        }
        static::$statusCache[$normalizedNumber] = $status;

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
     * True when this record is a VIES confirmed 'invalid' younger than
     * INVALID_RETRY_SECONDS. Such numbers are rejected from cache, so
     * repeated saves of the same wrong number do not hammer VIES.
     *
     * @return bool
     */
    public function isFreshInvalid()
    {
        return $this->status === static::STATUS_INVALID
            && $this->source === static::SOURCE_VIES
            && strtotime($this->date_upd) > (time() - static::INVALID_RETRY_SECONDS);
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
    public static function getPending($limit)
    {
        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            (new DbQuery())
                ->select('*')
                ->from('vatnumber_check')
                ->where('status = \''.pSQL(static::STATUS_UNVERIFIED).'\'')
                ->orderBy('date_upd ASC')
                ->limit(max(1, (int) $limit))
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
    public static function getStale($limit)
    {
        $result = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            (new DbQuery())
                ->select('*')
                ->from('vatnumber_check')
                ->where('status = \''.pSQL(static::STATUS_VALID).'\'')
                ->where('date_upd < \''.pSQL(date('Y-m-d H:i:s', strtotime('-'.static::RECHECK_DAYS.' days'))).'\'')
                ->orderBy('date_upd ASC')
                ->limit(max(1, (int) $limit))
        );

        return is_array($result) ? $result : [];
    }

    /**
     * Move a row to the back of the verification queue by refreshing its
     * date_upd. Used when VIES could not answer for this specific number,
     * so it does not block the queue head forever.
     *
     * @param int $idVatNumberCheck
     *
     * @return void
     * @throws PrestaShopException
     */
    public static function touchRow($idVatNumberCheck)
    {
        $ok = Db::getInstance()->update(
            'vatnumber_check',
            ['date_upd' => date('Y-m-d H:i:s')],
            'id_vatnumber_check = '.(int) $idVatNumberCheck
        );
        if (!$ok) {
            throw new PrestaShopException('Failed to touch VatNumberCheck '.(int) $idVatNumberCheck);
        }
    }

    /**
     * Delete old 'unverified' and 'invalid' rows that no customer address
     * references anymore, e.g. leftovers of corrected typos or of random
     * numbers submitted by bots. Keeps the table bounded and keeps the cron
     * from re-checking numbers nobody uses.
     *
     * The set of live numbers is collected in PHP and normalized with
     * normalize(), the single source of truth. Reimplementing the
     * normalization in SQL would silently diverge (GR to EL mapping,
     * Belgian zero padding) and purge rows that are still in use.
     *
     * 'valid' rows are never purged: they carry the consultation ID that
     * proves a past verification.
     *
     * @return int Number of deleted rows.
     *
     * @throws PrestaShopException
     */
    public static function purgeOrphaned()
    {
        $rows = Db::getInstance()->executeS(
            (new DbQuery())
                ->select('DISTINCT vat_number')
                ->from('address')
                ->where('deleted = 0')
                ->where('vat_number != \'\'')
        );
        $live = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $normalized = static::normalize($row['vat_number']);
            if ($normalized !== '') {
                $live[] = '\''.pSQL($normalized).'\'';
            }
        }

        $cutoff = date('Y-m-d H:i:s', strtotime('-'.static::PURGE_DAYS.' days'));
        $sql = 'DELETE FROM `'._DB_PREFIX_.'vatnumber_check`'
            .' WHERE status IN (\''.pSQL(static::STATUS_UNVERIFIED).'\', \''.pSQL(static::STATUS_INVALID).'\')'
            .' AND date_upd < \''.pSQL($cutoff).'\'';
        if ($live) {
            $sql .= ' AND vat_number NOT IN ('.implode(', ', $live).')';
        }
        if (!Db::getInstance()->execute($sql)) {
            throw new PrestaShopException('Failed to purge orphaned VAT number checks: '.Db::getInstance()->getMsgError());
        }

        return (int) Db::getInstance()->Affected_Rows();
    }

    /**
     * Normalized VAT numbers of addresses that have been active since a
     * date: the address itself was created or changed since then, or its
     * customer placed an order since then.
     *
     * @param string $date Date in Y-m-d format.
     *
     * @return string[] Normalized numbers, pSQL-quoted for use in IN ().
     *
     * @throws PrestaShopException
     */
    protected static function getActiveNumbersSince($date)
    {
        $rows = Db::getInstance()->executeS(
            (new DbQuery())
                ->select('DISTINCT a.vat_number')
                ->from('address', 'a')
                ->where('a.deleted = 0')
                ->where('a.vat_number != \'\'')
                ->where(
                    'a.date_upd >= \''.pSQL($date).'\''
                    .' OR a.id_customer IN ('
                    .'SELECT o.id_customer FROM `'._DB_PREFIX_.'orders` o WHERE o.date_add >= \''.pSQL($date).'\''
                    .')'
                )
        );
        $numbers = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $normalized = static::normalize($row['vat_number']);
            if ($normalized !== '') {
                $numbers[] = '\''.pSQL($normalized).'\'';
            }
        }

        return $numbers;
    }

    /**
     * Drop queued rows (status 'unverified', never answered by VIES) for
     * numbers whose owners have not been active since the given start date.
     * Keeps the verification queue focused on customers who still order,
     * instead of working through years of dormant addresses.
     *
     * Safe by design: only rows that never got a VIES answer are dropped,
     * and if a dormant customer returns and saves their address, the number
     * is simply checked live again at that moment.
     *
     * @param string $date Date in Y-m-d format.
     *
     * @return int Number of dropped rows.
     *
     * @throws PrestaShopException
     */
    public static function removeInactiveUnverified($date)
    {
        $active = static::getActiveNumbersSince($date);
        $sql = 'DELETE FROM `'._DB_PREFIX_.'vatnumber_check`'
            .' WHERE status = \''.pSQL(static::STATUS_UNVERIFIED).'\''
            .' AND source = \''.pSQL(static::SOURCE_FORMAT).'\'';
        if ($active) {
            $sql .= ' AND vat_number NOT IN ('.implode(', ', $active).')';
        }
        if (!Db::getInstance()->execute($sql)) {
            throw new PrestaShopException('Failed to drop inactive unverified VAT numbers: '.Db::getInstance()->getMsgError());
        }
        static::$statusCache = [];

        return (int) Db::getInstance()->Affected_Rows();
    }
}
