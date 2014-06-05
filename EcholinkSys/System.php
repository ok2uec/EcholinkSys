<?php

/**
 * This file is part of the EcholinkSys package.
 *
 * (c) Martin Nakládal <nakladal@intravps.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 * @package echolinksys
 * @author Martin Nakládal
 * @copyright 2010
 * @version 2
 * @access public
 */

namespace EcholinkSys;

use DateTime;
use PDO;

class System {

    /**
     * Response CODE
     */
    CONST RESPONSE_OK = "response_ok";
    CONST RESPONSE_SERVER_DISCONNECT = "server_disconnect";
    CONST RESPONSE_SERVER_PROBLEM = "server_problem"; 
    /**
     * Domain from which the data will be downloaded
     */
    CONST domainVerification = "http://echolink.org/logins.jsp";

    /**
     * List of all repeater in the registr
     *
     * @var EcholinkSys[]
     */
    private static $repeaterList = array();

    /**
     * List of all repeater in the registr new
     *
     * @var MessagesSend[]
     */
    public $messageEmail = array();

    /**
     * MYSQL connect
     * 
     * @var PDO $mysql
     */
    private $mysql = null;

    /**
     * Response message for check fce
     * @var String $responseStat
     */
    public $responseStat = self::RESPONSE_OK;

    /**
     * MYSQL parram
     */
    private $mysqlParram = array("dns" => "localhost", "username" => "root", "password" => "", "options" => null);

    public function __construct($PDOLocalhost, $PDOUsername, $PDOPassword, $PDOOPtions = null) {
        $this->mysqlParram = array("dns" => $PDOLocalhost, "username" => $PDOUsername, "password" => $PDOPassword, "options" => $PDOOPtions);

        $this->mysql = new \CentralApps\Pdo\Pdo($this->mysqlParram["dns"], $this->mysqlParram["username"], $this->mysqlParram["password"], $this->mysqlParram["options"]);
        //loaded of repeater Database
        $this->getRepeaterFromDBToRegistr();
    }

    /**
     * Adding a repeater to a list that will check whether it is connected.
     * @param string $call Callname repeater
     * @return boolean status
     */
    public function addRepeater($call, $email) {
        if (!array_key_exists($call, self::$repeaterList)) {
            $this->addRepeaterFromDBToRegistr($call, $email, false);
            self::$repeaterList[] = array("repeater" => strtoupper($call), "status" => null, "update" => null);
            return true;
        } else {
            return false;
        }
    }

    /**
     * update status and date repeater check.
     * @param string $callname Callname repeater
     * @param boolean $status status repeater
     * @param string $checkDate datum repeater
     * @return boolean status
     */
    public function updateRepeater($callname, $status, $checkDate) {
        $sql = "UPDATE `echolink_node` set `status` = :status,  `checkDate` = :checkdate where `callname` = :callname";
        $upStat = $this->mysql->prepare($sql);
        $upStat->bindParam("status", $status);
        $upStat->bindParam("checkdate", $checkDate);
        $upStat->bindParam("callname", $callname);
        $upStat->execute();
    }

    /**
     * Remove repeater
     * @param integer $idRepeater ID repeater!
     * @return boolean status
     */
    public function removeRepeater($idRepeater) {
        $sql = "DELETE FROM `echolink_node` WHERE `id` = :repeaterID";
        $upStat = $this->mysql->prepare($sql);
        $upStat->bindParam("repeaterID", $idRepeater);
        $upStat->execute();
        return true;
    }

    /**
     * Assignment proposal the selected entry
     * 
     * @return echolink_node data from db
     */
    public function getRepeater($callname) {
        $dprep = $this->mysql->prepare("SELECT * FROM echolink_node WHERE callname=:callname ORDER by id ASC");
        $dprep->bindParam(":callname", $callname);
        $dprep->execute();

        return $dprep->fetch();
    }

    /**
     * List of repeaters in array
     * 
     * @return Array Repeaters
     */
    public function getRepeaterInArray() {
        return self::$repeaterList;
    }

    /**
     * List the repeater to array (all)
     * 
     * @return boolean Success is true
     */
    public function getRepeaterFromDBToRegistr() {
        $result = $this->mysql->query("SELECT * FROM echolink_node ORDER by id ASC");
        foreach ($result->fetchAll() as $list) {
            self::$repeaterList[$list["callname"]] = $list;
        }
    }

    /**
     * add new echolink node to DB
     * @param integer $callname callname
     * @param email $email email format 
     * @param boolean $status Description
     * @return boolean
     */
    private function addRepeaterFromDBToRegistr($callname, $email, $status) {
        $sql = "INSERT INTO echolink_node (callname,checkDate,status,email) VALUES(:call,:date,:status,:email)";
        $q = $this->mysql->prepare($sql);

        $date = new DateTime();
        $dateResult = $date->format('Y-m-d H:i:s');

        $q->execute(array(
            ':call' => $callname,
            ':date' => $dateResult,
            ':email' => $email,
            ':status' => $status));

        return true;
    }

