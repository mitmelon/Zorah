<?php

namespace Manomite\Engine;

use libphonenumber\{
    geocoding\PhoneNumberOfflineGeocoder,
    NumberParseException,
    PhoneNumberUtil,
    ShortNumberInfo,
    PhoneNumberToCarrierMapper,
    PhoneNumberToTimeZonesMapper,
    PhoneNumberFormat
};

class Phoner
{
    public $instance;

    private $phone;
    public function __construct(string $phone, string $country = null)
    {
        $this->phone = $phone;
        try {
            $this->instance = PhoneNumberUtil::getInstance()->parse($this->phone, $country);
        } catch (NumberParseException $e) {
            throw new \Exception($e);
        }
    }

    public function phoneInfo()
    {
        try {
            ///////////////////////////////////////////////////////////////////////////////////////////////////
            $phoneNumberUtil = PhoneNumberUtil::getInstance();
            $nationalSignificantNumber = $phoneNumberUtil->getNationalSignificantNumber($this->instance);
            $areaCodeLength = $phoneNumberUtil->getLengthOfGeographicalAreaCode($this->instance);

            $regionCode = PhoneNumberUtil::getInstance()->getRegionCodeForNumber($this->instance);
            if ($regionCode === '001') {
                $regionCode = null;
            }
            $possible = PhoneNumberUtil::getInstance()->isPossibleNumber($this->instance);
            $validNumber = PhoneNumberUtil::getInstance()->isValidNumber($this->instance);
            $numberType = PhoneNumberUtil::getInstance()->getNumberType($this->instance);
            $canbedialed = PhoneNumberUtil::getInstance()->canBeInternationallyDialled($this->instance);
            $phoneNumber = PhoneNumberUtil::getInstance()->format($this->instance, PhoneNumberFormat::E164);
            $intphoneNumber = PhoneNumberUtil::getInstance()->format($this->instance, PhoneNumberFormat::INTERNATIONAL);
            $natphoneNumber = PhoneNumberUtil::getInstance()->format($this->instance, PhoneNumberFormat::NATIONAL);
            $refphoneNumber = PhoneNumberUtil::getInstance()->format($this->instance, PhoneNumberFormat::RFC3966);
            $carrierCode = PhoneNumberUtil::getInstance()->formatNationalNumberWithCarrierCode($this->instance, 14);
            $numberType = match((string)$numberType){
                '0' => 'FIXED_LINE',
                '1' => 'MOBILE',
                '2' => 'FIXED_LINE_OR_MOBILE',
                '3' => 'TOLL_FREE',
                '4' => 'PREMIUM_RATE',
                '5' => 'SHARED_COST',
                '6' => 'VOIP',
                '7' => 'PERSONAL_NUMBER',
                '8' => 'PAGER',
                '9' => 'UAN',
                '10' => 'UNKNOWN',
                '27' => 'EMERGENCY',
                '28' => 'VOICEMAIL',
                '29' => 'SHORT_CODE',
                '30' => 'STANDARD_RATE',
                default => 'UNKNOWN'
            };              
            $description = PhoneNumberOfflineGeocoder::getInstance()->getDescriptionForNumber(
                $this->instance,
                'en',
                $regionCode
            );
            $phoneNumberToCarrierInfo = PhoneNumberToCarrierMapper::getInstance()->getNameForNumber(
                $this->instance,
                'en'
            );
            
            $timezone = PhoneNumberToTimeZonesMapper::getInstance()->getTimeZonesForNumber($this->instance);
            $timezone = is_array($timezone) ? $timezone[0] : null;
            //////////////////////////////////////////////////////////////////////////////////////////////////////////

            $countryCode = (string) $this->instance->getCountryCode();
            $areaCode = substr($nationalSignificantNumber, 0, $areaCodeLength);
            $nationalNumber = $this->instance->getNationalNumber();
            $region = $regionCode;
            $isPossible = $possible;
            $isValidNumber = $validNumber;
            $type = $numberType;
            $desc = $description;
            $carrier = $phoneNumberToCarrierInfo;

            return array('status' => true, 'response' => array(
                'number' => $phoneNumber,
                'international_number' => $intphoneNumber,
                'national_number' => $natphoneNumber,
                'readable_number' => $refphoneNumber,
                'countryCode' => $countryCode,
                'areaCode' => $areaCode,
                'local_format' => $nationalNumber,
                'region' => $region,
                'isPossible' => $isPossible,
                'isValidNumber' => $isValidNumber,
                'type' => $type,
                'carrier' => $carrier,
                'carrierCode' => $carrierCode,
                'isDialable' => $canbedialed,
                'timezone' => $timezone,
                'location' => $desc
            ));
        } catch(\Throwable $e) {
            throw new \Exception($e->getMessage());
        }
    }
}
