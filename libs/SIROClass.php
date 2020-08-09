<?php

declare(strict_types=1);

namespace SIRO;

/* * @addtogroup siro
 * @{
 *
 * @package       SIRO
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2020 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       0.1
 * @example <b>Ohne</b>
 */

 class BridgeCommand
 {
     const VERSION = 'V';
     const ADDRESS = 'G';
     const UPDATE_MODE = 'C';
     const REBOOT = 'R';
     const DEVICE = 'D';
     public static function ToString( string $Command)
     {
         switch ($Command) {
            case self::ADDRESS:
                return 'Address';
            case self::DEVICE:
                return 'Device';
            case self::REBOOT:
                return 'reboot';
            case self::UPDATE_MODE:
                return 'Status update mode';
            case self::VERSION:
                return 'Version';
            default:
                return 'unknown command';
         }
     }
 }

class BridgeFrame
{
    /**
     * Alle Kommandos als 1byte Char.
     *
     * @var string
     */
    public $Command;

    /**
     * BridgeAddress als String
     * @var string
     */
    public $Address;

    /**
     * Payload als string.
     *
     * @var string
     */
    public $Data;

    public function __construct($Command, $Address = '', $Data = '')
    {
        if ($Command[0] == '!') {
            $this->Address = substr($Command, 1, 3);
            $this->Command = $Command[4];
            $this->Data = substr($Command, 5);
        } else {
            $this->Command = $Command;
            $this->Address = $Address;
            $this->Data = $Data;
        }
    }

    public function ToJSONStringForIO()
    {
        $SendData = new \stdClass();
        $SendData->DataID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
        $SendData->Buffer = utf8_encode($this->EncodeFrame());
        return json_encode($SendData);
    }

    public function EncodeFrame()
    {
        //Frame  bauen.
        $Frame = '!';
        $Frame .= $this->Address;
        $Frame .= $this->Command;
        $Frame .= $this->Data;
        $Frame .= ';';
        return $Frame;
    }
}

class DeviceCommand
{
    const VERSION = 'v';
    const OPEN = 'o';
    const STOP = 's';
    const CLOSE = 'c';
    const LIFT = 'm';
    const TILT = 'b';
    const THIRD_POSITION = 'f';
    const QUERY = '?';
    const REPORT_STATE = 'r';
    const POWER = 'P';
    const ALIAS_NAME = 'N';
    const ERROR = 'E';

    public static function ToString(string $Command)
    {
        switch ($Command) {
            case self::OPEN:
                return 'OPEN';
            case self::STOP:
                return 'STOP';
            case self::CLOSE:
                return 'CLOSE';
            case self::LIFT:
                return 'LIFT';
            case self::THIRD_POSITION:
                return 'THIRD_POSITION';
            case self::QUERY:
                return 'QUERY';
            case self::REPORT_STATE:
                return 'REPORT_STATE';
            case self::POWER:
                return 'POWER';
            case self::ALIAS_NAME:
                return 'ALIAS_NAME';
            case self::ERROR:
                return 'ERROR';
           default:
               return 'unknown command';
        }
    }
}

class DeviceError
{
    const DEVICE_BUSY = 'bz';
    const DEVICE_NOT_EXISTS = 'np';
    const POSITION_LIMIT_OCCURRED = 'nc';
    const HALL_SENSOR_ERROR_M = 'mh';
    const HALL_SENSOR_ERROR_S = 'sh';
    const OPEN_OBSTACLE_RECOGNIZED = 'or';
    const CLOSE_OBSTACLE_RECOGNIZED = 'cr';
    const POWER_TO_LOW = 'pl';
    const POWER_TO_HIGH = 'ph';
    const DEVICE_OFFLINE = 'nl';
    const UNDEFINED_ERROR = 'ec';

    public static function ToString(string $Error)
    {
        switch ($Error) {
            case self::DEVICE_BUSY:
                return 'DEVICE_BUSY.';
            case self::DEVICE_NOT_EXISTS:
                return 'DEVICE_NOT_EXISTS.';
            case self::POSITION_LIMIT_OCCURRED:
                return 'POSITION_LIMIT_OCCURRED';
            case self::HALL_SENSOR_ERROR_M:
                return 'HALL_SENSOR_ERROR_M';
            case self::HALL_SENSOR_ERROR_S:
                return 'HALL_SENSOR_ERROR_S';
            case self::OPEN_OBSTACLE_RECOGNIZED:
                return 'OPEN_OBSTACLE_RECOGNIZED';
            case self::CLOSE_OBSTACLE_RECOGNIZED:
                return 'CLOSE_OBSTACLE_RECOGNIZED';
            case self::POWER_TO_LOW:
                return 'POWER_TO_LOW.';
            case self::POWER_TO_HIGH:
                return 'POWER_TO_HIGH.';
            case self::UNDEFINED_ERROR:
                return 'UNDEFINED_ERROR.';
            case self::DEVICE_OFFLINE:
                return 'DEVICE_OFFLINE.';
        }
    }
}
class DeviceFrame
{
    /**
     * Alle Kommandos als 1byte Char.
     *
     * @var string
     */
    public $Command;

