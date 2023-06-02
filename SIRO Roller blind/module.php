<?php

declare(strict_types=1);
/*
 * @addtogroup siro
 * @{
 *
 * @package       SIRO Roller blind
 * @file          module.php
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2020 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       0.01
 */
require_once __DIR__ . '/../libs/SIROClass.php';  // diverse Klassen
eval('declare(strict_types=1);namespace SIRORollerblind {?>' . file_get_contents(__DIR__ . '/../libs/helper/VariableProfileHelper.php') . '}');
eval('declare(strict_types=1);namespace SIRORollerblind {?>' . file_get_contents(__DIR__ . '/../libs/helper/VariableHelper.php') . '}');

/**
 * @method void RegisterProfileInteger(string $Name, string $Icon, string $Prefix, string $Suffix, int $MinValue, int $MaxValue, int $StepSize)
 * @method void SetValueInteger(string $Ident, int $value)
 * @method void SetValueFloat(string $Ident, float $value)
 */
class SIRORollerblind extends IPSModule
{
    use \SIRO\DebugHelper;
    use \SIRO\ErrorHandler;
    use \SIRORollerblind\VariableProfileHelper;
    use \SIRORollerblind\VariableHelper;

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterPropertyString('Address', '000');
        $this->ConnectParent('{BC63861A-BEA5-9F77-FC6D-977665E8D839}');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->RegisterProfileInteger('SIRO.Tilt', 'TurnLeft', '', '%', 0, 180, 1);
        $this->RegisterVariableInteger('CONTROL', $this->Translate('Control'), '~ShutterMoveStop', 1);
        $this->EnableAction('CONTROL');
        $this->RegisterVariableInteger('LEVEL', $this->Translate('Level'), '~Shutter', 2);
        $this->EnableAction('LEVEL');
        $this->RegisterVariableInteger('TILT', $this->Translate('Tilt'), 'SIRO.Tilt', 3);
        $this->EnableAction('TILT');
        $this->RegisterVariableFloat('POWER', $this->Translate('Voltage'), '~Volt', 4);
        $Address = $this->ReadPropertyString('Address');
        $this->SetSummary($Address);
        if ($Address == '000') {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        $this->SetStatus(IS_ACTIVE);
        // Wenn Kernel nicht bereit, dann warten... KR_READY kommt ja gleich
        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->SetReceiveDataFilter('.*NOTING.*');
            return;
        }
        $Filter = '.*"DeviceAddress":"' . $Address . '".*';
        $this->SetReceiveDataFilter($Filter);
        $this->SendDebug('Filter', $Filter, 0);
        if ($this->HasActiveParent()) {
            $this->RequestState();
        }
    }

    public function RequestState(): bool
    {
        $ResultData = $this->SendData(\SIRO\DeviceCommand::REPORT_STATE, \SIRO\DeviceCommand::QUERY);
        if ($ResultData != null) {
            $this->DecodeEvent($ResultData);
            return true;
        }
        return false;
    }

    public function Open(): bool
    {
        $ResultData = $this->SendData(\SIRO\DeviceCommand::OPEN);
        if ($ResultData == null) {
            return false;
        }
        $this->SetValueInteger('CONTROL', 0);
        return true;
    }

    public function Close(): bool
    {
        $ResultData = $this->SendData(\SIRO\DeviceCommand::CLOSE);
        if ($ResultData == null) {
            return false;
        }
        $this->SetValueInteger('CONTROL', 4);
        return true;
    }

    public function Stop(): bool
    {
        $ResultData = $this->SendData(\SIRO\DeviceCommand::STOP);
        if ($ResultData == null) {
            return false;
        }
        $this->SetValueInteger('CONTROL', 2);
        return true;
    }

    public function Move(int $Value): bool
    {
        $ResultData = $this->SendData(\SIRO\DeviceCommand::LIFT, sprintf('%03d', $Value));
        if ($ResultData == null) {
            return false;
        }
        $this->SetValueInteger('LEVEL', (int) $ResultData->Data);
        return true;
    }

    public function Tilt(int $Value): bool
    {
        $ResultData = $this->SendData(\SIRO\DeviceCommand::TILT, sprintf('%03d', $Value));
        if ($ResultData == null) {
            return false;
        }
        $this->SetValueInteger('TILT', (int) $ResultData->Data);
        return true;
    }

    public function MoveTilt(int $Level, int $Tilt): bool
    {
        $ResultData = $this->SendData(
            \SIRO\DeviceCommand::LIFT,
            sprintf('%03d', $Level) .
            \SIRO\DeviceCommand::TILT .
            sprintf('%03d', $Tilt)
        );

        if ($ResultData == null) {
            return false;
        }
        $ResultData->Command = \SIRO\DeviceCommand::REPORT_STATE;
        $this->DecodeEvent($ResultData);
        return true;
    }
    /**
     * @param mixed $Value
     */
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'LEVEL':
                return $this->Move($Value);
            case 'TILT':
                return $this->Tilt($Value);
            case 'CONTROL':
                switch ($Value) {
                    case 0:
                        return $this->Open();
                    case 2:
                        return $this->Stop();
                    case 4:
                        return $this->Close();
                    }
                    set_error_handler([$this, 'ModulErrorHandler']);
                    trigger_error($this->Translate('Invalid value'), E_USER_NOTICE);
                    restore_error_handler();
                return false;
        }
        set_error_handler([$this, 'ModulErrorHandler']);
        trigger_error($this->Translate('Invalid ident'), E_USER_NOTICE);
        restore_error_handler();
        return false;
    }
    public function ReceiveData($JSONString)
    {
        $Data = json_decode($JSONString);
        $DeviceFrame = new \SIRO\DeviceFrame(
            $Data->DeviceCommand,
            $Data->DeviceAddress,
            $Data->Data
        );
        $this->SendDebug('Event', $DeviceFrame, 0);
        $this->DecodeEvent($DeviceFrame);
    }
    private function SendData(string $Command, string $Data = '')
    {
        $Address = $this->ReadPropertyString('Address');
        if ($Address == '000') {
            return null;
        }
        if (!$this->HasActiveParent()) {
            set_error_handler([$this, 'ModulErrorHandler']);
            trigger_error($this->Translate('Instance has no active parent.'), E_USER_NOTICE);
            restore_error_handler();
            return null;
        }
        $SiroFrame = new \SIRO\DeviceFrame($Command, $Address, $Data);
        $this->SendDebug('Send', $SiroFrame, 0);
        $Result = $this->SendDataToParent($SiroFrame->ToJSONStringForSplitter());
        if ($Result === false) {
            $this->SendDebug('Timeout', '', 0);
            // error wurde im Splitter geworfen.
            return null;
        }
        /**  @var \SIRO\DeviceFrame $ResultFrame	*/
        $ResultFrame = unserialize($Result);
        $this->SendDebug('Response', $ResultFrame, 0);
        if ($ResultFrame->Command == \SIRO\DeviceCommand::ERROR) {
            set_error_handler([$this, 'ModulErrorHandler']);
            trigger_error(\SIRO\DeviceError::ToString($ResultFrame->Data), E_USER_NOTICE);
            restore_error_handler();
            return null;
        }
        return $ResultFrame;
    }

    private function DecodeEvent(\SIRO\DeviceFrame $DeviceFrame)
    {
        switch ($DeviceFrame->Command) {
            case \SIRO\DeviceCommand::REPORT_STATE:
                $Part = explode('b', $DeviceFrame->Data);
                $Level = (int) $Part[0];
                $Tilt = (int) $Part[1];
                $this->SetValueInteger('LEVEL', $Level);
                $this->SetValueInteger('TILT', $Tilt);
                $this->RequestPowerState();
                break;
        }
    }

    private function RequestPowerState()
    {
        $ResultData = $this->SendData(\SIRO\DeviceCommand::POWER, 'Vc'.\SIRO\DeviceCommand::QUERY);
        if ($ResultData == null) {
            return false;
        }
        $this->SetValueFloat('POWER', ((int) substr($ResultData->Data,2)/100));
        return true;

    }
}
