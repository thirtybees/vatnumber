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
 * Client for the VIES service of the European Commission.
 *
 * Primary transport is the REST API: one HTTPS request per check, strict
 * timeouts, and explicit error codes that let us distinguish an invalid
 * number from a service outage. When REST cannot answer (or cURL is not
 * available) and the PHP soap extension is loaded, the legacy SOAP
 * interface is tried as a fallback; both talk to the same backend database.
 * Only when neither transport answers is the check reported as an outage.
 *
 * Two time budgets: interactive checks (a customer saving an address) are
 * capped hard at a few seconds and skip the SOAP fallback, so a slow VIES
 * can never hang the checkout; batch checks (cron, back office tool) get
 * the full budget.
 *
 * Error philosophy: only codes that positively mean "this number is wrong"
 * cause a rejection. Every other non-VALID code (rate limits, member state
 * database down, unknown future codes) counts as "could not be asked", so a
 * struggling VIES can never cause a legitimate customer to be refused.
 */
class VatNumberViesClient
{
    const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    const SOAP_WSDL = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';

    /** Batch budget: seconds to wait for the TCP/TLS connection. */
    const CONNECT_TIMEOUT = 5;

    /** Batch budget: seconds to wait for the complete response. */
    const TIMEOUT = 10;

    /** Interactive budget (customer is waiting): connect / total seconds. */
    const INTERACTIVE_CONNECT_TIMEOUT = 3;
    const INTERACTIVE_TIMEOUT = 5;

    /** Longest consultation ID we store; VIES issues ~16 characters. */
    const REQUEST_ID_MAX_LENGTH = 64;

    /**
     * VIES error codes that positively mean the submitted number itself is
     * unacceptable. Everything else non-VALID is treated as an outage.
     *
     * @var string[]
     */
    protected static $hardRejectCodes = [
        'INVALID',
        'INVALID_INPUT',
    ];

    /**
     * Ask VIES whether a VAT number is registered.
     *
     * @param string $countryCode VIES country code, e.g. 'NL' or 'EL'.
     * @param string $number Number part after the country prefix.
     * @param array|null $requester The shop's own VAT number as
     *                              ['countryCode' => .., 'number' => ..].
     *                              When given, VIES returns a consultation
     *                              ID (requestIdentifier) with the answer.
     * @param bool $interactive True when a customer is waiting: short
     *                          timeouts, no SOAP fallback.
     *
     * @return array with keys:
     *               'valid' (bool),
     *               'requestIdentifier' (string) consultation ID, proof of the check,
     *               'name' (string) trader name as registered,
     *               'address' (string) trader address as registered.
     *
     * @throws VatNumberViesUnavailableException when VIES cannot answer.
     * @throws PrestaShopException when no usable transport exists at all.
     */
    public static function checkVat($countryCode, $number, $requester = null, $interactive = false)
    {
        $curlAvailable = function_exists('curl_init');
        $soapAvailable = extension_loaded('soap') && !$interactive;

        if (!$curlAvailable && !extension_loaded('soap')) {
            // Server misconfiguration, not an outage: surface it loudly
            // instead of silently disabling all checking.
            throw new PrestaShopException('Neither the curl nor the soap PHP extension is available, VAT numbers cannot be verified');
        }
        if (!$curlAvailable) {
            if ($interactive) {
                // SOAP (WSDL download, soft timeouts) is too slow to make a
                // customer wait for it; the outage path accepts provisionally
                // and the cron verifies through SOAP later.
                throw new VatNumberViesUnavailableException('cURL is not available; interactive checks do not use the SOAP transport');
            }

            return static::checkVatSoap($countryCode, $number, $requester);
        }

        try {
            return static::checkVatRest($countryCode, $number, $requester, $interactive);
        } catch (VatNumberViesUnavailableException $restException) {
            if (!$soapAvailable) {
                throw $restException;
            }
            try {
                $result = static::checkVatSoap($countryCode, $number, $requester);
            } catch (VatNumberViesUnavailableException $soapException) {
                throw new VatNumberViesUnavailableException('REST: '.$restException->getMessage().'; SOAP fallback: '.$soapException->getMessage());
            }
            PrestaShopLogger::addLog('vatnumber: VIES REST interface failed ('.$restException->getMessage().'), the SOAP fallback answered instead.', 1);

            return $result;
        }
    }

