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

// Handles car rental booking imports from Expedia APIs, extending base functionality from Expedia class.
// Manages API-specific URL, headers, and data processing for car rentals, including sector and itinerary generation.
 
class Cars extends Expedia {
    private $sectorInsertTemplate;
    protected string $url = self::URL . 'cars/bookings/';
    protected string $acceptString = 'Accept: application/vnd.exp-car.v3+json';
    protected ExpediaRepository $repository;
    protected bool $debug = false;
    protected string $rawData;
    protected array $data = [];
    protected array $carDetails = [];
    protected array $vehicleDetails = [];
    protected string $bookingCurrencyCode;
    protected string $agencyCurrencyCode;
    protected bool $GSTApplied;
    protected int $debugCount;

    protected array $sectors = [];
    protected array $itineraries = [];


    public function __construct($config, Database $db, Database $db_master, Session $session) {
        //Injecting the repository for db calls 
        $this->repository = new ExpediaRepository($db, $db_master, $session);
        $this->sectorInsertTemplate = new SectorInsertTemplate();
        parent::__construct($config, $this->repository);
    }

    protected function generateSectorInformation(): void {
        // modularized functions into smaller ones
        $this->loadData();
        $this->calculateBookingCurrencyCode();
        $tripSectorID = $this->createSectorInsertObject();
        $this->createItineraryInsertObject($tripSectorID);
    }

    protected function loadData(): void {
        if ($this->debug) {
            $path = __DIR__ . '/Cars.json';
            $this->rawData = file_get_contents($path);
            $this->data = json_decode($this->rawData, true);
        }
        $this->carDetails = $this->data['CarDetails'];
        $this->vehicleDetails = $this->carDetails['VehicleDetails'];
    }


    // Sets the booking currency code based on car rental details or session settings, and checks GST applicability.
    protected function calculateBookingCurrencyCode(): void {
        $this->bookingCurrencyCode = !empty($this->carDetails['Price']['BasePrice']['Currency']) ?
            $this->carDetails['Price']['BasePrice']['Currency'] : $this->repository->getSession()->getCurrencyCode();
        $this->agencyCurrencyCode = $this->repository->getSession()->getCurrencyCode();
        $this->GSTApplied = strtoupper($this->bookingCurrencyCode) == strtoupper($this->agencyCurrencyCode);
    }


    // Sets up and stores itinerary details for car hire, including trip specifics, dates, locations, inclusions, and notes.
    protected function createItineraryInsertObject(int $tripSectorID): void {
        $itineraryInsert = new \stdClass();
        $itineraryInsert->tripID = $this->tripID;
        $itineraryInsert->tripSectorID = $tripSectorID;
        $itineraryInsert->itineraryTypeID = ItineraryType::CAR;
        $itineraryInsert->subType = 'Expedia';
        $itineraryInsert->productID = $this->productID;
        $itineraryInsert->segTattoo = '';
        $itineraryInsert->segLineNumber = null;
        $itineraryInsert->segName = 'Car Hire ' . $this->getClassType();
        $itineraryInsert->startDate = $this->getFormattedDate($this->carDetails['PickupDetails']['DateTime']);
        $itineraryInsert->endDate = $this->getFormattedDate($this->carDetails['DropOffDetails']['DateTime']);
        $itineraryInsert->startTime = $this->getFormattedTime($this->carDetails['PickupDetails']['DateTime']);
        $itineraryInsert->endTime = $this->getFormattedTime($this->carDetails['DropOffDetails']['DateTime']);
        $itineraryInsert->startLocation = $this->getLocation($this->carDetails['PickupDetails']['Location']['Address']);
        $itineraryInsert->endLocation = $this->getLocation($this->carDetails['DropOffDetails']['Location']['Address']);
        $itineraryInsert->inclusions = $this->getDetails();
        $itineraryInsert->notes = $this->getNotes($this->carDetails['CarPolicies']);

        $this->itineraries[] = (array) clone $itineraryInsert;

        if (!$this->debug) {
            $this->repository->getDB()->insertObject(clone $itineraryInsert, 'itineraryAux');
        }
    }

    protected function getClassType(): string {
        return $this->vehicleDetails['CarClass'] ?? ($this->vehicleDetails['Make'] ?? '');
    }

    protected function getFormattedDate(string $dateTime): string {
        return date('Y-m-d', strtotime($dateTime));
    }

    protected function getFormattedTime(string $dateTime): string {
        return date("H:i:s", strtotime($dateTime));
    }

    protected function getLocation(array $address): string {
        $location = '';
        if (!empty($address['Address1'])) $location .= $address['Address1'];
        if (!empty($address['City'])) $location .= ' ' . $address['City'];
        if (!empty($address['Province'])) $location .= ' ' . $address['Province'];
        if (!empty($address['Country'])) $location .= ' ' . $address['Country'];
        return $location;
    }

