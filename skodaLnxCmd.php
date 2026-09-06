#!/usr/bin/env php
<?php

/**
 *  PHP Skoda Public API implementations.
 *
 *  https://github.com/mem76/skodaLnxCmd
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  As a special exception to the GNU General Public License version 3,
 *  the copyright holders of this library give you permission to link
 *  this library with independent modules to produce an executable,
 *  regardless of the license terms of these independent modules, and to
 *  copy and distribute the resulting executable under terms of your choice,
 *  provided that you also meet, for each linked independent module, the
 *  terms and conditions of the license of that module. An independent
 *  module is a module which is not derived from or based on this library.
 *  If you modify this library, you may extend this exception to your
 *  version of the library, but you are not obligated to do so. If you do
 *  not wish to do so, delete this exception statement from your version.
 *
 * Škoda API documentation
 * https://public.api.connect.skoda-auto.cz/docs/swagger-ui/index.html#/
 */

class skodaApi 
{
    private const string API_HOST = 'https://public.api.connect.skoda-auto.cz/api/v1';
    private const string APP_ID = 'com.github.com.mem76.skodaLnxCmd';
    private static ?string $vin = null;
    private static ?string $key = null;
    private static ?string $pin = null;
    private static ?string $cacheDir = null;
    private static int $heatTemp = 20;
    private static array $_CACHE = array();
    private static ?string $errorMsg = null;
    private static array $errorLog = array();

    /**
     * Get estimated charge compete.
     * @return string|null Date/time in ISO format.
     */
    public static function getChargeDone(): ?string
    {
        if (!self::isCharging()) {
            return null;
        }
        $status = self::getStatus();
        $doneAt = $status['vehicle']['charging']['status']['fullyChargedAt'] ?? null;
        if (is_null($doneAt)) {
            return null;
        }
        return @date('c', strtotime($doneAt)) ?: null;
    }

    /**
     * Return remaining charge time.
     * @param bool $minutes Return number of minuts as int if true, else retun text.
     * @return string|int|null
     */
    protected static function getChargeDoneIn(bool $minutes=false): null|string|int
    {
        // "remainingTimeToFullyChargedInMinutes" is a bad metric, the car set the value, and the API send this value
        // minutes, sometime more than an hour after it is fetched. This results in a bad experience do to bad numbers.
        // By calculating it from "fullyChargedAt" the error will be less than if the number from the API is used.
        $endTime=strtotime(self::getChargeDone());
        if($endTime){
            $timeLeft = $endTime - time();
            $doneIn = max(0, $timeLeft / 60);
        } else {
            $status = self::getStatus();
            $doneIn = $status['vehicle']['charging']['status']['remainingTimeToFullyChargedInMinutes'] ?? null;
        }
        if (is_null($doneIn)) {
            return null;
        }
        if ($minutes) {
            return (int)$doneIn;
        }
        if ($doneIn < 60) {
            return (int)$doneIn . ' minutes';
        }
        $h = (int)($doneIn / 60);
        $m = $doneIn % 60;
        if ($h < 24) {
            $ret=$h==1?"$h hour and ":"$h hours";
            if ($m != 0) $ret .= $m == 1 ? " and $m minute" : " and $m minutes";
            return $ret;
        }
        $d = (int)($h / 24);
        $h = $h % 24;
        return "$d days and $h hours";
    }

    public static function getAcStatusValue():?string
    {
        $status = self::getStatus();
        if (empty($status['vehicle']['airConditioning']['state'])) {
            return null;
        }
        return $status['vehicle']['airConditioning']['state'];
        
    }

    /**
     * Return error message.
     * 
     * Functions store a human-readable message on error, is message can be retried by this function.
     * @return string|null
     */
    public static function getErrorMsg(): ?string
    {
        return self::$errorMsg;
    }
    protected static function setErrorMsg(string $msg):void
    {
        self::$errorMsg = $msg;
        self::$errorLog[] = $msg;
    }

    /**
     * Some functions need a security pin.
     * @param int|string $pin
     * @return void
     */
    public static function setSecurityPin(int|string $pin):void
    {
        self::$pin = $pin;
    }
    