    /**
     * Ask VIES via the REST API, the primary transport.
     *
     * @param string $countryCode VIES country code, e.g. 'NL' or 'EL'.
     * @param string $number Number part after the country prefix.
     * @param array|null $requester See checkVat().
     * @param bool $interactive See checkVat().
     *
     * @return array Same shape as checkVat().
     *
     * @throws VatNumberViesUnavailableException when the REST interface cannot answer.
     */
    protected static function checkVatRest($countryCode, $number, $requester = null, $interactive = false)
    {
        $request = [
            'countryCode' => (string) $countryCode,
            'vatNumber'   => (string) $number,
        ];
        if ($requester) {
            $request['requesterMemberStateCode'] = (string) $requester['countryCode'];
            $request['requesterNumber'] = (string) $requester['number'];
        }

        $ch = curl_init(static::ENDPOINT);
        if ($ch === false) {
            throw new VatNumberViesUnavailableException('Could not initialise a cURL handle');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($request),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $interactive ? static::INTERACTIVE_CONNECT_TIMEOUT : static::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $interactive ? static::INTERACTIVE_TIMEOUT : static::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new VatNumberViesUnavailableException('VIES request failed: '.$error);
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 500) {
            throw new VatNumberViesUnavailableException('VIES returned HTTP '.$httpCode);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new VatNumberViesUnavailableException('VIES returned an unreadable response (HTTP '.$httpCode.')');
        }

        // VIES signals problems both via HTTP status and via error fields in
        // the body. Check the body first, it is the more specific signal.
        $errorCode = static::extractErrorCode($data);
        if ($errorCode !== '' && $errorCode !== 'VALID') {
            if (in_array($errorCode, static::$hardRejectCodes, true)) {
                return static::rejection(isset($data['requestIdentifier']) ? $data['requestIdentifier'] : '');
            }
            if ($errorCode === 'INVALID_REQUESTER_INFO' && $requester) {
                // Our own number was not accepted as requester (for example
                // an XI number). The check itself is still possible without
                // it, just without a consultation ID.
                return static::checkVatRest($countryCode, $number, null, $interactive);
            }

            // Rate limits, member state outages, and any code we do not
            // know: never reject a customer over these.
            throw new VatNumberViesUnavailableException('VIES service unavailable: '.$errorCode);
        }

        if ($httpCode !== 200) {
            throw new VatNumberViesUnavailableException('VIES returned HTTP '.$httpCode);
        }

        if (!array_key_exists('isValid', $data) && !array_key_exists('valid', $data)) {
            throw new VatNumberViesUnavailableException('VIES response is missing the validity flag');
        }

        return [
            'valid'             => !empty($data['isValid']) || !empty($data['valid']),
            'requestIdentifier' => static::cleanRequestId(isset($data['requestIdentifier']) ? $data['requestIdentifier'] : ''),
            'name'              => static::cleanTraderString(isset($data['name']) ? $data['name'] : ''),
            'address'           => static::cleanTraderString(isset($data['address']) ? $data['address'] : ''),
        ];
    }

