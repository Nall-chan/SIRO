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
    public const VERSION = 'V';
    public const ADDRESS = 'G';
    public const UPDATE_MODE = 'C';
    public const REBOOT = 'R';
    public const DEVICE = 'D';
    public static function ToString(string $Command): string
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
    public string $Command;

    /**
     * BridgeAddress als String
     * @var string
     */
    public string $Address;

    /**
     * Payload als string.
     *
     * @var string
     */
    public string $Data;

    public function __construct(string $Command, string $Address = '', string $Data = '')
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

    public function ToJSONStringForIO(): string
    {
        $SendData = new \stdClass();
        $SendData->DataID = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
        $SendData->Buffer = bin2hex($this->EncodeFrame());
        return json_encode($SendData);
    }

    public function EncodeFrame(): string
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
    public const VERSION = 'v';
    public const OPEN = 'o';
    public const STOP = 's';
    public const CLOSE = 'c';
    public const LIFT = 'm';
    public const TILT = 'b';
    public const THIRD_POSITION = 'f';
    public const QUERY = '?';
    public const REPORT_STATE = 'r';
    public const POWER = 'p';
    public const ALIAS_NAME = 'N';
    public const ERROR = 'E';

    public static function ToString(string $Command): string
    {
        switch ($Command) {
            case self::VERSION:
                return 'VERSION';
            case self::OPEN:
                return 'OPEN';
            case self::STOP:
                return 'STOP';
            case self::CLOSE:
                return 'CLOSE';
            case self::LIFT:
                return 'LIFT';
            case self::TILT:
                return 'TILT';
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
    public const DEVICE_BUSY = 'bz';
    public const DEVICE_NOT_EXISTS = 'np';
    public const POSITION_LIMIT_OCCURRED = 'nc';
    public const HALL_SENSOR_ERROR_M = 'mh';
    public const HALL_SENSOR_ERROR_S = 'sh';
    public const OPEN_OBSTACLE_RECOGNIZED = 'or';
    public const CLOSE_OBSTACLE_RECOGNIZED = 'cr';
    public const POWER_TO_LOW = 'pl';
    public const POWER_TO_HIGH = 'ph';
    public const DEVICE_OFFLINE = 'nl';
    public const UNDEFINED_ERROR = 'ec';

    public static function ToString(string $Error): string
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
    public string $Command;

    /**
     * DeviceAddress als String
     * @var string
     */
    public string $Address;

    /**
     * Payload als string.
     *
     * @var string
     */
    public string $Data;

    /**
     * Flag ob auf Antwort gewartet werden muss.
     *
     * @var bool
     */
    public bool $needResponse;

    public function __construct(string $Command, string $Address = '', string $Data = '', bool $needResponse = true)
    {
        if (strlen($Command) == 1) {
            $this->Command = $Command;
            $this->Address = $Address;
            $this->Data = $Data;
            $this->needResponse = $needResponse;
        } else {
            $this->Address = substr($Command, 0, 3);
            $this->Command = $Command[3];
            $this->Data = substr($Command, 4);
            $this->needResponse = false;
        }
    }
    public function ToJSONStringForSplitter(): string
    {
        return $this->ToJSON('{5138E7A2-ECEE-ED0E-2DFF-759D556AD9CB}');
    }
    public function ToJSONStringForDevice(): string
    {
        return $this->ToJSON('{656600E1-FC88-50CD-EBEC-6B8A9BA157FA}');
    }
    public function EncodeFrame(): string
    {
        //Frame  bauen.
        $Frame = $this->Address;
        $Frame .= $this->Command;
        $Frame .= $this->Data;
        return $Frame;
    }

    private function ToJSON(string $GUID): string
    {
        $SendData = new \stdClass();
        $SendData->DataID = $GUID;
        $SendData->DeviceCommand = $this->Command;
        $SendData->DeviceAddress = $this->Address;
        $SendData->Data = $this->Data;
        $SendData->needResponse = $this->needResponse;
        return json_encode($SendData);
    }
}
trait ErrorHandler
{
    protected function ModulErrorHandler(int $errno, string $errstr): bool
    {
        $this->SendDebug('ERROR', $errstr, 0);
        echo $errstr . "\r\n";
        return true;
    }
}
trait DebugHelper
{
    protected function SendDebug(string $Message, mixed $Data, int $Format): bool
    {
        if (is_a($Data, '\\SIRO\\BridgeFrame')) {
            /* @var $Data \SIRO\BridgeFrame */
            $this->SendDebug($Message . ':Bridge:Address', $Data->Address, 0);
            $this->SendDebug($Message . ':Bridge:Command', \SIRO\BridgeCommand::ToString($Data->Command), 0);
            $this->SendDebug($Message . ':Bridge:Data', $Data->Data, $Format);
        } elseif (is_a($Data, '\\SIRO\\DeviceFrame')) {
            /* @var $Data \SIRO\DeviceFrame */
            $this->SendDebug($Message . ':Device:Address', $Data->Address, 0);
            $this->SendDebug($Message . ':Device:Command', \SIRO\DeviceCommand::ToString($Data->Command), 0);
            $this->SendDebug($Message . ':Device:needResponse', $Data->needResponse, 0);
            $this->SendDebug($Message . ':Device:Data', $Data->Data, $Format);
        } elseif (is_array($Data)) {
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
