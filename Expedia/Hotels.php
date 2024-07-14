<?php

namespace Fenix\Core\Import\ThirdParty\Expedia;

use Fenix\Core\Import\ThirdParty\Expedia;
use Fenix\Core\Import\ThirdParty\ExpediaRepository;
use Fenix\Core\Import\ThirdParty\SectorInsertTemplate;
use Fenix\Library\Objects\ItineraryType;
use Fenix\Library\Objects\TaxCode;
use Fenix\Library\Objects\Trip;
use Fenix\Library\Objects\TripSector;
use Fenix\Core\Database;
use Fenix\Core\Session;


 // Handles hotel booking imports from Expedia APIs, extending base functionality from Expedia class.
 // Manages API-specific URL, headers, and data processing for hotel rooms, including sector and itinerary generation.

class Hotels extends Expedia
{
    protected string $url = self::URL . 'hotels/bookings/';
    protected string $acceptString = 'Accept: application/vnd.exp-hotel.v3+json';
    private $sectorInsertTemplate;


    public function __construct($config, Database $db, Database $db_master, Session $session)
    {
        //Injecting the repository for db calls 
        $this->repository = new ExpediaRepository($db, $db_master, $session);
        $this->sectorInsertTemplate = new SectorInsertTemplate();
        parent::__construct($config, $this->repository);
    }

     // Modularized functions into smaller ones.
     //
     // Generates sector and itinerary information for hotel rooms, 
     // handling database insertion based on booking and session currencies.
    protected function generateSectorInformation(): void
    {
        
        $clone = $this->data;
        unset($clone['HotelDetails']['Rooms']);

        $hotelName = $this->data['HotelDetails']['Name'];
        $localCurrencyCode = $this->getLocalCurrencyCode();
        $agencyCurrency = $this->getAgencyCurrency();
        $GSTApplied = strtoupper($localCurrencyCode) === strtoupper($agencyCurrency);

        $today = date('Y-m-d H:i:s');
        $bookingDate = date('Y-m-d H:i:s', strtotime($this->data['BookingDateTime']));

        $debugCount = 0;
        foreach ($this->data['HotelDetails']['Rooms'] as $room) {
            $debugCount++;
            // instead of creating sectors here we can simply move it to another function
            $sectorInsert = $this->createSectorInsert($room, $hotelName, $GSTApplied, $debugCount, $bookingDate, $today);
            $this->sectors[] = (array) clone $sectorInsert;

            if ($this->debug) {
                $tripSectorID = $debugCount;
            } else {
                $this->repository->getDB()->insertObject(clone $sectorInsert, 'tripSector');
                $tripSectorID = $this->repository->getDB()->lastInsertId();
            }

            $itineraryInsert = $this->createItineraryInsert($room, $hotelName, $tripSectorID, $today);
            $this->itineraries[] = (array) clone $itineraryInsert;

            if (!$this->debug) {
                $this->repository->getDB()->insertObject(clone $itineraryInsert, 'itineraryAux');
            }
        }
    }

    private function getLocalCurrencyCode(): string
    {
        return isset($this->data['HotelDetails']['LocalCurrencyCode']) && !empty($this->data['HotelDetails']['LocalCurrencyCode'])
            ? $this->data['HotelDetails']['LocalCurrencyCode']
            : $this->repository->getSession()->getCurrencyCode();
    }

    private function getAgencyCurrency(): string
    {
        return isset($this->data['TotalPrice']['Currency']) && !empty($this->data['TotalPrice']['Currency'])
            ? $this->data['TotalPrice']['Currency']
            : $this->repository->getSession()->getCurrencyCode();
    }

    // Creates a sector insert object for a hotel room with trip details, dates, rates, and taxes.
    private function createSectorInsert($room, string $hotelName, bool $GSTApplied, int $debugCount, string $bookingDate, string $today): \stdClass
    {
        $sectorInsert = $this->sectorInsertTemplate->createTemplate();
        

        $sectorInsert->tripID = $this->tripID;
        $sectorInsert->productID = $this->productID;
        $sectorInsert->supplierID = $this->supplierID;
        $sectorInsert->consultantID = $this->repository->getSession()->getConsultantID();
        $sectorInsert->passengerID = $this->debug ? 1 : Trip::_getLeadPassengerID_FromTripID($this->tripID);
        $sectorInsert->localSectorID = $this->debug ? $debugCount : TripSector::_getNextLocalSectorID($this->tripID);
        $sectorInsert->details = $this->getRoomDetails($room, $hotelName);
        $sectorInsert->travelDate = current($room['StayDates'])['CheckInDate'];
        $sectorInsert->returnDate = current($room['StayDates'])['CheckOutDate'];
        $sectorInsert->ticketDate = $bookingDate;
        $sectorInsert->net = $this->getBaseRate($room, $this->getFees($room)) + $this->getFees($room);
        $sectorInsert->unitPrice = $this->getBaseRate($room, $this->getFees($room));
        $sectorInsert->fees = $this->getFees($room);
        $sectorInsert->total = $sectorInsert->unitPrice + $sectorInsert->fees;
        $sectorInsert->referenceNumber = $this->data['ItineraryNumber'];
        $sectorInsert->taxCodeID = $GSTApplied ? $this->repository->getSession()->getTaxCodeID() : TaxCode::FRE;
        $sectorInsert->taxesTaxCodeID = $sectorInsert->taxCodeID;
        $sectorInsert->createdDate = $today;
        $sectorInsert->dateActivated = $today;
        $sectorInsert->dateModified = $today;

        return $sectorInsert;
    }