    /**
     * Ask VIES via the legacy SOAP interface. Fallback transport, used when
     * REST failed or cURL is unavailable. Same backend database, so answers
     * are identical. With a requester the checkVatApprox operation is used,
     * which (like REST) returns a consultation ID; without one the plain
     * checkVat operation is used, which does not.
     *
     * @param string $countryCode VIES country code, e.g. 'NL' or 'EL'.
     * @param string $number Number part after the country prefix.
     * @param array|null $requester See checkVat().
     *
     * @return array Same shape as checkVat().
     *
     * @throws VatNumberViesUnavailableException when the SOAP interface cannot answer.
     */
    protected static function checkVatSoap($countryCode, $number, $requester = null)
    {
        // The WSDL download does not honor SoapClient timeouts, it uses this
        // ini setting. Narrow it for the call, restore afterwards.
        $previousTimeout = ini_get('default_socket_timeout');
        if ($previousTimeout === false || $previousTimeout === '') {
            $previousTimeout = 60;
        }
        ini_set('default_socket_timeout', (string) static::TIMEOUT);
        try {
            $client = new SoapClient(static::SOAP_WSDL, [
                'connection_timeout' => static::CONNECT_TIMEOUT,
                'exceptions'         => true,
                'cache_wsdl'         => WSDL_CACHE_DISK,
                'stream_context'     => stream_context_create([
                    'http' => ['timeout' => static::TIMEOUT],
                ]),
            ]);
            if ($requester) {
                $response = $client->checkVatApprox([
                    'countryCode'          => (string) $countryCode,
                    'vatNumber'            => (string) $number,
                    'requesterCountryCode' => (string) $requester['countryCode'],
                    'requesterVatNumber'   => (string) $requester['number'],
                ]);
            } else {
                $response = $client->checkVat([
                    'countryCode' => (string) $countryCode,
                    'vatNumber'   => (string) $number,
                ]);
            }
        } catch (SoapFault $fault) {
            $code = strtoupper(trim($fault->getMessage()));
            if (in_array($code, static::$hardRejectCodes, true)) {
                return static::rejection('');
            }
            if ($code === 'INVALID_REQUESTER_INFO' && $requester) {
                return static::checkVatSoap($countryCode, $number, null);
            }

            // Outage codes and anything unrecognized (WSDL fetch failures,
            // HTTP errors) count as "could not be asked", never as a
            // rejection of the customer's number.
            throw new VatNumberViesUnavailableException('VIES SOAP request failed: '.$fault->getMessage());
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }

        // checkVat answers with name/address, checkVatApprox with
        // traderName/traderAddress plus requestIdentifier.
        $name = isset($response->traderName) ? $response->traderName : (isset($response->name) ? $response->name : '');
        $address = isset($response->traderAddress) ? $response->traderAddress : (isset($response->address) ? $response->address : '');

        return [
            'valid'             => !empty($response->valid),
            'requestIdentifier' => static::cleanRequestId(isset($response->requestIdentifier) ? $response->requestIdentifier : ''),
            'name'              => static::cleanTraderString($name),
            'address'           => static::cleanTraderString($address),
        ];
    }

    /**
     * Result shape for a number VIES positively rejected.
     *
     * @param mixed $requestId Consultation ID of the rejecting check, if any.
     *
     * @return array
     */
    protected static function rejection($requestId)
    {
        return [
            'valid'             => false,
            'requestIdentifier' => static::cleanRequestId($requestId),
            'name'              => '',
            'address'           => '',
        ];
    }

    /**
     * @param array $data Decoded VIES response body.
     *
     * @return string Error code, empty string when none present.
     */
    protected static function extractErrorCode($data)
    {
        if (isset($data['userError']) && is_string($data['userError'])) {
            return strtoupper(trim($data['userError']));
        }
        if (isset($data['errorWrappers'][0]['error']) && is_string($data['errorWrappers'][0]['error'])) {
            return strtoupper(trim($data['errorWrappers'][0]['error']));
        }

        return '';
    }

    /**
     * @param mixed $value
     *
     * @return string Consultation ID, bounded to the stored column width.
     */
    protected static function cleanRequestId($value)
    {
        if (!is_string($value)) {
            return '';
        }

        return mb_substr(trim($value), 0, static::REQUEST_ID_MAX_LENGTH);
    }

    /**
     * VIES sometimes answers '---' for withheld trader data and uses
     * newlines in addresses. Normalize for single line database storage,
     * and strip angle brackets so remote data can never reach a page as
     * markup.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected static function cleanTraderString($value)
    {
        if (!is_string($value)) {
            return '';
        }
        $value = str_replace(['<', '>'], '', $value);
        $value = trim(preg_replace('/\s+/', ' ', $value));
        if ($value === '---') {
            return '';
        }

        return mb_substr($value, 0, 255);
    }
}