    /**
     * Add new line for history log
     * @param string $status status system
     * @return boolean Success is true
     */
    public function addHistoryLog($status) {
        $sql = "INSERT INTO echolink_history (checkDate,text) VALUES(:date,:status)";
        $q = $this->mysql->prepare($sql);

        $date = new DateTime();
        $dateResult = $date->format('Y-m-d H:i:s');

        $q->execute(array(
            ':date' => $dateResult,
            ':status' => $status));

        return true;
    }

    /**
     * list of history
     * 
     * @return boolean Success is true
     */
    public function getHistoryLog() {
        $result = $this->mysql->query("SELECT * FROM echolink_history ORDER by checkDate DESC limit 1000");
        return $result->fetchAll();
    }

    /**
     * Desolation control, data is downloaded from the web and check whether
     * there is a converter in the data and accordingly selects the online / offline
     * */
    public function dataFromTheServer() {

        /**
         * verify that the server is enabled to obtain data from remote server.
         * The function can be enabled only for local files and not on remote servers.
         */
        if (!file_get_contents("data:,ok")) {
            die("Houston, we have a stream wrapper problem.");
        }

        /**
         * Preparing for the future, if EchoLink server refused robots or Crone, head simulates user with firefox browser
         */
        $options = array(
            'http' => array(
                'method' => "GET",
                'header' => "Accept-language: en\r\n" .
                "User-Agent: Mozilla/5.0 (iPad; U; CPU OS 3_2 like Mac OS X; en-us) AppleWebKit/531.21.10 (KHTML, like Gecko) Version/4.0.4 Mobile/7B334b Safari/531.21.102011-10-16 20:23:10\r\n" // i.e. An iPad
            )
        );

        $context = stream_context_create($options);
        $echolinkRemoteData = @file_get_contents(self::domainVerification, false, $context);
        if (!empty($http_response_header)) {
            sscanf($http_response_header[0], 'HTTP/%*d.%*d %d', $code);
        }
        
        if ($code != 200) {
            if ($code == 503) {
                $this->responseStat = self::RESPONSE_SERVER_DISCONNECT;
            } else {
                $this->responseStat = self::RESPONSE_SERVER_PROBLEM;
            }
            return false;
        }

        $date = new DateTime();
        $dateResult = $date->format('Y-m-d H:i:s');

        $emailHTML = "text bude...";
        $status = "ok";

        if (count($echolinkRemoteData) > 0) {
            foreach (self::$repeaterList as &$repeater) {
                $repS = $repeater["callname"];
                $oldD = $repeater["checkDate"];
                $newStatus = strpos($echolinkRemoteData, strtoupper($repS) . "-R") ? true : false;
                $repeater["checkDate"] = $dateResult;
                $repeater["NewStatus"] = "No";

                if ($newStatus != $repeater["status"]) {
                    $repeater["NewStatus"] = "Yes";

                    $this->updateRepeater($repS, $newStatus, $dateResult);

                    $status .= " , (" . $repS . " - new status " . $newStatus . ") ";

                    /*
                     * If the converter has changed status, add it to the queue and then just starting to turn this feature
                     */
                    if ($repeater["email"]) {
                        $this->messageEmail[] = array("email" => $repeater["email"], "callname" => $repS, "oldCheckDate" => $oldD, "newStatus" => $newStatus, "checkDate" => $dateResult);
                    }
                }
            }
            $this->addHistoryLog($status);
//            $this->responseStat = self::RESPONSE_OK;
        } else {
            die("Houston, server is unavailable!");
            //EchoLink server is unavailable
        }
    }

    /**
     * Email support
     * 
     * @param string $to 
     * @param string $subject
     * @param string $body
     * @param string $from_name
     * @param string $from_a
     * @param string $reply
     */
    public function mail($to, $subject, $body, $from_name = "x", $from_a = "echolink@smoce.net", $reply = "reply@smoce.net") {
        $s = "=?utf-8?b?" . base64_encode($subject) . "?=";
        $headers = "MIME-Version: 1.0\r\n";
        $headers.= "From: =?utf-8?b?" . base64_encode($from_name) . "?= <" . $from_a . ">\r\n";
        $headers.= "Content-Type: text/plain;charset=utf-8\r\n";
        $headers.= "Reply-To: $reply\r\n";
        $headers.= "X-Mailer: PHP/" . phpversion();
        mail($to, $subject, $body, $headers);
    }

}