    /**
     * DeviceAddress als String
     * @var string
     */
    public $Address;

    /**
     * Payload als string.
     *
     * @var string
     */
    public $Data;
    public function __construct($Command, $Address = '', $Data = '')
    {
        if (strlen($Command) == 1) {
            $this->Command = $Command;
            $this->Address = $Address;
            $this->Data = $Data;
        } else {
            $this->Address = substr($Command, 0, 3);
            $this->Command = $Command[3];
            $this->Data = substr($Command, 4);
        }
    }
    public function ToJSONStringForSplitter()
    {
        return $this->ToJSON('{5138E7A2-ECEE-ED0E-2DFF-759D556AD9CB}');
    }
    public function ToJSONStringForDevice()
    {
        return $this->ToJSON('{656600E1-FC88-50CD-EBEC-6B8A9BA157FA}');
    }
    public function EncodeFrame()
    {
        //Frame  bauen.
        $Frame = $this->Address;
        $Frame .= $this->Command;
        $Frame .= $this->Data;
        return $Frame;
    }
    private function ToJSON(string $GUID)
    {
        $SendData = new \stdClass();
        $SendData->DataID = $GUID;
        $SendData->DeviceCommand = $this->Command;
        $SendData->DeviceAddress = $this->Address;
        $SendData->Data = $this->Data;
        return json_encode($SendData);
    }
}
trait ErrorHandler
{
    protected function ModulErrorHandler($errno, $errstr)
    {
        $this->SendDebug('ERROR', utf8_decode($errstr), 0);
        echo $errstr."\r\n";
    }
}
trait DebugHelper
{
    protected function SendDebug($Message, $Data, $Format)
    {
        if (is_a($Data, '\\SIRO\\BridgeFrame')) {
            /* @var $Data \SIRO\BridgeFrame */
            $this->SendDebug($Message . ':Command', \SIRO\BridgeCommand::ToString($Data->Command), 0);
            $this->SendDebug($Message . ':Address', $Data->Address, 0);
            if ($Data->Data == '') {
                $this->SendDebug($Message . ':Data', $Data->Data, $Format);
            }
        } elseif (is_a($Data, '\\SIRO\\DeviceFrame')) {
            /* @var $Data \SIRO\DeviceFrame */
            $this->SendDebug($Message . ':Command', \SIRO\DeviceCommand::ToString($Data->Command), 0);
            $this->SendDebug($Message . ':Address', $Data->Address, 0);
            if ($Data->Data == '') {
                $this->SendDebug($Message . ':Data', $Data->Data, $Format);
            }
        } else if (is_array($Data)) {
            if (count($Data) == 0) {
                $this->SendDebug($Message, '[EMPTY]', 0);
            } elseif (count($Data) > 25) {
                $this->SendDebug($Message, array_slice($Data, 0, 20), 0);
                $this->SendDebug($Message . ':CUT', '-------------CUT-----------------', 0);
                $this->SendDebug($Message, array_slice($Data, -5, null, true), 0);
            } else {
                foreach ($Data as $Key => $DebugData) {
                    $this->SendDebug($Message . ':' . $Key, $DebugData, 0);
                }
            }
        } elseif (is_object($Data)) {
            if (count(get_object_vars($Data)) == 0) {
                $this->SendDebug($Message, '[EMPTY]', 0);
            }
            foreach ($Data as $Key => $DebugData) {
                $this->SendDebug($Message . '->' . $Key, $DebugData, 0);
            }
        } elseif (is_bool($Data)) {
            parent::SendDebug($Message, ($Data ? 'TRUE' : 'FALSE'), 0);
        } else {
            if (IPS_GetKernelRunlevel() == KR_READY) {
                parent::SendDebug($Message, (string) $Data, $Format);
            } else {
                $this->LogMessage($Message . ':' . (string) $Data, KL_DEBUG);
            }
        }
    }
}
/* @} */
