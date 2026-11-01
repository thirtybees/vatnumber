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
 * latest known state plus the last confirmed verification. This is the
 * local cache that keeps the shop working when VIES is down, and the audit
 * trail (consultation IDs) that proves to the tax authorities that a number
 * was verified.
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

    /** Literals per IN () list, keeps statements well below max_allowed_packet. */
    const SQL_CHUNK_SIZE = 1000;

    /** @var string Full normalized VAT number including country prefix. */
    public $vat_number;

    /** @var string VIES country code, e.g. 'NL' or 'EL'. */
    public $country_code;

    /** @var string One of the STATUS_* constants. */
    public $status;

    /** @var string One of the SOURCE_* constants. */
    public $source;

    /** @var string VIES consultation ID of the latest check, if any. */
    public $vies_request_id;

    /** @var string Trader name as registered with the tax authorities. */
    public $trader_name;

    /** @var string Trader address as registered with the tax authorities. */
    public $trader_address;

    /** @var int Last address this number was seen on, 0 when unknown. */
    public $id_address;

    /** @var int Customer who last submitted this number, 0 when unknown. */
    public $id_customer;

    /**
     * @var string Consultation ID of the last check that confirmed the
     *             number valid. Kept when a later check rejects it, so the
     *             proof for orders placed in between survives.
     */
    public $valid_request_id;

    /** @var string|null Date of the last check that confirmed the number valid. */
    public $valid_date;

    /** @var string */
    public $date_add;

    /** @var string Timestamp of the latest check. */
    public $date_upd;

    /** @var array<string, string|null> Per-request memo for getStatus(), avoids repeated queries. */
    protected static $statusCache = [];

    /** @var array<string, array<int, array{id_address: int, id_customer: int, deleted: bool}>>|null Per-request memo for getAddressMap(). */
    protected static $addressMap = null;

    /**
     * @var array
     */
    public static $definition = [
        'table'   => 'vatnumber_check',
        'primary' => 'id_vatnumber_check',
        'fields'  => [
            'vat_number'       => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 32],
            'country_code'     => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 2],
            'status'           => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 16],
            'source'           => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 16],
            'vies_request_id'  => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64],
            'trader_name'      => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'size' => 255],
            'trader_address'   => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml', 'size' => 255],
            'id_address'       => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'id_customer'      => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'valid_request_id' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64],
            'valid_date'       => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true],
            'date_add'         => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd'         => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * Normalize a VAT number for storage and comparison: uppercase, strip
     * everything that is not a letter or digit (spaces, dots, dashes), and
     * translate to the canonical VIES form: the common 'GR' spelling for
     * Greece becomes 'EL', and 9 digit legacy Belgian numbers get their
     * leading zero restored. The '+' and '*' of the legacy Irish format are
     * part of the number and are kept.
     *
     * @param string|false|null $vatNumber Raw value; false (unset
     *                                     configuration) and null count as
     *                                     empty.
     *
     * @return string
     */
    public static function normalize($vatNumber): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9+*]/', '', (string) $vatNumber));
        if (substr($normalized, 0, 2) === 'GR') {
            $normalized = 'EL'.substr($normalized, 2);
        }
        if (preg_match('/^BE[0-9]{9}$/', $normalized)) {
            $normalized = 'BE0'.substr($normalized, 2);
        }

        return $normalized;
    }

    /**
     * True when the value is the legacy checkbox sentinel of versions before
     * 2.7.4, in any spelling or spacing a customer might type.
     *
     * @param string $vatNumber Raw or normalized value.
     *
     * @return bool
     */
    public static function isLegacySentinel(string $vatNumber): bool
    {
        return static::normalize($vatNumber) === static::normalize(VatNumber::VAT_EXEMPTION_FLAG);
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
    public static function findByNumber(string $normalizedNumber): ?VatNumberCheck
    {
        $row = Db::getInstance()->getRow(
            (new DbQuery())
                ->select('*')
                ->from('vatnumber_check')
                ->where('vat_number = \''.pSQL($normalizedNumber).'\'')
        );
        if (!$row) {
            return null;
        }

        $check = new VatNumberCheck();
        $check->hydrate($row);

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
    public static function getStatus(string $normalizedNumber): ?string
    {
        if (!array_key_exists($normalizedNumber, static::$statusCache)) {
            // Master on purpose: a replica lagging behind a confirmation
            // just written would tax a customer VIES has confirmed.
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
     * A 'valid' result also refreshes the kept proof (valid_request_id,
     * valid_date); an 'invalid' result leaves that proof alone, so orders
     * placed while the number was valid keep their evidence.
     *
     * @param string $normalizedNumber Normalized VAT number with prefix.
     * @param string $countryCode VIES country code.
     * @param string $status One of the STATUS_* constants.
     * @param string $source One of the SOURCE_* constants.
     * @param string $requestId VIES consultation ID, if any.
     * @param string $traderName
     * @param string $traderAddress
     * @param int $idAddress Address the number was seen on, if known.
     * @param int $idCustomer Customer who submitted it, if known.
     *
     * @return VatNumberCheck
     *
     * @throws PrestaShopException
     */
    public static function recordResult(string $normalizedNumber, string $countryCode, string $status, string $source, string $requestId = '', string $traderName = '', string $traderAddress = '', int $idAddress = 0, int $idCustomer = 0): VatNumberCheck
    {
        $check = static::findByNumber($normalizedNumber);
        $previousStatus = $check ? (string) $check->status : null;
        if (!$check) {
            $check = new VatNumberCheck();
            $check->vat_number = $normalizedNumber;
            $check->id_address = 0;
            $check->id_customer = 0;
            $check->valid_request_id = '';
            $check->valid_date = null;
        }
        static::applyResult($check, $countryCode, $status, $source, $requestId, $traderName, $traderAddress, $idAddress, $idCustomer);

        // Two flows can check the same new number at once and both try to
        // INSERT against the unique key. Depending on debug settings core
        // reports that as an exception or as a false return; either way
        // the row exists now, so reload it and apply the result as update.
        $saved = false;
        try {
            $saved = $check->save();
        } catch (PrestaShopDatabaseException $e) {
            $saved = false;
        }
        if (!$saved) {
            $existing = static::findByNumber($normalizedNumber);
            if (!$existing) {
                throw new PrestaShopException('Failed to save VAT number check result for '.$normalizedNumber.': '.Db::getInstance()->getMsgError());
            }
            $check = $existing;
            static::applyResult($check, $countryCode, $status, $source, $requestId, $traderName, $traderAddress, $idAddress, $idCustomer);
            if (!$check->save()) {
                throw new PrestaShopException('Failed to update VAT number check result for '.$normalizedNumber.': '.Db::getInstance()->getMsgError());
            }
        }
        static::$statusCache[$normalizedNumber] = $status;

        if ($previousStatus !== $status) {
            static::announceStatusChange($normalizedNumber, $previousStatus, $status, $source);
        }

        return $check;
    }

    /**
     * Tell other modules that the status of a number changed, through the
     * hook actionVatNumberStatusUpdate. tborderflags uses it to re-evaluate
     * the open orders that carry the number. A failing listener is logged
     * and never undoes or blocks the stored result.
     *
     * Hook parameters: vat_number (normalized), old_status (null for a new
     * number), new_status, source; statuses are the STATUS_* constants.
     *
     * @param string $normalizedNumber
     * @param string|null $oldStatus
     * @param string $newStatus
     * @param string $source One of the SOURCE_* constants.
     *
     * @return void
     * @throws PrestaShopException
     */
    protected static function announceStatusChange(string $normalizedNumber, ?string $oldStatus, string $newStatus, string $source): void
    {
        try {
            Hook::exec('actionVatNumberStatusUpdate', [
                'vat_number' => $normalizedNumber,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'source'     => $source,
            ]);
        } catch (Exception $e) {
            VatNumber::log('A module listening to VAT number status changes failed for %s: %s', 3, $normalizedNumber, $e->getMessage());
        }
    }

    /**
     * Copy a check result onto a record without saving.
     *
     * @param VatNumberCheck $check
     * @param string $countryCode
     * @param string $status
     * @param string $source
     * @param string $requestId
     * @param string $traderName
     * @param string $traderAddress
     * @param int $idAddress
     * @param int $idCustomer
     *
     * @return void
     */
    protected static function applyResult(VatNumberCheck $check, string $countryCode, string $status, string $source, string $requestId, string $traderName, string $traderAddress, int $idAddress, int $idCustomer): void
    {
        $check->country_code = strtoupper((string) $countryCode);
        $check->status = $status;
        $check->source = $source;
        $requestId = mb_substr((string) $requestId, 0, 64);
        if (!Validate::isGenericName($requestId)) {
            $requestId = '';
        }
        $check->vies_request_id = $requestId;
        // VIES trader data is foreign input; a value the model's HTML check
        // would refuse must never make a successful VIES answer fail.
        $check->trader_name = Validate::isCleanHtml($traderName) ? (string) $traderName : '';
        $check->trader_address = Validate::isCleanHtml($traderAddress) ? (string) $traderAddress : '';
        if ((int) $idAddress) {
            $check->id_address = (int) $idAddress;
        }
        if ((int) $idCustomer) {
            $check->id_customer = (int) $idCustomer;
        }
        if ($status === static::STATUS_VALID && $source === static::SOURCE_VIES) {
            $check->valid_request_id = $requestId;
            $check->valid_date = date('Y-m-d H:i:s');
        }
    }

    /**
     * True when this record is a VIES confirmed 'valid' younger than
     * CACHE_DAYS. Such numbers are not re-checked on address save.
     *
     * @return bool
     */
    public function isFreshValid(): bool
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
    public function isFreshInvalid(): bool
    {
        return $this->status === static::STATUS_INVALID
            && $this->source === static::SOURCE_VIES
            && strtotime($this->date_upd) > (time() - static::INVALID_RETRY_SECONDS);
    }

    /**
     * How many new numbers a customer had checked in the last period. Used
     * to stop one customer (or a script on their account) from triggering
     * unbounded VIES requests with made up numbers. Customer 0 counts the
     * checks of all anonymous visitors together.
     *
     * @param int $idCustomer
     * @param int $seconds Look-back window.
     *
     * @return int
     *
     * @throws PrestaShopException
     */
    public static function countRecentByCustomer(int $idCustomer, int $seconds): int
    {
        return (int) Db::getInstance()->getValue(
            (new DbQuery())
                ->select('COUNT(*)')
                ->from('vatnumber_check')
                ->where('id_customer = '.(int) $idCustomer)
                ->where('date_add >= \''.pSQL(date('Y-m-d H:i:s', time() - (int) $seconds)).'\'')
        );
    }

    /**
     * Numbers that still await VIES confirmation.
     *
     * @param int $limit
     *
     * @return array<int, array{id_vatnumber_check: int|string, vat_number: string, country_code: string, status: string, source: string, vies_request_id: string, trader_name: string, trader_address: string, id_address: int|string, id_customer: int|string, valid_request_id: string, valid_date: string|null, date_add: string, date_upd: string}>
     *         Rows with all table columns, as the database returns them
     *         (integer columns are strings unless the shop enabled native
     *         database types).
     *
     * @throws PrestaShopException
     */
    public static function getPending(int $limit): array
    {
        $result = Db::getInstance()->executeS(
            (new DbQuery())
                ->select('*')
                ->from('vatnumber_check')
                ->where('status = \''.pSQL(static::STATUS_UNVERIFIED).'\'')
                ->orderBy('date_upd ASC')
                ->limit(max(1, (int) $limit))
        );
        if ($result === false) {
            throw new PrestaShopException('Failed to read the VAT number queue: '.Db::getInstance()->getMsgError());
        }

        return $result;
    }

    /**
     * Count of numbers that still await VIES confirmation.
     *
     * @return int
     *
     * @throws PrestaShopException
     */
    public static function countPending(): int
    {
        return (int) Db::getInstance()->getValue(
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
     * @return array<int, array{id_vatnumber_check: int|string, vat_number: string, country_code: string, status: string, source: string, vies_request_id: string, trader_name: string, trader_address: string, id_address: int|string, id_customer: int|string, valid_request_id: string, valid_date: string|null, date_add: string, date_upd: string}>
     *         Rows with all table columns, as the database returns them
     *         (integer columns are strings unless the shop enabled native
     *         database types).
     *
     * @throws PrestaShopException
     */
    public static function getStale(int $limit): array
    {
        $result = Db::getInstance()->executeS(
            (new DbQuery())
                ->select('*')
                ->from('vatnumber_check')
                ->where('status = \''.pSQL(static::STATUS_VALID).'\'')
                ->where('date_upd < \''.pSQL(date('Y-m-d H:i:s', strtotime('-'.static::RECHECK_DAYS.' days'))).'\'')
                ->orderBy('date_upd ASC')
                ->limit(max(1, (int) $limit))
        );
        if ($result === false) {
            throw new PrestaShopException('Failed to read stale VAT numbers: '.Db::getInstance()->getMsgError());
        }

        return $result;
    }

    /**
     * Move a row to the back of the verification queue by refreshing its
     * date_upd. Used when a check of this specific row could not complete,
     * so it does not block the queue head forever.
     *
     * @param int $idVatNumberCheck
     *
     * @return void
     *
     * @throws PrestaShopException
     */
    public static function touchRow(int $idVatNumberCheck): void
    {
        $ok = Db::getInstance()->update(
            'vatnumber_check',
            ['date_upd' => date('Y-m-d H:i:s')],
            'id_vatnumber_check = '.(int) $idVatNumberCheck
        );
        if (!$ok) {
            throw new PrestaShopException('Failed to touch VatNumberCheck '.(int) $idVatNumberCheck.': '.Db::getInstance()->getMsgError());
        }
    }

    /**
     * Normalized VAT numbers found on customer addresses. With a date, only
     * addresses that have been active since then: the address itself was
     * created or changed since then, or its customer placed an order since
     * then. Without a date, all addresses.
     *
     * Soft deleted addresses are included on request: orders keep pointing
     * at their historical address rows, and the invoice PDF re-evaluates
     * the exemption through those rows, so their numbers count as "in use"
     * for housekeeping purposes.
     *
     * Normalization happens here in PHP with normalize(), the single source
     * of truth. Reimplementing it in SQL would silently diverge (GR to EL
     * mapping, Belgian zero padding).
     *
     * @param string|null $sinceDate Date in Y-m-d format, or null for all.
     * @param bool $includeDeleted Also look at soft deleted addresses.
     *
     * @return string[] Unique normalized numbers.
     *
     * @throws PrestaShopException when the address table cannot be read;
     *                             callers must never treat that as "no
     *                             numbers".
     */
    public static function getAddressNumbers(?string $sinceDate = null, bool $includeDeleted = false): array
    {
        $base = 'SELECT DISTINCT a.vat_number FROM `'._DB_PREFIX_.'address` a WHERE a.vat_number != \'\'';
        if (!$includeDeleted) {
            $base .= ' AND a.deleted = 0';
        }
        if ($sinceDate !== null && $sinceDate !== '') {
            // Two indexed drives joined by UNION instead of one OR with a
            // subquery: the OR form forces a full scan with a correlated
            // probe per address row.
            $sql = $base.' AND a.date_upd >= \''.pSQL($sinceDate).'\''
                .' UNION '
                .'SELECT DISTINCT a.vat_number FROM `'._DB_PREFIX_.'orders` o'
                .' INNER JOIN `'._DB_PREFIX_.'address` a ON a.id_customer = o.id_customer'
                .' WHERE o.date_add >= \''.pSQL($sinceDate).'\' AND a.vat_number != \'\''
                .($includeDeleted ? '' : ' AND a.deleted = 0');
        } else {
            $sql = $base;
        }
        $rows = Db::getInstance()->executeS($sql);
        if ($rows === false) {
            throw new PrestaShopException('Failed to read VAT numbers from the address table: '.Db::getInstance()->getMsgError());
        }

        $numbers = [];
        foreach ($rows as $row) {
            $normalized = static::normalize($row['vat_number']);
            if (strlen($normalized) >= 4 && !static::isLegacySentinel($normalized)) {
                $numbers[$normalized] = true;
            }
        }

        return array_keys($numbers);
    }

    /**
     * Addresses carrying a VAT number, newest first, keyed by normalized
     * number. The address table is scanned once per request; call
     * forgetAddressMap() after changing addresses.
     *
     * @return array<string, array<int, array{id_address: int, id_customer: int, deleted: bool}>>
     * @throws PrestaShopException
     */
    protected static function getAddressMap(): array
    {
        if (static::$addressMap === null) {
            $rows = Db::getInstance()->executeS('SELECT a.`id_address`, a.`id_customer`, a.`vat_number`, a.`deleted` FROM `'._DB_PREFIX_.'address` a WHERE a.`vat_number` != \'\' ORDER BY a.`id_address` DESC');
            if ($rows === false) {
                throw new PrestaShopException('Failed to read VAT numbers from the address table: '.Db::getInstance()->getMsgError());
            }
            $map = [];
            foreach ($rows as $row) {
                $normalized = static::normalize($row['vat_number']);
                if (strlen($normalized) < 4) {
                    continue;
                }
                $map[$normalized][] = [
                    'id_address'  => (int) $row['id_address'],
                    'id_customer' => (int) $row['id_customer'],
                    'deleted'     => (bool) $row['deleted'],
                ];
            }
            static::$addressMap = $map;
        }

        return static::$addressMap;
    }

    /**
     * Addresses on which a number appears, newest first.
     *
     * @param string $normalizedNumber
     * @param bool $liveOnly Skip soft deleted addresses (the historical
     *                       copies orders point at).
     *
     * @return array<int, array{id_address: int, id_customer: int, deleted: bool}>
     * @throws PrestaShopException
     */
    public static function findAddresses(string $normalizedNumber, bool $liveOnly): array
    {
        $map = static::getAddressMap();
        $found = isset($map[$normalizedNumber]) ? $map[$normalizedNumber] : [];
        if ($liveOnly) {
            $found = array_values(array_filter($found, function (array $address): bool {
                return !$address['deleted'];
            }));
        }

        return $found;
    }

    /**
     * The address a number is best linked to: the newest live one, else the
     * newest historical one.
     *
     * @param string $normalizedNumber
     *
     * @return array{id_address: int, id_customer: int, deleted: bool}|null
     * @throws PrestaShopException
     */
    public static function findPrimaryAddress(string $normalizedNumber): ?array
    {
        $all = static::findAddresses($normalizedNumber, false);
        foreach ($all as $address) {
            if (!$address['deleted']) {
                return $address;
            }
        }

        return $all ? $all[0] : null;
    }

    /**
     * Drop the cached address map, after addresses were changed.
     *
     * @return void
     */
    public static function forgetAddressMap(): void
    {
        static::$addressMap = null;
    }

    /**
     * Number of rows with a status.
     *
     * @param string $status One of the STATUS_* constants.
     *
     * @return int
     * @throws PrestaShopException
     */
    public static function countByStatus(string $status): int
    {
        $count = Db::getInstance()->getValue(
            (new DbQuery())
                ->select('COUNT(*)')
                ->from(static::$definition['table'])
                ->where('`status` = \''.pSQL($status).'\'')
        );
        if ($count === false) {
            throw new PrestaShopException('Failed to count VAT number checks: '.Db::getInstance()->getMsgError());
        }

        return (int) $count;
    }

    /**
     * Delete old 'unverified' and 'invalid' rows that no customer address
     * references anymore, e.g. leftovers of corrected typos or of random
     * numbers submitted by bots. Keeps the table bounded and keeps the cron
     * from re-checking numbers nobody uses.
     *
     * 'valid' rows are never purged: they carry the consultation ID that
     * proves a past verification.
     *
     * @param string[] $liveNumbers Normalized numbers currently on any
     *                              address (including soft deleted ones),
     *                              from getAddressNumbers(null, true).
     *
     * @return int Number of deleted rows.
     *
     * @throws PrestaShopException
     */
    public static function purgeOrphaned(array $liveNumbers): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-'.static::PURGE_DAYS.' days'));
        $candidates = Db::getInstance()->executeS(
            (new DbQuery())
                ->select('id_vatnumber_check, vat_number')
                ->from('vatnumber_check')
                ->limit(static::SQL_CHUNK_SIZE * 50)
                ->where('status IN (\''.pSQL(static::STATUS_UNVERIFIED).'\', \''.pSQL(static::STATUS_INVALID).'\')')
                ->where('date_upd < \''.pSQL($cutoff).'\'')
        );
        if ($candidates === false) {
            throw new PrestaShopException('Failed to read purge candidates: '.Db::getInstance()->getMsgError());
        }

        $live = array_fill_keys($liveNumbers, true);
        $ids = [];
        foreach ($candidates as $row) {
            if (!isset($live[$row['vat_number']])) {
                $ids[] = (int) $row['id_vatnumber_check'];
            }
        }

        return static::deleteByIds($ids);
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
     * @param string[] $activeNumbers Normalized numbers of addresses active
     *                                since the start date, from
     *                                getAddressNumbers($date).
     *
     * @return int Number of dropped rows.
     *
     * @throws PrestaShopException
     */
    public static function removeInactiveUnverified(array $activeNumbers): int
    {
        $candidates = Db::getInstance()->executeS(
            (new DbQuery())
                ->select('id_vatnumber_check, vat_number')
                ->from('vatnumber_check')
                ->limit(static::SQL_CHUNK_SIZE * 50)
                ->where('status = \''.pSQL(static::STATUS_UNVERIFIED).'\'')
                ->where('source = \''.pSQL(static::SOURCE_FORMAT).'\'')
        );
        if ($candidates === false) {
            throw new PrestaShopException('Failed to read the verification queue: '.Db::getInstance()->getMsgError());
        }

        $active = array_fill_keys($activeNumbers, true);
        $ids = [];
        foreach ($candidates as $row) {
            if (!isset($active[$row['vat_number']])) {
                $ids[] = (int) $row['id_vatnumber_check'];
            }
        }

        return static::deleteByIds($ids);
    }

    /**
     * Delete rows by primary key, in chunks.
     *
     * @param int[] $ids
     *
     * @return int Rows deleted.
     *
     * @throws PrestaShopException
     */
    protected static function deleteByIds(array $ids): int
    {
        $deleted = 0;
        foreach (array_chunk($ids, static::SQL_CHUNK_SIZE) as $chunk) {
            $sql = 'DELETE FROM `'._DB_PREFIX_.'vatnumber_check` WHERE id_vatnumber_check IN ('.implode(', ', array_map('intval', $chunk)).')';
            if (!Db::getInstance()->execute($sql)) {
                throw new PrestaShopException('Failed to delete VAT number checks: '.Db::getInstance()->getMsgError());
            }
            $deleted += (int) Db::getInstance()->Affected_Rows();
        }
        if ($deleted) {
            static::$statusCache = [];
        }

        return $deleted;
    }

    /**
     * Queue VAT numbers that exist on customer addresses but have no check
     * record yet, so the cron verifies them. This is what makes the queue
     * self healing: numbers stored while checking was off, imported
     * addresses, or anything that slipped past the save-time check all get
     * picked up eventually.
     *
     * @param string[] $numbers Normalized numbers from getAddressNumbers().
     * @param int $limit Maximum numbers to queue in one call.
     *
     * @return int Number of rows created.
     *
     * @throws PrestaShopException
     */
    public static function queueMissing(array $numbers, int $limit): int
    {
        if (!$numbers) {
            return 0;
        }

        $known = [];
        foreach (array_chunk($numbers, static::SQL_CHUNK_SIZE) as $chunk) {
            $quoted = array_map(function ($n) {
                return '\''.pSQL($n).'\'';
            }, $chunk);
            $rows = Db::getInstance()->executeS(
                (new DbQuery())
                    ->select('vat_number')
                    ->from('vatnumber_check')
                    ->where('vat_number IN ('.implode(', ', $quoted).')')
            );
            if ($rows === false) {
                throw new PrestaShopException('Failed to read known VAT numbers: '.Db::getInstance()->getMsgError());
            }
            foreach ($rows as $row) {
                $known[$row['vat_number']] = true;
            }
        }

        $now = date('Y-m-d H:i:s');
        $inserts = [];
        foreach ($numbers as $normalized) {
            if (isset($known[$normalized])) {
                continue;
            }
            if (count($inserts) >= (int) $limit) {
                break;
            }
            $inserts[] = [
                'vat_number'       => pSQL($normalized),
                'country_code'     => pSQL(substr($normalized, 0, 2)),
                'status'           => static::STATUS_UNVERIFIED,
                'source'           => static::SOURCE_FORMAT,
                'vies_request_id'  => '',
                'trader_name'      => '',
                'trader_address'   => '',
                'id_address'       => 0,
                'id_customer'      => 0,
                'valid_request_id' => '',
                'date_add'         => $now,
                'date_upd'         => $now,
            ];
        }
        if (!$inserts) {
            return 0;
        }

        // INSERT IGNORE against the unique key: a concurrent front office
        // save of the same number simply wins, no duplicate key failure.
        // valid_date is left out so it takes its NULL column default.
        $created = 0;
        foreach (array_chunk($inserts, static::SQL_CHUNK_SIZE) as $chunk) {
            if (!Db::getInstance()->insert('vatnumber_check', $chunk, false, true, Db::INSERT_IGNORE)) {
                throw new PrestaShopException('Failed to queue VAT numbers: '.Db::getInstance()->getMsgError());
            }
            $created += (int) Db::getInstance()->Affected_Rows();
        }

        return $created;
    }
}
