<?php

declare(strict_types=1);
/*
 * @addtogroup siro
 * @{
 *
 * @package       SIRO Configurator
 * @file          module.php
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2020 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       0.01
 */
require_once __DIR__ . '/../libs/SIROClass.php';  // diverse Klassen
eval('declare(strict_types=1);namespace SIROConfigurator {?>' . file_get_contents(__DIR__ . '/../libs/helper/BufferHelper.php') . '}');
/**
 * SIROConfigurator ist die Klasse für das SIRO RS485 Interface
 * Erweitert ipsmodule.
 *
 * @property array $Devices
 * @property bool $SearchFinished
 */
class SIROConfigurator extends IPSModule
{
    use \SIRO\DebugHelper;
    use \SIROConfigurator\BufferHelper;

    public function Create()
    {
        parent::Create();
        $this->Devices = [];
        $this->SearchFinished = true;
        $this->ConnectParent('{BC63861A-BEA5-9F77-FC6D-977665E8D839}');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetReceiveDataFilter('.*"DeviceCommand":"' . \SIRO\DeviceCommand::VERSION . '".*');
    }

    public function GetConfigurationForm()
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $NodeValues = [];
        if (!$this->HasActiveParent()) {
            $Form['actions'][1]['visible'] = true;
            $Form['actions'][1]['popup']['items'][0]['caption'] = 'Instance has no active parent.';
            $Form['actions'][0]['items'][0]['visible'] = false;
        }
        $Values = $this->GetDevicesConfigValues();
        $Form['actions'][0]['values'] = $Values;
        $this->SendDebug('FORM', json_encode($Form), 0);
        $this->SendDebug('FORM', json_last_error_msg(), 0);
        return json_encode($Form);
    }

    public function ReceiveData($JSONString)
    {
        $Data = json_decode($JSONString);
        $DeviceFrame = new \SIRO\DeviceFrame(
            $Data->DeviceCommand,
            $Data->DeviceAddress,
            $Data->Data
        );
        if ($this->SearchFinished) {
            $this->SendDebug('Ignore', $DeviceFrame, 0);
            return;
        }
        $this->SendDebug('Event', $DeviceFrame, 0);
        if ($DeviceFrame->Address == 'FFF') {
            $this->SearchFinished = true;
            return;
        }
        $Devices = $this->Devices;
        $Devices[] = [
            'Address'=> $DeviceFrame->Address
        ];
        $this->Devices = $Devices;
    }

    private function GetDevicesConfigValues()
    {
        $FoundDevices = $this->GetDevicesFromBridge();
        $this->GetNamesFromBridge($FoundDevices);
        $this->SendDebug('Found Devices', $FoundDevices, 0);

        $InstanceIDList = [];
        $Splitter = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        foreach (IPS_GetInstanceListByModuleID('{742F943E-F3E2-0E3E-8F3D-9ACDDC379D26}') as $InstanceID) {
            // Fremde Geräte überspringen
            if (IPS_GetInstance($InstanceID)['ConnectionID'] == $Splitter) {
                $InstanceIDList[$InstanceID] = IPS_GetProperty($InstanceID, 'Address');
            }
        }
        $this->SendDebug('Known Instances', $InstanceIDList, 0);
        $Values = [];
        foreach ($FoundDevices as &$Device) {
            $InstanceIDDevice = array_search($Device['Address'], $InstanceIDList);
            if ($InstanceIDDevice !== false) {
                $Device['instanceID'] = $InstanceIDDevice;
                $Device['Name'] = IPS_GetName($InstanceIDDevice);
                $Device['Location'] = stristr(IPS_GetLocation($InstanceIDDevice), IPS_GetName($InstanceIDDevice), true);
                unset($InstanceIDList[$InstanceIDDevice]);
            } else {
                $Device['instanceID'] = 0;
                $Device['Name'] = $Device['Name'];
                $Device['Location'] = '';
            }
            $Device['create'] = [
                'moduleID'      => '{742F943E-F3E2-0E3E-8F3D-9ACDDC379D26}',
                'configuration' => ['Address' => $Device['Address']]
            ];
        }

        foreach ($InstanceIDList as $InstanceID => $DeviceAddress) {
            $FoundDevices[] = [
                'instanceID'  => $InstanceID,
                'Address'     => $DeviceAddress,
                'Name'        => IPS_GetName($InstanceID),
                'Location'    => stristr(IPS_GetLocation($InstanceID), IPS_GetName($InstanceID), true)
            ];
        }
        return $FoundDevices;
    }
    private function GetInstanceList(string $GUID, int $Parent, string $ConfigParam)
    {
        $InstanceIDList = [];
        foreach (IPS_GetInstanceListByModuleID($GUID) as $InstanceID) {
            // Fremde Geräte überspringen
            if (IPS_GetInstance($InstanceID)['ConnectionID'] == $Parent) {
                $InstanceIDList[] = $InstanceID;
            }
        }
        if ($ConfigParam != '') {
            $InstanceIDList = array_flip(array_values($InstanceIDList));
            array_walk($InstanceIDList, [$this, 'GetConfigParam'], $ConfigParam);
        }
        return $InstanceIDList;
    }
    private function GetDevicesFromBridge()
    {
        $this->SearchFinished = false;
        $DeviceFrame = new \SIRO\DeviceFrame(\SIRO\DeviceCommand::VERSION, '000', \SIRO\DeviceCommand::QUERY, false);
        $this->SendDebug('Send', $DeviceFrame, 0);
        $this->Devices = [];
        $Devices = [];
        $Result = @$this->SendDataToParent($DeviceFrame->ToJSONStringForSplitter());
        if ($Result === false) {
            $this->SendDebug('Timeout', '', 0);
            $this->SearchFinished = true;
            return $Devices;
        }
        /**  @var \SIRO\DeviceFrame $ResultFrame	*/
        //$ResultFrame = unserialize($Result);
        //$this->SendDebug('Response', $ResultFrame, 0);
        if (!$this->WaitForFinish()) {
            $this->SearchFinished = true;
            return $Devices;
        }
        $Devices = $this->Devices;
        //array_unshift($Devices, ['Address'=>$ResultFrame->Address]);
        return $Devices;
    }

    private function GetNamesFromBridge(array &$Devices)
    {
        foreach ($Devices as &$Device) {
            $DeviceFrame = new \SIRO\DeviceFrame(\SIRO\DeviceCommand::ALIAS_NAME, $Device['Address'], \SIRO\DeviceCommand::QUERY);

            $this->SendDebug('Send', $DeviceFrame, 0);
            $Result = @$this->SendDataToParent($DeviceFrame->ToJSONStringForSplitter());
            if ($Result === false) {
                $this->SendDebug('Timeout', '', 0);
                $Device['Name'] = '';
                continue;
            }
            /**  @var \SIRO\DeviceFrame $ResultFrame	*/
            $ResultFrame = unserialize($Result);
            $this->SendDebug('Response', $ResultFrame, 0);
            $Device['Name'] = $ResultFrame->Data;
        }
    }

    /**
     * Wartet auf den Abschluss der Suche.
     *
     */
    private function WaitForFinish()
    {
        for ($i = 0; $i < 5000; $i++) {
            if ($this->SearchFinished) {
                return true;
            }
            usleep(1000);
        }
        return false;
    }
}