    public static function getAcStatus():?bool
    {
        $state = self::getAcStatusValue();
        if ($state == 'OFF') {
            return false;
        }
        if (preg_match('/^(UNSUPPORTED|UNKNOWN)$/', $state)) {
            return null;
        }
        if (preg_match('/^(COOLING|HEATING|HEATING_AUXILIARY|VENTILATION|COMPLETED)$/', $state)) {
            return true;
        }
        return null;
    }
    protected static function getAcTargetTemp() :?int {
        $status = self::getStatus();
        if(empty($status['vehicle']['airConditioning']['targetTemperature']['value'])) {
           return null; 
        }
        if (is_numeric($status['vehicle']['airConditioning']['targetTemperature']['value'])) {
            return (int)$status['vehicle']['airConditioning']['targetTemperature']['value'];
        }
        return null;
    }

    protected static function getChargingState(): ?string
    {
        $status = self::getStatus();
        if (empty($status['vehicle']['charging']['status']['state'])) {
            return null;
        }
        return $status['vehicle']['charging']['status']['state'];
    }

    public static function isCharging(): ?bool
    {
        $status = self::getStatus();
        $charge = $status['vehicle']['charging']['status']['state'];
        if (preg_match('/^(CONSERVING|CHARGING)$/', $charge)) {
            return true;
        }
        if (preg_match('/^(CONNECT_CABLE|DISCHARGING|READY_FOR_CHARGING|CHARGING_INTERRUPTED)$/', $charge)) {
            return false;
        }
        return null;
    }

    private static function getStatus()
    {
        self::init();
        $cacheID = __FUNCTION__;
        if (isset(self::$_CACHE[$cacheID])) {
            return self::$_CACHE[$cacheID];
        }
        $d = self::getCachedStatus();
        if (is_array($d)) {
            self::$_CACHE[$cacheID] = $d;
            return $d;
        }
        $uri = 'https://public.api.connect.skoda-auto.cz/api/v1/vehicles/' . self::$vin;
        $rez = self::_apiFetch($uri);
        $data = json_decode($rez['http_body'], true);
        self::$_CACHE[$cacheID] = $data;
        self::saveCachedStatus($data);
        return self::$_CACHE[$cacheID];
    }

    private static function getCachedStatus(string $id = 'status'): ?array
    {
        $cacheFile = self::getCacheFileName($id);
        if ($id == 'status') {
            $ttl = self::getCacheTtl();
        } else {
            $ttl = 3600;
        }
        if (
                file_exists($cacheFile) &&
                (time() - filemtime($cacheFile)) < $ttl
        ) {
            return json_decode(file_get_contents($cacheFile), true);
        }
        return null;
    }

    public static function getCacheFileName(string $id = 'status'): ?string
    {
        if ($id != 'status' && $id != 'rate') {
            return null;
        }
        return sprintf(
                '/run/user/%d/' . self::APP_ID . '/' . $id . '.cache.json',
                posix_getuid()
        );
    }

    /**
     * Disk cache TTL
     * 
     * This function keeps status cache just shy of 5 min, but:
     * * increase cache time if the API has been heavily used.
     * * reduce cache time if rate-limit period is soon over
     * @return int
     */
    private static function getCacheTtl(): int
    {
        $ttl = 290; // 4:50 min
        if (is_numeric(self::getE('SKODA_STATUS_TTL'))) {
            $ttl = max(30, (int)self::getE('SKODA_STATUS_TTL'));
            $ttl = min($ttl, 1800);
        }
        $rate = self::getRateLimitStatus();
        if ($rate == array()) { //First run since reboot?
            return 300;
        }
        $age = time() - $rate['timestamp'];
        $rate_reset = max(0, $rate['ratelimit-reset'] - $age);
        if ($age > $rate['ratelimit-reset']) { // New period started => low TTL
            return 60;
        }
        if ($rate['ratelimit-remaining'] <= 6) {
            // Keep 6 req/hour for commands (20 - 1req/5min = 6)
            $sec_per_request = max(1, (int)($rate_reset / $rate['ratelimit-remaining']));
            return max(60, $sec_per_request * 2);
        }
        $sec_per_request = max(1, (int)($rate_reset / ($rate['ratelimit-remaining']) - 4));
        if ($sec_per_request < 20) {
            // Let's refresh before new period starts
            return 30;
        }
        return max($ttl, $sec_per_request);
    }

