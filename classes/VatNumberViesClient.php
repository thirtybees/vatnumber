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
 * number from a service outage. When REST cannot answer and the PHP soap
 * extension is available, the legacy SOAP interface is tried once as a
 * silent fallback; both talk to the same backend database. Only when
 * neither transport answers is the check reported as an outage.
 */
class VatNumberViesClient
{
    const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    const SOAP_WSDL = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';

    /** Seconds to wait for the TCP/TLS connection. */
    const CONNECT_TIMEOUT = 5;

    /** Seconds to wait for the complete response. */
    const TIMEOUT = 10;

    /**
     * VIES error codes that mean "the service could not answer right now".
     * These trigger the provisional acceptance fallback.
     *
     * @var string[]
     */
    protected static $outageCodes = [
        'SERVICE_UNAVAILABLE',
        'MS_UNAVAILABLE',
        'TIMEOUT',
        'GLOBAL_MAX_CONCURRENT_REQ',
        'MS_MAX_CONCURRENT_REQ',
        'IP_BLOCKED',
    ];

    /**
     * Ask VIES whether a VAT number is registered.
     *
     * @param string $countryCode VIES country code, e.g. 'NL' or 'EL'.
     * @param string $number Number part after the country prefix.
     *
     * @return array with keys:
     *               'valid' (bool),
     *               'requestIdentifier' (string) consultation ID, proof of the check,
     *               'name' (string) trader name as registered,
     *               'address' (string) trader address as registered.
     *
     * @throws VatNumberViesUnavailableException when VIES cannot answer.
     * @throws PrestaShopException on configuration errors (e.g. missing cURL).
     */
    public static function checkVat($countryCode, $number)
    {
        try {
            return static::checkVatRest($countryCode, $number);
        } catch (VatNumberViesUnavailableException $restException) {
            if (!extension_loaded('soap')) {
                throw $restException;
            }
            try {
                $result = static::checkVatSoap($countryCode, $number);
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
     *
     * @return array Same shape as checkVat().
     *
     * @throws VatNumberViesUnavailableException when the REST interface cannot answer.
     * @throws PrestaShopException on configuration errors (e.g. missing cURL).
     */
    protected static function checkVatRest($countryCode, $number)
    {
        if (!function_exists('curl_init')) {
            throw new PrestaShopException('PHP curl extension not loaded');
        }

        $payload = json_encode([
            'countryCode' => (string) $countryCode,
            'vatNumber'   => (string) $number,
        ]);

        $ch = curl_init(static::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => static::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => static::TIMEOUT,
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
        if ($errorCode !== '' && $errorCode !== 'VALID' && $errorCode !== 'INVALID') {
            if (in_array($errorCode, static::$outageCodes)) {
                throw new VatNumberViesUnavailableException('VIES service unavailable: '.$errorCode);
            }

            // Remaining codes (e.g. INVALID_INPUT) mean the number itself is
            // not acceptable, which is a hard rejection, not an outage.
            return [
                'valid'             => false,
                'requestIdentifier' => '',
                'name'              => '',
                'address'           => '',
            ];
        }

        if ($httpCode !== 200) {
            throw new VatNumberViesUnavailableException('VIES returned HTTP '.$httpCode);
        }

        if (!array_key_exists('isValid', $data) && !array_key_exists('valid', $data)) {
            throw new VatNumberViesUnavailableException('VIES response is missing the validity flag');
        }

        $valid = !empty($data['isValid']) || !empty($data['valid']);

        return [
            'valid'             => $valid,
            'requestIdentifier' => isset($data['requestIdentifier']) ? (string) $data['requestIdentifier'] : '',
            'name'              => static::cleanTraderString(isset($data['name']) ? $data['name'] : ''),
            'address'           => static::cleanTraderString(isset($data['address']) ? $data['address'] : ''),
        ];
    }

    /**
     * Ask VIES via the legacy SOAP interface. Fallback transport, only used
     * when REST failed and the PHP soap extension is loaded. Same backend
     * database, so answers are identical; SOAP just does not return a
     * consultation ID.
     *
     * @param string $countryCode VIES country code, e.g. 'NL' or 'EL'.
     * @param string $number Number part after the country prefix.
     *
     * @return array Same shape as checkVat(), with an empty
     *               'requestIdentifier' (the SOAP checkVat operation does
     *               not provide one).
     *
     * @throws VatNumberViesUnavailableException when the SOAP interface cannot answer.
     */
    protected static function checkVatSoap($countryCode, $number)
    {
        // The WSDL download does not honor SoapClient timeouts, it uses this
        // ini setting. Narrow it for the call, restore afterwards.
        $previousTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) static::TIMEOUT);
        try {
            $client = new SoapClient(static::SOAP_WSDL, [
                'connection_timeout' => static::CONNECT_TIMEOUT,
                'exceptions'         => true,
                'stream_context'     => stream_context_create([
                    'http' => ['timeout' => static::TIMEOUT],
                ]),
            ]);
            $response = $client->checkVat([
                'countryCode' => (string) $countryCode,
                'vatNumber'   => (string) $number,
            ]);
        } catch (SoapFault $fault) {
            $code = strtoupper(trim($fault->getMessage()));
            if ($code === 'INVALID_INPUT') {
                return [
                    'valid'             => false,
                    'requestIdentifier' => '',
                    'name'              => '',
                    'address'           => '',
                ];
            }

            // Outage codes and anything unrecognized (WSDL fetch failures,
            // HTTP errors) count as "could not be asked", never as a
            // rejection of the customer's number.
            throw new VatNumberViesUnavailableException('VIES SOAP request failed: '.$fault->getMessage());
        } finally {
            ini_set('default_socket_timeout', (string) $previousTimeout);
        }

        return [
            'valid'             => !empty($response->valid),
            'requestIdentifier' => '',
            'name'              => static::cleanTraderString(isset($response->name) ? $response->name : ''),
            'address'           => static::cleanTraderString(isset($response->address) ? $response->address : ''),
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
     * VIES sometimes answers '---' for withheld trader data and uses
     * newlines in addresses. Normalize for single line database storage.
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
        $value = trim(preg_replace('/\s+/', ' ', $value));
        if ($value === '---') {
            return '';
        }

        return mb_substr($value, 0, 255);
    }
}
