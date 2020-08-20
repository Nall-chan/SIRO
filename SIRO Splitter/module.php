<?php

declare(strict_types=1);
/*
 * @addtogroup siro
 * @{
 *
 * @package       SIRO Splitter
 * @file          module.php
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2020 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       0.01
 */
require_once __DIR__ . '/../libs/SIROClass.php';  // diverse Klassen

eval('declare(strict_types=1);namespace SIROSplitter {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
eval('declare(strict_types=1);namespace SIROSplitter {?>' . file_get_contents(__DIR__ . '/../libs/helper/ParentIOHelper.php') . '}');
eval('declare(strict_types=1);namespace SIROSplitter {?>' . file_get_contents(__DIR__ . '/../libs/helper/SemaphoreHelper.php') . '}');
eval('declare(strict_types=1);namespace SIROSplitter {?>' . file_get_contents(__DIR__ . '/../libs/helper/VariableHelper.php') . '}');

/**
 * SIROSplitter ist die Klasse für die SIRO Rollos
 * Erweitert ipsmodule.
 *
 * @property string $ReceiveBuffer Receive Buffer.
 * @property string $BridgeAddress
 * @property \SIRO\BridgeFrame $ResponseFrame
 * @property bool $WaitForResponse
 * @property int $ParentID Aktueller IO-Parent.
 */
class SIROSplitter extends IPSModule
{
    use \SIRO\DebugHelper;
    use \SIRO\ErrorHandler;
    use \SIROSplitter\Semaphore;
    use \SIROSplitter\VariableHelper;
    use \SIROSplitter\InstanceStatus;
    use \SIROSplitter\BufferHelper {
    \SIROSplitter\InstanceStatus::MessageSink as IOMessageSink;
    \SIROSplitter\InstanceStatus::RequestAction as IORequestAction;
}
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->ReceiveBuffer = '';
        $this->WaitForResponse = false;
        $this->BridgeAddress = '000';
        $this->RequireParent('{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}');
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
        $this->ReceiveBuffer = '';
        $this->WaitForResponse = false;
        $this->BridgeAddress = '000';
        $this->SetSummary('000');
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        $this->RegisterVariableString('FIRMWARE', $this->Translate('Firmware'), '', 0);
        // Wenn Kernel nicht bereit, dann warten... KR_READY kommt ja gleich
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $this->RegisterParent();

        // Wenn Parent aktiv, dann Anmeldung an der Hardware bzw. Datenabgleich starten
        if ($this->HasActiveParent()) {
            $this->IOChangeState(IS_ACTIVE);
        } else {
            $this->IOChangeState(IS_INACTIVE);
        }
    }
    /**
     * Nachrichten aus der Nachrichtenschlange verarbeiten.
     *
     * @param int       $TimeStamp
     * @param int       $SenderID
     * @param int       $Message
     * @param array|int $Data
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->IOMessageSink($TimeStamp, $SenderID, $Message, $Data);
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;
        }
    }
    /**
     * Interne Funktion des SDK.
     */
    public function RequestAction($Ident, $Value)
    {
        if ($this->IORequestAction($Ident, $Value)) {
            return true;
        }
        return false;
    }
    public function GetConfigurationForParent()
    {
        $ParentInstance = IPS_GetInstance($this->ParentID);
        if ($ParentInstance['ModuleInfo']['ModuleID'] == '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}') {
            $Config['StopBits'] = '1';
            $Config['BaudRate'] = '9600';
            $Config['Parity'] = 'None';
            $Config['DataBits'] = '8';
            return json_encode($Config);
        } else { // Kein SerialPort, sondern TCP oder XBEE Brücke. User muss selber den Port am Endgerät einstellen.
            return '';
        }
    }
    public function ForwardData($JSONString)
    {
        $Data = json_decode($JSONString);
        $DeviceFrame = new \SIRO\DeviceFrame(
            $Data->DeviceCommand,
            $Data->DeviceAddress,
            $Data->Data
        );
        $this->SendDebug('Forward Device', $DeviceFrame, 0);
        $ResultData = $this->SendData(\SIRO\BridgeCommand::DEVICE, $DeviceFrame->EncodeFrame());
        if ($ResultData == null) {
            return serialize(null);
        }
        return serialize(new \SIRO\DeviceFrame($ResultData->Data));
    }

    public function ReceiveData($JSONString)
    {
        $Data = utf8_decode((json_decode($JSONString)->Buffer));
        $Data = $this->ReceiveBuffer . $Data;
        $this->SendDebug('Receive Data', $Data, 0);
        $Start = strpos($Data, '!');
        if ($Start === false) {
            $this->SendDebug('ERROR', 'Start Marker not found', 0);
            $this->ReceiveBuffer = '';
            return false;
        }
        if ($Start != 0) {
            $this->SendDebug('WARNING', 'Start is ' . $Start . ' and not 0', 0);
            $Data = substr($Data, $Start);
        }
        // Stream in einzelne Pakete schneiden
        $Packets = explode(';', $Data);

        // Rest vom Stream wieder in den EmpfangsBuffer schieben
        $Tail = trim(array_pop($Packets));
        $this->ReceiveBuffer = $Tail;

        // Pakete verarbeiten
        foreach ($Packets as $Packet) {
            $SiroFrame = new \SIRO\BridgeFrame($Packet);
            $this->SendDebug('Receive', $SiroFrame, 0);
            if ($this->WaitForResponse) {
                if (!$this->WriteResponseFrame($SiroFrame)) {
                    $this->SendDebug('ERROR', 'ResponseFrame is not empty', 0);
                }
                continue;
            }
            if ($SiroFrame->Command != \SIRO\BridgeCommand::DEVICE) {
                $this->SendDebug('Wrong Command', $SiroFrame, 0);
                continue;
            }
            $DeviceFrame = new \SIRO\DeviceFrame($SiroFrame->Data);
            $this->SendDebug('Event', $DeviceFrame, 0);
            $ChildrenData = $DeviceFrame->ToJSONStringForDevice();
            $this->SendDataToChildren($ChildrenData);
        }
    }
    /**
     * Wird ausgeführt wenn der Kernel hochgefahren wurde.
     */
    protected function KernelReady()
    {
        if ($this->RegisterParent() > 0) {
            if ($this->HasActiveParent()) {
                $this->IOChangeState(IS_ACTIVE);
                return;
            }
        }
        $this->IOChangeState(IS_INACTIVE);
    }
    /**
     * Wird ausgeführt wenn sich der Status vom Parent ändert.
     */
    protected function IOChangeState($State)
    {
        // Wenn der IO Aktiv wurde
        if ($State == IS_ACTIVE) {
            if ($this->StartConnection()) {
                if ($this->SetAutoUpdateMode()) {
                    $this->SetStatus(IS_ACTIVE);
                    return;
                }
            }
            $this->SetStatus(IS_EBASE + 1);
        } else { // und wenn nicht
            $this->BridgeAddress = '000';
            $this->SetSummary('000');
            $this->SetStatus(IS_INACTIVE);
        }
    }

    private function StartConnection()
    {
        $Successful = false;
        $this->SendDebug(__FUNCTION__, 'begin', 0);
        $ResultData = $this->SendData(\SIRO\BridgeCommand::VERSION, \SIRO\DeviceCommand::QUERY, false);
        if ($ResultData != null) {
            $Successful = true;
            $this->BridgeAddress = $ResultData->Address;
            $this->SetSummary($ResultData->Address);
            $this->SetValue('FIRMWARE', $ResultData->Data);
        }
        $this->SendDebug(__FUNCTION__, 'end', 0);
        return $Successful;
    }
    private function SetAutoUpdateMode()
    {
        $Successful = false;
        $this->SendDebug(__FUNCTION__, 'begin', 0);
        $ResultData = $this->SendData(\SIRO\BridgeCommand::UPDATE_MODE, '1');
        if ($ResultData != null) {
            $Successful = ($ResultData->Data == '1');
        }
        $this->SendDebug(__FUNCTION__, 'end', 0);
        return $Successful;
    }
    /**
     * Wartet auf eine Antwort einer Anfrage an die Bridge.
     *
     */
    private function ReadResponseFrame()
    {
        for ($i = 0; $i < 2000; $i++) {
            $Buffer = $this->ResponseFrame;
            if (!is_null($Buffer)) {
                $this->ResponseFrame = null;
                return $Buffer;
            }
            usleep(1000);
        }
        $this->ResponseFrame = null;
        $this->WaitForResponse = false;
        return null;
    }
    /**
     * Wartet auf eine Antwort einer Anfrage an den LMS.
     *
     */
    private function WriteResponseFrame($ResponseFrame)
    {
        for ($i = 0; $i < 2000; $i++) {
            $Buffer = $this->ResponseFrame;
            if (is_null($Buffer)) {
                $this->ResponseFrame = $ResponseFrame;
                $this->WaitForResponse = false;
                return true;
            }
            usleep(1000);
        }
        return false;
    }
    private function SendData(string $Command, string $Data, bool $SetState = false)
    {
        $SiroFrame = new \SIRO\BridgeFrame($Command, $this->BridgeAddress, $Data);
        $Result = null;
        $this->SendDebug('Send', $SiroFrame, 0);
        try {
            if (!$this->lock('SendAPIData')) {
                throw new Exception($this->Translate('Send is blocked for: ') . \SIRO\BridgeCommand::ToString($SiroFrame->Command), E_USER_ERROR);
            }
            if (!$this->HasActiveParent()) {
                throw new Exception($this->Translate('IO not connected'), E_USER_NOTICE);
            }
            $Data = $SiroFrame->ToJSONStringForIO();
            $this->ResponseFrame = null;
            $this->WaitForResponse = true;
            parent::SendDataToParent($Data);
            $ResponseFrame = $this->ReadResponseFrame();
            if ($ResponseFrame === null) {
                throw new Exception($this->Translate('Timeout'), E_USER_NOTICE);
            }
            $this->SendDebug('Response', $ResponseFrame, 0);
            $this->unlock('SendAPIData');
            $Result = $ResponseFrame;
        } catch (Exception $exc) {
            if ($exc->getCode() != E_USER_ERROR) {
                $this->unlock('SendAPIData');
            }
            $this->WaitForResponse = false;
            $this->SendDebug('ERROR', $exc->getMessage(), 0);
            set_error_handler([$this, 'ModulErrorHandler']);
            trigger_error($exc->getMessage(), E_USER_NOTICE);
            restore_error_handler();
            if ($SetState) {
                $this->SetStatus(IS_EBASE + 2);
            }
        }

        return $Result;
    }
}