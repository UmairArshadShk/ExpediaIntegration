<?php

namespace Fenix\Core\Import\ThirdParty;

use Fenix\Core\Database;
use Fenix\Core\Session;

// Used repository pattern to centerlized our database code
class ExpediaRepository {
    protected Database $db;
    protected Database $db_master;
    protected Session $session;

    public function __construct(Database $db, Database $db_master, Session $session) {
        $this->db = $db;
        $this->db_master = $db_master;
        $this->session = $session;
    }

    public function getExpediaGatewaySettingsCount() {
        $query_string = "SELECT * FROM settingsGateway WHERE gatewayIdentifier='Expedia' AND branchID=:branchID";
        $this->db->query($query_string);
        $this->db->bind(":branchID", $this->session->getBranchID());
        $this->db->execute();
        return $this->db->rowCount();
    }

    public function getConsultantExpediaGatewaySettingsCount() {
        $query_string = "SELECT * FROM consultantGatewaySettings WHERE gatewayName='Expedia' AND consultantID=:consultantID";
        $this->db_master->query($query_string);
        $this->db_master->bind(":consultantID", $this->session->getConsultantID());
        $this->db_master->execute();
        return $this->db->rowCount();
    }

    public function getBranchWideExpediaGatewaySettings() {
        $sql = "SELECT * FROM consultantGatewaySettings WHERE gatewayName='Expedia' AND branchID=:branchID AND extra2='BRANCH-WIDE'";
        $this->db_master->query($sql);
        $this->db_master->bind(":branchID", $this->session->getBranchID());
        $this->db_master->execute();
    }

    public function getOfficeWideExpediaGatewaySettings() {
        $sql = "SELECT * FROM consultantGatewaySettings WHERE gatewayName='Expedia' AND officeID=:officeID AND extra2='OFFICE-WIDE'";
        $this->db_master->query($sql);
        $this->db_master->bind(":officeID", $this->session->getOfficeID());
        $this->db_master->execute();
    }
    
    public function fetchApiSettingsCount($version, $country) {
        global $db_master;
        $sql = "SELECT * FROM settingsAPI WHERE name='EXPEDIA-$version' AND extra2=:country";
        $db_master->query($sql);
        $db_master->bind(":country", $country);
        $db_master->execute();
        return $db_master->rowCount();
    }

    //Helper Methods 

    public function getDBSingleRow() {
        return $this->db->single();
    }

    public function getSession() {
        return $this->session;
    }

    public function getMaseterDB() {
        return $this->db_master;
    }

    public function getDB() {
        return $this->db;
    }
    public function getMaseterDBSingleRow() {
        return $this->db_master->single();
    }

    public function getMaseterDBResultSet() {
        return $this->db_master->resultset();
    }
}
