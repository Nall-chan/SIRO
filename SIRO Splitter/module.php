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
 * @property array $ReplyDeviceFrames Enthält die versendeten DeviceFrame und speichert die Antworten.
 * @property string $BridgeAddress
 * @property \SIRO\BridgeFrame $ResponseFrame
 * @property bool $WaitForBridgeResponse
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
        parent::Create();
        $this->ReceiveBuffer = '';
        $this->WaitForBridgeResponse = false;
        $this->BridgeAddress = '000';
        $this->ReplyDeviceFrames=[];
        $this->RequireParent('{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}');
        if (IPS_GetKernelRunlevel() != KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
        }
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->ReceiveBuffer = '';
        $this->WaitForBridgeResponse = false;
        $this->BridgeAddress = '000';
        $this->ReplyDeviceFrames=[];
        $this->SetSummary('000');
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
                $this->UnregisterMessage(0, IPS_KERNELSTARTED);
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
            $Data->Data,
            $Data->needResponse
        );
        if ($Data->needResponse) {
            $this->SendQueuePush($DeviceFrame);
            $SendOk = $this->SendData(\SIRO\BridgeCommand::DEVICE, $DeviceFrame->EncodeFrame());
            $ResultData = $this->WaitForResponse($DeviceFrame);
            $this->SendDebug('Result Forward Device', $ResultData, 0);
            if ($ResultData === false) {
                trigger_error($this->Translate('No answer from Device'), E_USER_NOTICE);
                return false;
            }
            return serialize($ResultData);
        } else {
            $Result = $this->SendData(\SIRO\BridgeCommand::DEVICE, $DeviceFrame->EncodeFrame());
        }
        return serialize($Result);
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

            if ($SiroFrame->Command == \SIRO\BridgeCommand::DEVICE) {
                $DeviceFrame = new \SIRO\DeviceFrame($SiroFrame->Data);
                if (!$this->SendQueueUpdate($DeviceFrame)) {
                    $this->SendDebug('Event', $DeviceFrame, 0);
                    $ChildrenData = $DeviceFrame->ToJSONStringForDevice();
                    $this->SendDataToChildren($ChildrenData);
                }
            } else {
                if ($this->WaitForBridgeResponse) {
                    if (!$this->WriteResponseFrame($SiroFrame)) {
                        $this->SendDebug('ERROR', 'ResponseFrame is not empty', 0);
                    }
                    continue;
                }
            }
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
        $this->WaitForBridgeResponse = false;
        return null;
    }
    /**
     * Wartet auf eine Antwort einer Anfrage an die Bridge.
     *
     */
    private function WriteResponseFrame($ResponseFrame)
    {
        for ($i = 0; $i < 2000; $i++) {
            $Buffer = $this->ResponseFrame;
            if (is_null($Buffer)) {
                $this->ResponseFrame = $ResponseFrame;
                $this->WaitForBridgeResponse = false;
                return true;
            }
            usleep(1000);
        }
        return false;
    }

    //################# SENDQUEUE Devices

    /**
     * Fügt eine Anfrage in die SendQueue ein.
     *
     * @param \SIRO\DeviceFrame $DeviceFrame Das versendete DeviceFrame Objekt.
     */
    private function SendQueuePush(\SIRO\DeviceFrame $DeviceFrame)
    {
        if (!$this->lock('ReplyDeviceFrames')) {
            //throw new Exception($this->Translate('ReplyDeviceFrames is locked'), E_USER_NOTICE);
            return false;
        }
        $data = $this->ReplyDeviceFrames;
        if (array_key_exists($DeviceFrame->Address, $data)) {
            $this->unlock('ReplyDeviceFrames');
            return false;
        }
        $data[$DeviceFrame->Address] = null;
        $this->ReplyDeviceFrames = $data;
        $this->unlock('ReplyDeviceFrames');
        return true;
    }
    
    /**
     * Fügt eine Antwort in die SendQueue ein.
     *
     * @param \SIRO\DeviceFrame $DeviceFrame Das empfangene DeviceFrame Objekt.
     *
     * @return bool True wenn Anfrage zur Antwort gefunden wurde, sonst false.
     */
    private function SendQueueUpdate(\SIRO\DeviceFrame $DeviceFrame)
    {
        if (!$this->lock('ReplyDeviceFrames')) {
            // throw new Exception($this->Translate('ReplyDeviceFrames is locked'), E_USER_NOTICE);
            return false;
        }
        $key = $DeviceFrame->Address;
        $data = $this->ReplyDeviceFrames;
        if (array_key_exists($key, $data)) {
            $data[$key] = $DeviceFrame;
            $this->ReplyDeviceFrames = $data;
            $this->unlock('ReplyDeviceFrames');
            return true;
        }
        $this->unlock('ReplyDeviceFrames');
        return false;
    }
    /**
     * Wartet auf eine Antwort einer Anfrage an die Bridge LMS.
     *
     * @param \SIRO\DeviceFrame $DeviceFrame Das Objekt welches an die Bridge versendet wurde.
     * @result array|boolean Enthält ein Array mit den Daten der Antwort. False bei einem Timeout
     */
    private function WaitForResponse(\SIRO\DeviceFrame $DeviceFrame)
    {
        $SearchPatter = $DeviceFrame->Address;
        for ($i = 0; $i < 1000; $i++) {
            $Buffer = $this->ReplyDeviceFrames;
            if (!array_key_exists($SearchPatter, $Buffer)) {
                return false;
            }
            if ($Buffer[$SearchPatter] !== null) {
                $this->SendQueueRemove($SearchPatter);
                return $Buffer[$SearchPatter];
            }
            IPS_Sleep(5);
        }
        $this->SendQueueRemove($SearchPatter);
        return false;
    }
    /**
     * Löscht einen Eintrag aus der SendQueue.
     *
     * @param int $Index Der Index des zu löschenden Eintrags.
     */
    private function SendQueueRemove(string $Index)
    {
        if (!$this->lock('ReplyDeviceFrames')) {
            throw new Exception($this->Translate('ReplyDeviceFrames is locked'), E_USER_NOTICE);
        }
        $data = $this->ReplyDeviceFrames;
        unset($data[$Index]);
        $this->ReplyDeviceFrames = $data;
        $this->unlock('ReplyDeviceFrames');
    }
    private function SendData(string $Command, string $Data, bool $SetState = false)
    {
        $SiroFrame = new \SIRO\BridgeFrame($Command, $this->BridgeAddress, $Data);
        $Result = null;
        $this->SendDebug('Send', $SiroFrame, 0);
        try {
            if (!$this->HasActiveParent()) {
                throw new Exception($this->Translate('IO not connected'), E_USER_NOTICE);
            }
            $Data = $SiroFrame->ToJSONStringForIO();
            if ($Command == \SIRO\BridgeCommand::DEVICE) {
                return parent::SendDataToParent($Data);
            }
            if (!$this->lock('SendAPIData')) {
                throw new Exception($this->Translate('Send is blocked for'), E_USER_ERROR);
            }
            $this->ResponseFrame = null;
            $this->WaitForBridgeResponse = true;
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
            $this->WaitForBridgeResponse = false;
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