    // Constructs details about the car hire, including dates and vehicle specifications like make, doors, transmission, and capacity.
    protected function getDetails(): string {
        $details = 'Car Hire' . PHP_EOL;
        $details .= date('Y-m-d H:i:s', strtotime($this->carDetails['PickupDetails']['DateTime'])) . ' - ' . date('Y-m-d H:i:s', strtotime($this->carDetails['DropOffDetails']['DateTime'])) . PHP_EOL;
        if (!empty($this->vehicleDetails['Make'])) $details .= 'Make: ' . $this->vehicleDetails['Make'] . PHP_EOL;
        if (!empty($this->vehicleDetails['MinDoors'])) $details .= 'Min Doors: ' . $this->vehicleDetails['MinDoors'] . PHP_EOL;
        if (!empty($this->vehicleDetails['MaxDoors'])) $details .= 'Max Doors: ' . $this->vehicleDetails['MaxDoors'] . PHP_EOL;
        if (!empty($this->vehicleDetails['TransmissionDrive']['Value'])) $details .= 'Transmission: ' . $this->vehicleDetails['TransmissionDrive']['Value'] . PHP_EOL;
        if (!empty($this->vehicleDetails['Capacity']['AdultCount'])) $details .= 'Capacity: ' . $this->vehicleDetails['Capacity']['AdultCount'] . PHP_EOL;
        return $details;
    }

    // Gathers and formats notes from car policies into HTML, categorized by policy type.
    protected function getNotes(array $carPolicies): string {
        $notes = '';
        foreach ($carPolicies as $row) {
            if (!empty($row['PolicyText'])) {
                $notes .= '<h3>' . $row['CategoryCode'] . '</h3>';
                $notes .= $row['PolicyText'] . PHP_EOL;
            }
        }
        return $notes;
    }

    // Creates an object for inserting car hire details into the database, 
    // setting up various properties like dates, rates, and taxes.
    protected function createSectorInsertObject(): int {
        // Constants
        $details = $this->createDetailsString();
        $today = date('Y-m-d H:i:s');
        $debugCount = 0;

        $sectorInsert = $this->sectorInsertTemplate->createTemplate(
            $this->tripID,
            $this->productID,
            $this->supplierID,
            $this->repository->getSession()->getConsultantID(),
            date('Y-m-d', strtotime($this->carDetails['PickupDetails']['DateTime'])),
            date('Y-m-d', strtotime($this->carDetails['DropOffDetails']['DateTime'])),
            $this->calculateBaseRate(),
            $this->data['ItineraryNumber']
        );

        $sectorInsert->details = $details;
        $sectorInsert->ticketDate = date('Y-m-d', strtotime($this->data['BookingDateTime']));
        $sectorInsert->createdDate = $today;
        $sectorInsert->order = $this->debug ? 0 : TripSector::_getNextLocalSectorID($this->tripID);
        $sectorInsert->taxesTaxCodeID = $this->GSTApplied ? $this->repository->getSession()->getTaxCodeID() : TaxCode::FRE;
        $sectorInsert->dateActivated = $today;
        $sectorInsert->dateModified = $today;
        $sectorInsert->taxCodeID = $this->GSTApplied ? $this->repository->getSession()->getTaxCodeID() : TaxCode::FRE;
        $sectorInsert->localSectorID = $this->debug ? 0 : TripSector::_getNextLocalSectorID($this->tripID);
        $sectorInsert->passengerID = $this->debug ? 1 : Trip::_getLeadPassengerID_FromTripID($this->tripID);
        $sectorInsert->net = $this->calculateNet();
        $sectorInsert->unitPrice = $this->calculateBaseRate();
        $sectorInsert->fees = $this->calculateFees();

        $this->sectors[] = (array) clone $sectorInsert;

        if (!$this->debug) {
            $this->repository->getDB()->insertObject(clone $sectorInsert, 'tripSector');
            $tripSectorID = $this->repository->getDB()->lastInsertId();
        } else {
            $tripSectorID = $debugCount;
        }

        return $tripSectorID;
    }

    // Builds a string for car hire details, showing pickup and drop-off times.
    protected function createDetailsString(): string {
        return 'Car Hire' . PHP_EOL .
            date('Y-m-d H:i:s', strtotime($this->carDetails['PickupDetails']['DateTime'])) . ' - ' .
            date('Y-m-d H:i:s', strtotime($this->carDetails['DropOffDetails']['DateTime'])) . PHP_EOL;
    }

    protected function calculateNet(): int {
        return $this->calculateBaseRate() + $this->calculateFees();
    }

    // Returns the base rate in cents, using 'BaseRate' if set, or 'TotalPrice' minus fees if 'BaseRate' isn't available.
    protected function calculateBaseRate(): int {
        if (isset($this->carDetails['Price']['BaseRate']['Value'])) {
            return $this->carDetails['Price']['BaseRate']['Value'] * 100;
        } elseif (isset($this->carDetails['Price']['TotalPrice']['Value'])) {
            return ($this->carDetails['Price']['TotalPrice']['Value'] * 100) - $this->calculateFees();
        }
        return 0;
    }

    // Calculates fees in cents from 'TaxesAndFees' if set; otherwise returns 0.
    protected function calculateFees(): int {
        return isset($this->carDetails['Price']['TaxesAndFees']['Value']) ? $this->carDetails['Price']['TaxesAndFees']['Value'] * 100 : 0;
    }
}