    private function getRoomDetails($room, string $hotelName): string
    {
        $details = $hotelName . PHP_EOL;
        if (!empty($room['Description'])) {
            $details .= $room['Description'];
        }
        return $details;
    }

    private function getFees($room): int
    {
        return isset($room['Price']['TaxesAndFees']['Value']) ? $room['Price']['TaxesAndFees']['Value'] * 100 : 0;
    }

    // Calculates and returns the base rate in cents for a hotel room, considering 'BaseRate' or 'TotalPrice' minus fees if available.
    private function getBaseRate($room, int $fees): int
    {
        if (isset($room['Price']['BaseRate']['Value'])) {
            return $room['Price']['BaseRate']['Value'] * 100;
        } elseif (isset($room['Price']['TotalPrice']['Value'])) {
            return ($room['Price']['TotalPrice']['Value'] * 100) - $fees;
        }
        return 0;
    }

    // Creates an itinerary insert object for a hotel room with trip details, dates, and policies.
    private function createItineraryInsert($room, string $hotelName, int $tripSectorID, string $today): \stdClass
    {
        $travelDate = current($room['StayDates'])['CheckInDate'];
        $returnDate = current($room['StayDates'])['CheckOutDate'];
        $itineraryInsert = new \stdClass();


        $itineraryInsert->tripID = $this->tripID;
        $itineraryInsert->tripSectorID = $tripSectorID;
        $itineraryInsert->itineraryTypeID = ItineraryType::HOTEL;
        $itineraryInsert->subType = 'Expedia';
        $itineraryInsert->productID = $this->productID;
        $itineraryInsert->segTattoo = '';
        $itineraryInsert->segLineNumber = null;
        $itineraryInsert->segName = $hotelName;
        $itineraryInsert->classType = !empty($room['Description']) ? substr($room['Description'], 0, 64) : '';
        $itineraryInsert->RLRCode = '';
        $itineraryInsert->startDate = $travelDate;
        $itineraryInsert->startTime = $this->getFormattedTime($this->data['HotelDetails']['Policies']['CheckInStartTime'] ?? null);
        $itineraryInsert->endDate = $returnDate;
        $itineraryInsert->endTime = $this->getFormattedTime($this->data['HotelDetails']['Policies']['CheckOutTime'] ?? null);
        $itineraryInsert->startLocation = $this->getStartLocation();
        $itineraryInsert->startPhoneNumber = $this->getStartPhoneNumber();
        $itineraryInsert->inclusions = $this->getInclusions();
        $itineraryInsert->cancellationPolicy = $this->getCancellationPolicy($room);
        $itineraryInsert->createdDate = $today;

        return $itineraryInsert;
    }

    // Helper Methods to DRY up the code

    private function getFormattedTime(?string $time): ?string
    {
        return $time ? date("H:i:s", strtotime($time)) : null;
    }

    private function getStartLocation(): string
    {
        $location = $this->data['HotelDetails']['Location']['Address'] ?? [];
        return trim(
            ($location['Address1'] ?? '') . ' ' .
                ($location['City'] ?? '') . ' ' .
                ($location['Province'] ?? '') . ' ' .
                ($location['Country'] ?? '')
        );
    }

    private function getStartPhoneNumber(): string
    {
        $phone = current($this->data['HotelDetails']['PhoneInfos'] ?? []) ?? [];
        return trim(
            ($phone['CountryCode'] ?? '') . ' ' .
                ($phone['AreaCode'] ?? '') . ' ' .
                ($phone['Number'] ?? '')
        );
    }

    private function getInclusions(): string
    {
        $amenities = $this->data['HotelDetails']['HotelAmenities'] ?? [];
        return implode('<br/>', array_column($amenities, 'Name'));
    }

    private function getCancellationPolicy($room): ?string
    {
        $ratePlan = current($room['RatePlans'] ?? []);
        return $ratePlan['CancellationPolicy']['CancelPolicyDescription'] ?? null;
    }
}
