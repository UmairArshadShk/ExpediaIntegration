<?php

namespace Fenix\Core\Import\ThirdParty;

// this class helps to take out the long assignnment as it was only making original file long and most 
// of the values are constants, even same in cars and hotels
class SectorInsertTemplate {
    public function createTemplate(
        int $tripID = null,
        int $productID = null,
        int $supplierID = null,
        int $consultantID = null,
        string $pickupDateTime = null,
        string $dropOffDateTime = null,
        float $baseRate = null,
        string $itineraryNumber = null
    ): \stdClass {
        $sectorInsert = new \stdClass();

        $sectorInsert->tripSectorStatusID = 2;
        $sectorInsert->chargeTypeID = 1;
        $sectorInsert->qty = 1;
        $sectorInsert->GST = 0;
        $sectorInsert->commission = 0;
        $sectorInsert->discount = 0;
        $sectorInsert->isDiscountPerQty = 0;
        $sectorInsert->fullFare = 0;
        $sectorInsert->markup = 0;
        $sectorInsert->isMarkupPerQty = 0;
        $sectorInsert->fareOffered = 0;
        $sectorInsert->pnrFees = 0;
        $sectorInsert->pnrFeesGST = 0;
        $sectorInsert->isActive = 1;
        $sectorInsert->isClaimed = 0;
        $sectorInsert->isVaried = 0;
        $sectorInsert->isFee = 0;
        $sectorInsert->isLocked = 0;
        $sectorInsert->isQtyLocked = 0;
        $sectorInsert->referenceNumberOld = '';
        $sectorInsert->relocCode = null;
        $sectorInsert->valCarrier = null;
        $sectorInsert->tktType = null;
        $sectorInsert->tktIssueType = null;
        $sectorInsert->tourCode = null;
        $sectorInsert->policyException = '';
        $sectorInsert->travelReason = '';
        $sectorInsert->codeVersion = 1;
        $sectorInsert->consolidatorFees = 0;

        // Include dynamic values if provided
        if (isset($tripID)) {
            $sectorInsert->tripID = $tripID;
        }
        if (isset($productID)) {
            $sectorInsert->productID = $productID;
        }
        if (isset($supplierID)) {
            $sectorInsert->supplierID = $supplierID;
        }
        if (isset($consultantID)) {
            $sectorInsert->consultantID = $consultantID;
        }
        if (isset($pickupDateTime)) {
            $sectorInsert->travelDate = date('Y-m-d', strtotime($pickupDateTime));
        }
        if (isset($dropOffDateTime)) {
            $sectorInsert->returnDate = date('Y-m-d', strtotime($dropOffDateTime));
        }
        if (isset($baseRate)) {
            $sectorInsert->total = $baseRate;
        }
        if (isset($itineraryNumber)) {
            $sectorInsert->referenceNumber = $itineraryNumber;
        }

        return $sectorInsert;
    }
}