    /**
     * Returns env-var $id if exist or config file as fallback.
     * @param $id
     * @return string|null
     */
    private static function getE($id): ?string
    {
        if (getenv($id)) {
            return getenv($id);
        }
        return self::getConfigValue(self::getConfigFileName(), $id);
    }

    private static function getConfigValue(string $filename, string $key): ?string
    {
        if (!is_readable($filename)) {
            return null;
        }
        $content = file_get_contents($filename);
        $pattern = "/^" . preg_quote($key, '/') . "='([^']*)'$/m";
        if (preg_match($pattern, $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public static function getConfigFileName(): string
    {
        return getenv('HOME') . '/.config/' . self::APP_ID . '/config.conf';
    }

    public static function getRateLimitStatus(): array
    {
        if (key_exists('rate_limit', self::$_CACHE)) {
            return self::$_CACHE['rate_limit'];
        }
        $cache = self::getCachedStatus('rate');
        if (is_array($cache)) {
            return $cache;
        }
        return array();
    }

    /**
     * @param string $uri
     * @param array $data
     * @param array $options Options array('http_method'=>'POST','timeout' => 10)
     * @return array
     *
     * keys: 'http_code', 'http_headers' , 'result'
     */
    protected static function _apiFetch(string $uri, array $data = array(), array $options = array()): array
    {
        if (!self::$key) {
            self::setErrorMsg(__FUNCTION__ . ': api_key_missing');
            return array();
        }
        $http_method = 'GET';
        if (@$options['http_method'] == 'POST' || @$options['http_method'] == 'GET' || @$options['http_method'] == 'PUT') 
            $http_method = $options['http_method'];
        $curlArray = array();
        $headers[] = "X-API-Key: " . self::$key;
        $curlArray[CURLOPT_URL] = $uri;
        $curlArray[CURLOPT_HEADER] = true;
        if (@is_numeric($options['timeout'])) {
            $curlArray[CURLOPT_TIMEOUT] = (int)$options['timeout'];
        } else {
            $curlArray[CURLOPT_TIMEOUT] = 10;
        }
        if ($http_method == 'POST') {
            $curlArray[CURLOPT_POST] = true;
            if (!empty($data)) {
                $headers[] = "Content-Type: application/json";
                $curlArray[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        }
        elseif ($http_method == 'PUT') {
            //TODO: Add code
            return array();
        }
        
        $curlArray[CURLOPT_HTTPHEADER] = $headers;
        $curlReturn = self::curl($curlArray);
        $return = $curlReturn;
        $return['http_header'] = substr($curlReturn['response'], 0, $curlReturn['header_size']);
        $return['http_body'] = substr($curlReturn['response'], $curlReturn['header_size']);
        unset($return['header_size']);
        unset($return['response']);
        self::analyzeHeader($return['http_header']);
        return $return;
    }

    private static function init(): void
    {
        if (!self::$key) self::$key = self::getE('SKODA_KEY');
        if (!self::$pin) self::$pin = self::getE('SKODA_PIN');
        if (!self::$vin) self::$vin = self::getE('SKODA_VIN');
        if (!self::$key || !self::$vin) {
            echo "ERROR: Key and/or vin not set." . PHP_EOL . PHP_EOL;
        }
        // Override timezone
        $overrideTimeZone = self::getE('SKODA_TIMEZONE');
        if (in_array($overrideTimeZone, DateTimeZone::listIdentifiers(), true)) {
            date_default_timezone_set($overrideTimeZone);
        }
    }

    private static function curl(array $curlArray): array
    {
        $return = array();
        $curlHandle = curl_init();
        if (!key_exists(CURLOPT_URL, $curlArray)) {
            return $return;
        }
        $curlArray[CURLOPT_RETURNTRANSFER] = true;
        curl_setopt_array($curlHandle, $curlArray);
        $return['response'] = curl_exec($curlHandle);
        $curl_info = curl_getinfo($curlHandle);
        if (key_exists('http_code', $curl_info)) $return['http_code'] = $curl_info['http_code'];
        if (key_exists('content_type', $curl_info)) $return['content_type'] = $curl_info['content_type'];
        if (curl_errno($curlHandle)) {
            $return['curl_errno'] = curl_errno($curlHandle);
            $return['curl_error'] = curl_error($curlHandle);
        }
        if (@$curlArray[CURLOPT_HEADER]) {
            $return['header_size'] = curl_getinfo($curlHandle, CURLINFO_HEADER_SIZE);
        }
        return $return;
    }

    /**
     * Extract data from HTTP header, stored in self::$_CACHE['rate_limit'].
     * @param string $header
     * @return void
     */
    private static function analyzeHeader(string $header): void
    {
        $headers = array();
        $lines = explode("\r\n", $header);
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            list($key, $value) = explode(':', $line, 2);
            $headers[trim($key)] = trim($value);
        }
        $o = array();
        foreach (array('ratelimit-limit', 'ratelimit-remaining', 'ratelimit-reset', 'x-api-key-expires-at') as $key) {
            $o[$key] = $headers[$key];
        }
        $o['timestamp'] = time();
        self::saveCachedStatus($o, 'rate');
        self::$_CACHE['rate_limit'] = $o;
    }

    private static function saveCachedStatus(array $data, string $id = 'status'): bool
    {
        $cacheFile = self::getCacheFileName($id);
        if (!file_exists(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0777, true);
        }
        $ret = file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT));
        if ($ret === false) {
            return false;
        }
        return true;
    }

    protected static function vehicleSupport(string $method = ''): ?bool
    {
        if ($method == '') {
            return null;
        }
        $status = self::getStatus();
        if (empty($status['vehicle']['operations'])) return null;
        foreach ($status['vehicle']['operations'] as $operation) {
            if ($operation['name'] === $method) {
                return true;
            }
        }
        self::setErrorMsg('Method not supported by vehicle: ' . $method);
        return false;
    }
    public static function vehicleSupportChargeModes(string $mode = ''): ?bool
    {
        if ($mode == '') {
            return null;
        }
        $status = self::getStatus();
        // /vehicle/charging/settings/availableChargeModes
        if (empty($status['vehicle']['charging']['settings']['availableChargeModes'])) {
            return null;
        }
        $modes = $status['vehicle']['charging']['settings']['availableChargeModes'];
        if (in_array($mode, $modes)) {
            return true;
        }
        self::setErrorMsg('Vehicle does not support this charging mode.');
        return false;
    }

    public static function getVehicleSupport(): array
    {
        $status = self::getStatus();
        $return = array();
        foreach ($status['vehicle']['operations'] as $operation) {
            $return['functions'][] = $operation['name'];
        }
        ;
        foreach ($status['vehicle']['charging']['settings']['availableChargeModes'] as $mode) {
            $return['chargeModes'][] = $mode;
        }
        return $return;
    }

    /**
     * @return float|null charging power in kW
     */
    public static function getChargingPower(): ?float
    {
        if (!self::isCharging()) {
            return null;
        }
        $status = self::getStatus();
        $chargePower = $status['vehicle']['charging']['status']['chargePowerInKw'] ?? null;
        if (is_numeric($chargePower)) {
            return (float)$chargePower;
        }
        return null;
    }

    /**
     * @return int|null charge rate in km/h
     */
    public static function getChargeRate(): ?int
    {
        if (!self::isCharging()) {
            return null;
        }
        $status = self::getStatus();
        $chargeRate = $status['vehicle']['charging']['status']['chargingRateInKilometersPerHour'] ?? null;
        if (is_numeric($chargeRate)) {
            return (int)$chargeRate;
        }
        return null;
    }

    public static function getLockedStatus(): ?string
    {
        $status = self::getStatus();
        if (empty($status['vehicle']['status']['overall']['doorsLocked'])) {
            return null;
        }
        return $status['vehicle']['status']['overall']['doorsLocked'];
    }

    public static function getOdo(): ?int
    {
        $status = self::getStatus();
        if (@is_numeric(($status['vehicle']['odometer']['mileageInKm']))) {
            return (int)$status['vehicle']['odometer']['mileageInKm'];
        }
        return null;
    }

    public static function getParkingLocation(): ?string
    {
        $status = self::getStatus();
        if(empty($status['vehicle']['parkingPosition'])) {
            return null;
        }
        $parking = $status['vehicle']['parkingPosition'];
        if ($parking['state'] == 'PARKED') {
            return $parking['formattedAddress'];
        }
        return null;
    }

    /**
     * Remaining range in km
     * @return int|null
     */
    public static function getRange(): ?int
    {
        $status = self::getStatus();
        if (@is_numeric(($status['vehicle']['charging']['status']['battery']['remainingCruisingRangeInMeters']))) {
            return (int)($status['vehicle']['charging']['status']['battery']['remainingCruisingRangeInMeters'] / 1000);
        }
        return null;
    }

    public static function getSoc(): ?int
    {
        $status = self::getStatus();
        if (@is_numeric(($status['vehicle']['charging']['status']['battery']['stateOfChargeInPercent']))) {
            return (int)$status['vehicle']['charging']['status']['battery']['stateOfChargeInPercent'];
        }
        return null;
    }

    /**
     * Purge disk cache.
     * Purging disk cache after changes (like turning on AC) can improve user experience
     * 
     * @param bool $force Purge cache regardless of API throtling status.
     * @return void
     */
    private static function purgeCache(bool $force = false): void
    {
        $fileName = self::getCacheFileName('status');
        if (file_exists($fileName)) @unlink($fileName);
    }


    protected static function getPlateNumber(): ?string
    {
        $status = self::getStatus();
        return $status['vehicle']['licensePlate'] ?? null;
    }


    /**
     * Returns data array to send to the API.
     * @param int $temp
     * @return array
     */
    public static function mkAcBody(int $temp): array
    {
        // limit range from 16°C to 29°C
        $temp = max(16, $temp);
        $temp = min(29, $temp);
        $a = array();
        $a['targetTemperature']['value'] = $temp;
        $a['targetTemperature']['unit'] = 'CELSIUS';
        $a['airConditioningWithoutExternalPower'] = true;
        return $a;
    }

    public static function startAuxiliaryHeating(?int $temp = null): ?bool
    {
        if (!self::vehicleSupport(__FUNCTION__)) {
            return null;
        }
        if (!$temp) $temp = self::$heatTemp;
        $temp = max(10, $temp);
        $temp = min(30, $temp);
        return false;
    }
    public static function startCharging(): ?bool
    {
        if (!self::vehicleSupport(__FUNCTION__)) {
            return null;
        }
        if (self::isCharging()) {
            // Is charging return OK.
            return true;
        }
        if (self::getChargingState()!='READY_FOR_CHARGING') {
            self::setErrorMsg( 'Car not plugged inn? Trying in case of stale data.');
        }
        $uri = self::API_HOST . '/vehicles/' . self::$vin . '/charging/start';
        $option = array('http_method' => 'POST');
        $ret = self::_apiFetch($uri, array(), $option);
        if ($ret['http_code'] < 300) {
            self::purgeCache();
            return true;
        }
        self::errorHandling($ret);
        return false;
    }
    public static function stopCharging(): ?bool
    {
        if (!self::vehicleSupport(__FUNCTION__)) {
            return null;
        }
        if (!self::isCharging()) {
            return true;
        }
        $uri = self::API_HOST . '/vehicles/' . self::$vin . '/charging/stop';
        $option = array('http_method' => 'POST');
        $ret=self::_apiFetch($uri, array(), $option);
        if ($ret['http_code'] < 300) {
            self::purgeCache();
            return true;
        }
        self::errorHandling($ret);
        return false;
        
    }
    public static function setChargingLimit(int $soc= 80 ) : ?bool
    {
        if (!self::vehicleSupport(__FUNCTION__)) {
            return null;
        }
        //TODO
        return null;
    }

    /**
     * @param string $mode
     * @return bool|null
     * 
     * 
     * Valid modes per september 2026
     * 
     * * `HOME_STORAGE_CHARGING`
     * * `IMMEDIATE_DISCHARGING`
     * * `MANUAL`
     * * `ONLY_OWN_CURRENT`
     * * `PREFERRED_CHARGING_TIMES`
     * * `TIMER`
     * * `TIMER_CHARGING_WITH_CLIMATISATION`
     */
    public static function setChargeMode(string $mode = 'MANUAL'): ?bool
    {
        if (!self::vehicleSupport(__FUNCTION__)) {
            return null;
        }
        $valid = array();
        if (!in_array($mode, $valid)) {
            return null;
        }
        $option = array('http_method' => 'PUT');
        $data["chargeMode"] = $mode;
        $uri = self::API_HOST . '/vehicles/' . self::$vin . '/charging/mode';
        $ret = self::_apiFetch($uri, $data, $option);
        if ($ret['http_code'] < 300) {
            self::purgeCache();
            return true;
        }
        self::errorHandling($ret);
        return false;
    }


    public static function isPinSet(): bool
    {
        if (self::$pin) {
            return true;
        }
        return false;
    }

    private static function mkHeatBody($temp, $dur = 30): string
    {
        // limit range from 10°C to 30°C
        $temp = max(16, $temp);
        $temp = min(29, $temp);
        // limit range from 5 min to 10 hours.
        $dur = max(5, $dur);
        $dur = min(10 * 60, $dur);
        $a = array();
        $a["targetTemperature"]["value"] = $temp;
        $a["targetTemperature"]["unit"] = 'CELSIUS';
        $a["spin"] = self::$pin;
        $a["durationInSeconds"] = $dur * 60;
        $a["startMode"] = "HEATING";
        return json_encode($a);
    }

    public static function startActiveVentilation()
    {
        if (!self::vehicleSupport(__FUNCTION__)) {
            return null;
        }
        //TODO: Add code
        return null;

    }
    protected static function errorHandling (array $array): void
    {
        if (!key_exists("http_code", $array)) {
            return;
        }
        if ($array["http_code"] > 399) {
            $msg='';
            if (key_exists("response", $array)) {
                if (json_validate($array["response"])) {
                    $tmp = json_decode($array["response"], true);
                    if (key_exists('detail',$tmp)) {
                        $msg = $tmp['detail'];
                    }
                } else {
                    $msg = 'Unknown response';
                }
            }
            self::setErrorMsg('HTTP-'.$array["http_code"] . ": " . $msg);
            print_r($array);
        }
    }

    public static function startAirConditioning(int $temp = 20)
    {
        if (!self::vehicleSupport(__FUNCTION__)) {
            return null;
        }
        $temp = max(10, $temp);
        $temp = min(30, $temp);
        $data = self::mkAcBody($temp);
        $uri = self::API_HOST . '/vehicles/' . self::$vin . '/air-conditioning/start';
        $option = array('http_method' => 'POST');
        $ret = self::_apiFetch($uri, $data, $option);
        if ($ret['http_code'] < 300) {
            self::purgeCache();
            return true;
        }
        self::errorHandling($ret);
        return false;
    }

    public static function stopActiveVentilation()
    {
        if (!self::vehicleSupport(__FUNCTION__)) {
            return null;
        }
        //TODO: Add code
        return null;

    }

    public static function stopAirConditioning()
    {
        if (!self::vehicleSupport(__FUNCTION__)) {
            return null;
        }
        if (empty(self::$vin) || empty(self::$key)) {
            self::setErrorMsg('Cars VIN and/or API-KEY not set.');
            return null;
        }
        $uri = self::API_HOST . '/vehicles/' . self::$vin . '/air-conditioning/stop';
        $option = array('http_method' => 'POST');
        $ret = self::_apiFetch($uri, array(), $option);
        if ($ret['http_code'] < 300) {
            self::purgeCache();
            return true;
        }
        self::errorHandling($ret);
        return false;
    }

    public static function stopAuxiliaryHeating()
    {
        if (!self::vehicleSupport(__FUNCTION__)) {
            return null;
        }
        //TODO: Add code
        return null;
    }

    public static function updateChargingProfile()
    {
        if (!self::vehicleSupport(__FUNCTION__)) {
            return null;
        }
        //TODO: Add code
        return null;

    }
}

class skodaLnxCmd extends skodaApi {
    public static function help(): void
    {
        echo "HELP " . PHP_EOL . PHP_EOL;
        echo "Options:" . PHP_EOL;
        echo "skoda.status AC:      Turn on AC (heat/cool)." . PHP_EOL;
        echo "skoda.status heat:    Turn on heat (Webasto?)." . PHP_EOL;
        echo "skoda.status reset:   Reset AC and heat" . PHP_EOL;
        echo "skoda.status status:  Display status." . PHP_EOL;
        echo "skoda.status support: What do you car support." . PHP_EOL;
        echo PHP_EOL . PHP_EOL;
        echo "Config:"
                . "The script needs the VIN and API key. The pin will be requested if not found." . PHP_EOL
                . "Set the following as env vars or in the configure file:" . PHP_EOL
                . "You can get the VIN and API key from the mySkoda app." . PHP_EOL
                . "# Filename: " . self::getConfigFileName() . PHP_EOL;
        echo "SKODA_VIN='TMBJB9NY5RF999999'" . PHP_EOL;
        echo "SKODA_KEY='msk_lkjadsfpoiaupojljm'" . PHP_EOL;
        echo "SKODA_PIN='1234'" . PHP_EOL;
        echo PHP_EOL . PHP_EOL;
        echo "# Note: This program have no affiliations with Škoda, it is published 'as is'. The script is" . PHP_EOL;
        echo "# primary made for the author to start the AC (cooling and heating) from Linux command line." . PHP_EOL;
        echo "# The application is tested against a 2025/11 Škoda Elroq." . PHP_EOL;
        echo PHP_EOL . PHP_EOL;
        echo "©2026 MEM76: https://github.com/mem76/skodaLnxCmd" . PHP_EOL;
        exit();
    }
    public static function printJsonStatus(): void
    {
        $a = array();
        $a['charge_done'] = self::getChargeDone();
        $a['charge_power'] = self::getChargingPower();
        $a['charge_rate'] = self::getChargeRate();
        $a['locked'] = self::getLockedStatus();
        $a['odometer'] = self::getOdo();
        $a['parking_location'] = self::getParkingLocation();
        $a['range'] = self::getRange();
        $a['soc'] = self::getSoc();
        echo json_encode($a, JSON_UNESCAPED_UNICODE, JSON_PRETTY_PRINT);
    }
    public static function printStatus(): void
    {
        $width = 70;
        $plateNumber = self::getPlateNumber();
        $top = mb_str_pad("[" , ($width - strlen($plateNumber)) / 2, "#", STR_PAD_LEFT);
        echo mb_str_pad($top . $plateNumber . ']', $width, "#");
        echo PHP_EOL;
        $print=array();
        $print[0][0] = mb_str_pad("ODO:", 8) . self::getOdo() . ' km';
        $print[1][0] = mb_str_pad("SOC:", 8) . self::getSoc() . '%';
        $print[2][0] = mb_str_pad("Range: ", 8) . self::getRange() . ' km';
        $print[3][0] = mb_str_pad("Locked: ", 8) . self::getLockedStatus();

        if (self::isCharging()) {
            $print[0][1] = "Is charging";
            $print[1][1] = mb_str_pad("Charging power:", 16) . self::getChargingPower() . ' kW';
            $print[2][1] = mb_str_pad("Sharing rate:", 16) . self::getChargeRate() . ' km/h';
            if (self::getChargingState()=='CONSERVING') {
                $print[3][1] = "Done charging";
            } else {
                //$print[3][1] = "Done at: " . self::getChargeDone();
                $print[3][1] = "Done in: " . self::getChargeDoneIn();
            }
        } else {
            $print[0][1] = "Not charging";
            if (self::getChargingState()=='READY_FOR_CHARGING') {
                $print[1][1] = "Connected, not charging";
            } else {
                $print[1][1] = "";
            }
            $print[2][1] = "";
            $print[3][1] = "";
        }
        $col0 = 23;
        $col1 = $width - $col0;
        foreach ($print as $row) {
            echo mb_str_pad('# ' . $row[0], $col0 - 1);
            echo mb_str_pad('# ' . $row[1], $col1 - 1);
            echo " #";
            echo PHP_EOL;
        }
        echo mb_str_pad('#', $width, '#') . PHP_EOL;
        if (self::getParkingLocation()) {
            $str = "# Parked: " . self::getParkingLocation();
            echo mb_str_pad($str, $width - 1) . '#' . PHP_EOL;
        }
        if (self::getAcStatus()) {
            $str = "# AC: On, target: " . self::getAcTargetTemp() . '°C';
            $str .= ' (' . self::getAcStatusValue() . ')';
            echo mb_str_pad($str, $width-1) . '#'. PHP_EOL;
        }
        if (self::getParkingLocation() || self::getAcStatus()){
            echo mb_str_pad('#', $width, '#') . PHP_EOL;
        }

        $rateArray = self::getRateLimitStatus();
        $rate = 'Rate limit: '
                . $rateArray['ratelimit-remaining'] . '/' . $rateArray['ratelimit-limit']
                . ' (' . floor(
                        ($rateArray['ratelimit-reset'] - (time() - $rateArray['timestamp']))
                        / 60) . ' minutes left)';
        echo mb_str_pad('# ' . $rate, $width - 1) . '#' . PHP_EOL;
        echo mb_str_pad('# ' . "Key expires: " . $rateArray['x-api-key-expires-at'], $width - 1) . '#' . PHP_EOL;
        echo mb_str_pad('#', $width, '#') . PHP_EOL;
    }
    public static function reset():void
    {
        self::stopActiveVentilation();
        self::stopAirConditioning();
        self::stopAuxiliaryHeating();
    }
    
    public static function requestSecurityPin(): void
    {
        if (self::isPinSet()){
            return;
        }
        echo "Enter security PIN: ";
        $pin = trim(fgets(STDIN));
        self::setSecurityPin($pin);
    }

}


/* 
 * The class license ends at the class boundaries. The rest of the code below is simple boilerplate code, and therefor
 * licensed under MIT.
 */

// Check if curl is installed
if (!extension_loaded('curl')) {
    fwrite(STDERR, "Error: PHP cURL extension is not installed or enabled.\n");
    exit(1);
}

// Check argument
if ($argc < 2) {
    echo "Usage: {$argv[0]} <action>\n";
    echo "Valid actions: AC, heat, status, json, reset, help\n";
    exit(1);
}

$action = strtolower($argv[1]);
// Help
if ($action == 'help') {
    skodaLnxCmd::help();
}

// Request PIN for all actions except status
if (!preg_match('/^(status|json|support|ac(|-off)|charge(|-on|-off))$/', $action)) {
    skodaLnxCmd::requestSecurityPin();
    // Exit if PIN is empty
    if (skodaLnxCmd::isPinSet()) {
        echo "Security PIN is required.\n";
        exit(1);
    }
}

switch ($action) {
    case 'ac':
        if (skodaLnxCmd::startAirConditioning()) {
            echo "Air conditioning has been started.\n";
        } else {
            echo "An error occurred" . PHP_EOL;
            echo skodaLnxCmd::getErrorMsg() . PHP_EOL;
        }
        break;

    case 'heat':
        // TODO: Handle heat action
        break;

    case 'status':
        // TODO: Handle status action
        skodaLnxCmd::printStatus();
        break;
    case 'json':
        // TODO: Handle status action
        skodaLnxCmd::printJsonStatus();
        break;
    case 'reset':
        skodaLnxCmd::reset();
        break;
    case 'ac-off':
        if (skodaLnxCmd::stopAirConditioning()) {
            echo "Air conditioning has been stopped.\n";
        } else {
            echo "An error occurred" . PHP_EOL;
            echo skodaLnxCmd::getErrorMsg() . PHP_EOL;
        }
        break;
    case 'charge':
    case 'charge-on':
        if (skodaLnxCmd::startCharging()) {
            if (skodaLnxCmd::getErrorMsg()) {
                echo skodaLnxCmd::getErrorMsg() . PHP_EOL ;
            }
            echo "Charging has been started.\n";
        } else {
            echo "An error occurred" . PHP_EOL;
            echo skodaLnxCmd::getErrorMsg() . PHP_EOL;
        }
        break;
    case 'charge-off':
        if (skodaLnxCmd::stopCharging()) {
            echo "Charging has been paused.\n";
        } else {
            echo "An error occurred" . PHP_EOL;
            echo skodaLnxCmd::getErrorMsg() . PHP_EOL;
        }
        break;
    case 'support':
        $d=skodaLnxCmd::getVehicleSupport();
        echo "Vehicle claim to support:\n";
        foreach ($d['functions'] as $v) {
            echo "* {$v}\n";
        }
        echo "\n";
        echo "Charge modes: ".PHP_EOL;
        foreach ($d['chargeModes'] as $v) {
            echo "* {$v}\n";
        }
        break;

    default:
        echo "Unknown action: {$argv[1]}\n";
        echo "Valid actions: AC, heat, status, reset\n";
        exit(1);
}
