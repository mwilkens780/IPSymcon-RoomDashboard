<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileKit.php';

class RoomDashboard extends IPSModule
{
    use \Fuchsbau\TileKit\RoomDashboard\TileKit;

    private const SENSOR_BOOL_TYPES = ['window', 'door', 'smoke', 'siren', 'presence'];

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('update_interval', 60);

        $this->RegisterPropertyInteger('var_presence', 0);
        $this->RegisterPropertyInteger('sonos_instance', 0);
        $this->RegisterPropertyInteger('humidity_instance', 0);

        $this->RegisterPropertyString('lights', '[]');
        $this->RegisterPropertyString('shutters', '[]');
        $this->RegisterPropertyString('sensors', '[]');
        // Any variable with a value profile that has associations (mode
        // selectors, status enums) -- covers Lueftung, Bewohner-Status, and
        // anything similar without needing a dedicated slot for each one.
        $this->RegisterPropertyString('statusVars', '[]');
        $this->RegisterPropertyString('thermostats', '[]');
        $this->RegisterPropertyString('automations', '[]');
        $this->RegisterPropertyString('smokeDetectors', '[]');
        $this->RegisterPropertyString('vacuums', '[]');

        $this->RegisterTimer('UpdateTimer', 0, 'RMD_Refresh($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $lights      = json_decode($this->ReadPropertyString('lights'), true) ?: [];
        $shutters    = json_decode($this->ReadPropertyString('shutters'), true) ?: [];
        $sensors     = json_decode($this->ReadPropertyString('sensors'), true) ?: [];
        $statusVars  = json_decode($this->ReadPropertyString('statusVars'), true) ?: [];
        $thermostats = json_decode($this->ReadPropertyString('thermostats'), true) ?: [];
        $automations = json_decode($this->ReadPropertyString('automations'), true) ?: [];
        $smokeDetectors = json_decode($this->ReadPropertyString('smokeDetectors'), true) ?: [];
        $vacuums = json_decode($this->ReadPropertyString('vacuums'), true) ?: [];

        $hasAnything = $this->ReadPropertyInteger('var_presence') > 0
            || $this->ReadPropertyInteger('sonos_instance') > 0
            || $this->ReadPropertyInteger('humidity_instance') > 0
            || count($lights) > 0 || count($shutters) > 0 || count($sensors) > 0
            || count($statusVars) > 0 || count($thermostats) > 0 || count($automations) > 0
            || count($smokeDetectors) > 0 || count($vacuums) > 0;

        if (!$hasAnything) {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);

        $this->Refresh();
    }

    // ─── HTML-SDK: dashboard tile ──────────────────────────────────────────────

    public function GetVisualizationTile(): string
    {
        return $this->buildDashboardHTML();
    }

    // ─── Public update ────────────────────────────────────────────────────────

    public function Refresh(): void
    {
        try {
            $this->pushFullState();
            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('RoomDashboard Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    /**
     * handleMessage() in the tile's JS only ever handles a push whose key is
     * '__all__' -- it patches every widget from one full state snapshot,
     * with no branch for anything else. Every forward*Action() below used to
     * push a narrow, ident-specific key/value instead (e.g.
     * "thermostat_0_mode"); those pushes were silently dropped on arrival,
     * so nothing ever updated in the open tile until the next periodic
     * Refresh() or a full reload -- exactly the "no feedback until reload"
     * symptom reported live for the thermostat mode buttons (14.09.2026).
     * Re-collecting and pushing the full state after every action is what
     * Refresh() already does on its own timer; doing the same right after a
     * write just makes that update immediate instead of waiting.
     */
    private function pushFullState(): void
    {
        $this->tkPush('__all__', $this->ops($this->view($this->collectData())));
    }

    // ─── IPS action handler ─────────────────────────────────────────────────────

    /**
     * This module owns none of the underlying variables. Every control just
     * forwards to whichever variable was configured for that widget/row via
     * the global RequestAction($VariableID, $Value) -- same pattern as every
     * other dashboard this session (Heating/Energy).
     */
    public function RequestAction($Ident, $Value): void
    {
        try {
            // TileKit-Schalter senden '<ident>_toggle'
            $Ident = (string) preg_replace('/_toggle$/', '', (string) $Ident);
            if (strpos($Ident, 'sonos_') === 0) {
                $this->forwardSonosAction(substr($Ident, strlen('sonos_')), $Value, $Ident);
                return;
            }

            if ($Ident === 'lights_all') {
                $this->forwardAllLightsAction((bool) $Value);
                return;
            }

            if (strpos($Ident, 'light_') === 0) {
                // light_{index}_{control}, e.g. "light_0_on", "light_0_brightness", "light_0_color"
                $rest = substr($Ident, strlen('light_'));
                $sep  = strpos($rest, '_');
                if ($sep !== false) {
                    $this->forwardLightAction((int) substr($rest, 0, $sep), substr($rest, $sep + 1), $Value, $Ident);
                }
                return;
            }

            if (strpos($Ident, 'shutter_') === 0) {
                // shutter_{index}_{control}, e.g. "shutter_0_position", "shutter_0_state"
                $rest = substr($Ident, strlen('shutter_'));
                $sep  = strpos($rest, '_');
                if ($sep !== false) {
                    $this->forwardShutterAction((int) substr($rest, 0, $sep), substr($rest, $sep + 1), $Value, $Ident);
                }
                return;
            }

            if (strpos($Ident, 'status_') === 0) {
                $this->forwardListAction('statusVars', (int) substr($Ident, strlen('status_')), $Value, $Ident);
                return;
            }

            if (strpos($Ident, 'thermostat_') === 0) {
                // thermostat_{index}_{control}, e.g. "thermostat_0_soll", "thermostat_0_mode"
                $rest = substr($Ident, strlen('thermostat_'));
                $sep  = strpos($rest, '_');
                if ($sep !== false) {
                    $this->forwardThermostatAction((int) substr($rest, 0, $sep), substr($rest, $sep + 1), $Value, $Ident);
                }
                return;
            }

            if (strpos($Ident, 'automation_') === 0) {
                $this->forwardListAction('automations', (int) substr($Ident, strlen('automation_')), $Value, $Ident);
                return;
            }

            if (strpos($Ident, 'vacuum_') === 0) {
                // vacuum_{index}_{control}, e.g. "vacuum_0_clean", "vacuum_0_fanspeed"
                $rest = substr($Ident, strlen('vacuum_'));
                $sep  = strpos($rest, '_');
                if ($sep !== false) {
                    $this->forwardVacuumAction((int) substr($rest, 0, $sep), substr($rest, $sep + 1), $Value);
                }
                return;
            }

            $this->LogMessage("RoomDashboard RequestAction: unknown ident {$Ident}", KL_WARNING);
        } catch (\Throwable $e) {
            $this->LogMessage('RoomDashboard RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    private function forwardSonosAction(string $key, $value, string $pushIdent): void
    {
        $sonosId = $this->ReadPropertyInteger('sonos_instance');
        if ($sonosId <= 0) {
            return;
        }

        if ($key === 'group') {
            $this->forwardSonosGroupAction($sonosId, (int) $value, $pushIdent);
            return;
        }

        $map = [
            'status' => 'Status', 'volume' => 'Volume', 'groupvolume' => 'GroupVolume',
            'mute' => 'Mute', 'playlist' => 'Playlist',
        ];
        if (!isset($map[$key])) {
            return;
        }
        $targetId = $this->varIdByIdent($sonosId, $map[$key]);
        if ($targetId <= 0) {
            return;
        }
        $cast = $this->castToVarType($targetId, $value);
        RequestAction($targetId, $cast);
        $this->pushFullState();
    }

    /**
     * The Sonos module's own SetGroup($coordinator) makes the instance you
     * call it ON join $coordinator as a satellite -- it stops playing its
     * own queue and inherits the coordinator's content instead. Writing to
     * this room's own MemberOfGroup therefore made THIS room follow the
     * other one, which is backwards from what a per-room dashboard wants:
     * this room should stay the coordinator and pull the other player in,
     * so its own content keeps playing everywhere. That means the write
     * has to target the OTHER instance's own MemberOfGroup, pointed at
     * this one -- never this instance's own.
     */
    private function forwardSonosGroupAction(int $sonosId, int $targetInstanceId, string $pushIdent): void
    {
        if ($targetInstanceId > 0) {
            $otherGroupVarId = $this->varIdByIdent($targetInstanceId, 'MemberOfGroup');
            if ($otherGroupVarId > 0) {
                RequestAction($otherGroupVarId, $sonosId);
            }
            $this->pushFullState();
            return;
        }

        // "keine" selected: release every known candidate player that might
        // currently be joined to us. We cannot read who actually is --
        // Sonos only exposes group membership on the joining side, not the
        // coordinator side -- so releasing all of them is the only reliable
        // way; it's a harmless no-op for anyone that wasn't grouped with us.
        foreach ($this->variableAssociations($this->varIdByIdent($sonosId, 'MemberOfGroup')) as $opt) {
            $otherId = (int) $opt['Value'];
            if ($otherId <= 0) {
                continue;
            }
            $otherGroupVarId = $this->varIdByIdent($otherId, 'MemberOfGroup');
            if ($otherGroupVarId > 0) {
                RequestAction($otherGroupVarId, 0);
            }
        }
        $this->pushFullState();
    }

    private function forwardListAction(string $listProp, int $index, $value, string $pushIdent, string $column = 'variable'): void
    {
        $rows = json_decode($this->ReadPropertyString($listProp), true) ?: [];
        if (!isset($rows[$index][$column])) {
            return;
        }
        $targetId = (int) $rows[$index][$column];
        if ($targetId <= 0) {
            return;
        }
        $cast = $this->castToVarType($targetId, $value);
        RequestAction($targetId, $cast);
        $this->pushFullState();
    }

    /**
     * Known "smart light" device-instance module types and the idents of
     * their on/brightness/color variables, so the lights list can accept a
     * top-level device node (Hue Light/Grouped Light instance, Govee
     * device instance) instead of forcing the user to pick the raw
     * variable, with the module figuring out which controls apply.
     */
    private const LIGHT_MODULE_IDENTS = [
        '{87FA14D1-0ACA-4CBD-BE83-BA4DF8831876}' => ['on', 'brightness', 'color'],       // HUE Light
        '{6324AC4A-330C-4CB2-9281-12EECB450024}' => ['on', 'brightness', 'color'],       // HUE Grouped Light
        '{BFF4858B-78B1-B4AD-B755-24AEC44EACFF}' => ['State', 'Brightness', 'Color'],    // Govee Device
    ];

    private function forwardLightAction(int $index, string $control, $value, string $pushIdent): void
    {
        $rows = json_decode($this->ReadPropertyString('lights'), true) ?: [];
        if (!isset($rows[$index]['variable'])) {
            return;
        }
        $nodeId = (int) $rows[$index]['variable'];
        if ($nodeId <= 0) {
            return;
        }

        $targetId = 0;
        if (@IPS_InstanceExists($nodeId)) {
            $moduleId = IPS_GetInstance($nodeId)['ModuleInfo']['ModuleID'] ?? '';
            $idents   = self::LIGHT_MODULE_IDENTS[$moduleId] ?? null;
            if ($idents !== null) {
                [$onIdent, $brightIdent, $colorIdent] = $idents;
                $identMap = ['on' => $onIdent, 'brightness' => $brightIdent, 'color' => $colorIdent];
                if (isset($identMap[$control])) {
                    $targetId = $this->varIdByIdent($nodeId, $identMap[$control]);
                }
            }
            // Unrecognised instance type (e.g. a Homematic channel selected
            // as the node instead of its raw variable): fall back to the
            // classic Homematic switch/dimmer idents.
            if ($targetId <= 0 && $control === 'on') {
                $targetId = $this->varIdByIdent($nodeId, 'STATE');
            }
            if ($targetId <= 0 && $control === 'brightness') {
                $targetId = $this->varIdByIdent($nodeId, 'LEVEL');
            }
        } elseif (@IPS_VariableExists($nodeId)) {
            // Plain switch/dimmer variable: on/off and brightness both act
            // on the same single variable (only one control is ever
            // actually rendered for it, based on its own type).
            $targetId = $nodeId;
        }

        if ($targetId <= 0) {
            return;
        }
        $cast = $this->castToVarType($targetId, $value);
        RequestAction($targetId, $cast);
        $this->pushFullState();
    }

    /**
     * Master switch for the whole Lichter section: turns every configured
     * light on/off regardless of kind or manufacturer. Dimmer-only lights
     * (no separate on/off control) are driven via brightness 0/100 instead.
     */
    private function forwardAllLightsAction(bool $on): void
    {
        $rows = json_decode($this->ReadPropertyString('lights'), true) ?: [];
        foreach ($rows as $i => $row) {
            $nodeId = (int) ($row['variable'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }
            $light = $this->buildLight($nodeId, 'light_' . $i, null);
            if ($light === null) {
                continue;
            }
            if ($light['kind'] === 'dimmer') {
                $this->forwardLightAction($i, 'brightness', $on ? 100 : 0, 'light_' . $i . '_brightness');
            } else {
                $this->forwardLightAction($i, 'on', $on, 'light_' . $i . '_on');
            }
        }
        $this->pushFullState();
    }

    /**
     * Homematic (classic and IP) datapoint idents for a heating thermostat
     * channel. Tried in order since the exact ident can differ between
     * device generations; the first one that resolves on the configured
     * node wins.
     */
    private const THERMOSTAT_SET_IDENTS    = ['SET_TEMPERATURE', 'SET_POINT_TEMPERATURE', 'SETPOINT'];
    private const THERMOSTAT_ACTUAL_IDENTS = ['ACTUAL_TEMPERATURE', 'TEMPERATURE'];
    private const THERMOSTAT_MODE_IDENTS   = ['CONTROL_MODE', 'SET_POINT_MODE'];
    private const THERMOSTAT_VALVE_IDENTS  = ['VALVE_STATE', 'LEVEL'];
    private const THERMOSTAT_RANGE         = [5.0, 30.0, 0.5];

    private function firstVarIdByIdent(int $instanceId, array $idents): int
    {
        foreach ($idents as $ident) {
            $id = $this->varIdByIdent($instanceId, $ident);
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }

    /** Whether IP-Symcon has an action handler registered for this variable -- if not, the global RequestAction() throws "No valid action available" rather than writing anything. */
    private function isActionable(int $varId): bool
    {
        $var = @IPS_GetVariable($varId);
        return $var !== false && (int) ($var['VariableAction'] ?? 0) > 0;
    }

    /**
     * Verified live against the Badezimmer thermostat (13.-14.09.2026): on
     * the CONFIGURED node itself, SET_TEMPERATURE has a working Default
     * Action ("Edit Object" shows it pointing back at the instance) but
     * CONTROL_MODE has none at all ("(None)"/"(None)") -- confirmed on the
     * wall thermostat "Badezimmer_Termostat:2" specifically, the actual
     * node this dashboard controls. An earlier attempt routed the mode
     * write to a sibling device instead (the separate radiator valve
     * "Badezimmer_Heizung:4") on the mistaken assumption that only the
     * valve could execute mode changes -- wrong: the thermostat is the
     * correct, sole place to both read and write climate control, and
     * switching devices produced a value mismatch (the valve isn't
     * necessarily in sync). Always resolves on the node itself now.
     *
     * HomeMatic leaving CONTROL_MODE non-actionable like this is normal;
     * the datapoint is still writable directly via the native
     * HM_WriteValueInteger()/-Boolean()/-Float()/-String() functions, which
     * write the raw CCU datapoint on the instance, bypassing IP-Symcon's
     * action system entirely (confirmed present on this installation via
     * the DataServer's "Override of native function HM_WriteValueInteger"
     * warning logged for an unrelated module).
     */
    private function resolveModeTarget(int $nodeId, array $idents): array
    {
        foreach ($idents as $ident) {
            $varId = $this->varIdByIdent($nodeId, $ident);
            if ($varId > 0) {
                return ['instanceId' => $nodeId, 'ident' => $ident, 'varId' => $varId];
            }
        }
        return ['instanceId' => 0, 'ident' => '', 'varId' => 0];
    }

    /**
     * Radiator valve open-percentage (VALVE_STATE) lives only on the
     * separate valve actuator device, not on the wall thermostat that this
     * dashboard otherwise reads/controls (e.g. "Badezimmer_Heizung:4" next
     * to the configured "Badezimmer_Termostat:2") -- read-only display
     * value, so unlike mode/soll it's fine to look across category
     * siblings for it instead of forcing a separate config field per room.
     */
    private function firstVarIdByIdentAcrossSiblings(int $nodeId, array $idents): int
    {
        $direct = $this->firstVarIdByIdent($nodeId, $idents);
        if ($direct > 0) {
            return $direct;
        }
        $parentId = @IPS_GetParent($nodeId);
        if ($parentId <= 0) {
            return 0;
        }
        foreach (@IPS_GetChildrenIDs($parentId) ?: [] as $siblingId) {
            if ($siblingId === $nodeId || !@IPS_InstanceExists($siblingId)) {
                continue;
            }
            $id = $this->firstVarIdByIdent($siblingId, $idents);
            if ($id > 0) {
                return $id;
            }
        }
        return 0;
    }

    /** Writes a HomeMatic datapoint directly for variables IP-Symcon never registered an action for. Tries HM_WritePara first (real CCU/RPC write), but that function throws "Instance does not implement this function" on a device-channel instance (it apparently belongs to a different instance type), so it must be wrapped -- an uncaught exception here would abort the whole RequestAction() call and make the WebFront button appear stuck. Falls back to the legacy HM_WriteValue* compatibility shims, which only update the local cached value without reaching the device. */
    private function writeHomeMaticValue(int $instanceId, string $ident, $castValue): bool
    {
        if (function_exists('HM_WritePara')) {
            try {
                return (bool) @HM_WritePara($instanceId, $ident, $castValue);
            } catch (\Throwable $e) {
                $this->LogMessage('RoomDashboard HM_WritePara fehlgeschlagen: ' . $e->getMessage(), KL_ERROR);
            }
        }
        if (is_bool($castValue) && function_exists('HM_WriteValueBoolean')) {
            return (bool) @HM_WriteValueBoolean($instanceId, $ident, $castValue);
        }
        if (is_int($castValue) && function_exists('HM_WriteValueInteger')) {
            return (bool) @HM_WriteValueInteger($instanceId, $ident, $castValue);
        }
        if (is_float($castValue) && function_exists('HM_WriteValueFloat')) {
            return (bool) @HM_WriteValueFloat($instanceId, $ident, $castValue);
        }
        if (is_string($castValue) && function_exists('HM_WriteValueString')) {
            return (bool) @HM_WriteValueString($instanceId, $ident, $castValue);
        }
        return false;
    }

    private function forwardThermostatAction(int $index, string $control, $value, string $pushIdent): void
    {
        $rows = json_decode($this->ReadPropertyString('thermostats'), true) ?: [];
        if (!isset($rows[$index]['node'])) {
            return;
        }
        $nodeId = (int) $rows[$index]['node'];
        if ($nodeId <= 0) {
            return;
        }

        if ($control === 'mode') {
            if (!@IPS_InstanceExists($nodeId)) {
                return;
            }
            $target = $this->resolveModeTarget($nodeId, self::THERMOSTAT_MODE_IDENTS);
            if ($target['varId'] <= 0) {
                return;
            }
            $cast       = $this->castToVarType($target['varId'], $value);
            $moduleGuid = @IPS_GetInstance($target['instanceId'])['ModuleInfo']['ModuleID'] ?? '?';
            if ($this->isActionable($target['varId'])) {
                RequestAction($target['varId'], $cast);
            } else {
                // Temporary diagnostic (remove once the real CCU write path is confirmed, 14.09.2026):
                // the instance chain shows 38092's ConnectionID is 57486 ("HomeMatic CCU Socket" --
                // the actual splitter), with Address 'MEQ0047915:2' on the channel instance itself.
                // HM_WritePara threw "Instance does not implement this function" on 38092 because it
                // belongs to the Socket module, not the CCU Device module -- try it on the splitter
                // instead, with a few plausible argument shapes since reflection on these
                // dynamically-registered functions doesn't reliably reveal the true signature.
                $address = (string) (@IPS_GetProperty($target['instanceId'], 'Address') ?: '');
                $splitterId = 0;
                $walkId = $target['instanceId'];
                for ($i = 0; $i < 5 && $walkId > 0; $i++) {
                    $inst = @IPS_GetInstance($walkId);
                    if ($inst === false) {
                        break;
                    }
                    if (stripos($inst['ModuleInfo']['ModuleName'], 'Socket') !== false || stripos($inst['ModuleInfo']['ModuleName'], 'Splitter') !== false) {
                        $splitterId = $walkId;
                        break;
                    }
                    $walkId = (int) $inst['ConnectionID'];
                }
                $this->LogMessage("RoomDashboard Splitter gefunden: {$splitterId}, Address der Kanal-Instanz: '{$address}'", KL_MESSAGE);

                $ok = false;
                if ($splitterId > 0 && $address !== '' && function_exists('HM_WritePara')) {
                    $attempts = [
                        'splitter+dotted-address' => fn () => HM_WritePara($splitterId, $address . '.' . $target['ident'], $cast),
                        'splitter+address+ident'  => fn () => HM_WritePara($splitterId, $address, $target['ident'], $cast),
                        'channel+ident'           => fn () => HM_WritePara($target['instanceId'], $target['ident'], $cast),
                    ];
                    foreach ($attempts as $label => $attempt) {
                        try {
                            $result = $attempt();
                            $this->LogMessage("RoomDashboard HM_WritePara Versuch '{$label}' erfolgreich, Rückgabe: " . var_export($result, true), KL_MESSAGE);
                            $ok = true;
                            break;
                        } catch (\Throwable $e) {
                            $this->LogMessage("RoomDashboard HM_WritePara Versuch '{$label}' fehlgeschlagen: " . $e->getMessage(), KL_ERROR);
                        }
                    }
                }

                if (!$ok) {
                    $ok = $this->writeHomeMaticValue($target['instanceId'], $target['ident'], $cast);
                }
                $readback = @GetValue($target['varId']);
                $this->LogMessage(
                    "RoomDashboard mode write: instance {$target['instanceId']} (Modul {$moduleGuid}), Ident '{$target['ident']}', "
                    . 'gesendet ' . var_export($cast, true) . ', Schreibfunktion meldet ' . ($ok ? 'true' : 'false')
                    . ', IPS-Wert danach ' . var_export($readback, true),
                    KL_MESSAGE
                );
                if (!$ok) {
                    return;
                }
            }
            $this->pushFullState();
            return;
        }

        $targetId = 0;
        if (@IPS_InstanceExists($nodeId) && $control === 'soll') {
            $targetId = $this->firstVarIdByIdent($nodeId, self::THERMOSTAT_SET_IDENTS);
        } elseif (@IPS_VariableExists($nodeId) && $control === 'soll') {
            $targetId = $nodeId;
        }

        if ($targetId <= 0) {
            return;
        }
        $cast = $this->castToVarType($targetId, $value);
        // Temporary diagnostic (remove once confirmed working, 14.09.2026): user reports a
        // direct edit of this same IPS variable reaches the CCU3 immediately, but a change
        // via this dashboard's dial does not -- logging the raw incoming value, the resolved
        // target, what's actually sent to RequestAction(), and an immediate readback narrows
        // down whether the dial is sending a wrong/stale value or RequestAction() itself
        // behaves differently when called from here.
        $before = @GetValue($targetId);
        RequestAction($targetId, $cast);
        $after = @GetValue($targetId);
        $this->LogMessage(
            "RoomDashboard soll write: instance {$nodeId}, target variable {$targetId}, "
            . 'roher Wert vom Dial ' . var_export($value, true) . ', gesendet ' . var_export($cast, true)
            . ', Wert davor ' . var_export($before, true) . ', Wert danach ' . var_export($after, true),
            KL_MESSAGE
        );
        $this->pushFullState();
    }

    /**
     * Saugroboter: forwards straight to the Ecovacs instance's own control
     * functions (ECOV_*) rather than RequestAction() on a variable -- start/
     * pause/stop/dock/fan speed aren't simple variable writes.
     */
    private function forwardVacuumAction(int $index, string $control, $value): void
    {
        $rows = json_decode($this->ReadPropertyString('vacuums'), true) ?: [];
        if (!isset($rows[$index]['variable'])) {
            return;
        }
        $nodeId = (int) $rows[$index]['variable'];
        if ($nodeId <= 0 || !@IPS_InstanceExists($nodeId)) {
            return;
        }

        switch ($control) {
            case 'clean':
                ECOV_Clean($nodeId);
                break;
            case 'pause':
                ECOV_Pause($nodeId);
                break;
            case 'stop':
                ECOV_Stop($nodeId);
                break;
            case 'dock':
                ECOV_Dock($nodeId);
                break;
            case 'fanspeed':
                ECOV_SetFanSpeed($nodeId, (int) $value);
                break;
        }
    }

    /**
     * TaHoma (Somfy) shutter idents: bidirectional covers expose a 0-100
     * position (core_TargetClosureState, or core_ClosureState as a
     * fallback for devices like Velux that don't report the target); RTS
     * (unidirectional) covers have no position feedback at all and only
     * support core_OpenClosedState with 'open'/'stop'/'closed'.
     */
    private const SHUTTER_POSITION_IDENTS = ['core_TargetClosureState', 'core_ClosureState'];
    private const SHUTTER_STATE_IDENT     = 'core_OpenClosedState';
    private const SHUTTER_MODULE_TAHOMA   = '{C3F89070-FE4D-A30A-C81F-B28131B32990}';

    private function forwardShutterAction(int $index, string $control, $value, string $pushIdent): void
    {
        $rows = json_decode($this->ReadPropertyString('shutters'), true) ?: [];
        if (!isset($rows[$index]['variable'])) {
            return;
        }
        $nodeId = (int) $rows[$index]['variable'];
        if ($nodeId <= 0) {
            return;
        }

        $targetId = 0;
        if (@IPS_InstanceExists($nodeId)) {
            if ($control === 'position') {
                $targetId = $this->firstVarIdByIdent($nodeId, self::SHUTTER_POSITION_IDENTS);
            } elseif ($control === 'state') {
                $targetId = $this->varIdByIdent($nodeId, self::SHUTTER_STATE_IDENT);
            }
        } elseif (@IPS_VariableExists($nodeId) && $control === 'position') {
            $targetId = $nodeId;
        }

        if ($targetId <= 0) {
            return;
        }
        $cast = $this->castToVarType($targetId, $value);
        RequestAction($targetId, $cast);
        $this->pushFullState();
    }

    /** Coerces a value coming from the browser (bool/string/number) to match the target variable's own type. */
    private function castToVarType(int $varId, $value)
    {
        $type = @IPS_GetVariable($varId)['VariableType'] ?? 1;
        switch ($type) {
            case 0: // Boolean
                return is_string($value) ? in_array(strtolower($value), ['1', 'true', 'on'], true) : (bool) $value;
            case 1: // Integer
                return (int) $value;
            case 2: // Float
                return (float) $value;
            default: // String
                return (string) $value;
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function readVarById(int $id)
    {
        if ($id <= 0 || !@IPS_VariableExists($id)) {
            return null;
        }
        return GetValue($id);
    }

    private function fmtNum($v, int $decimals = 0): string
    {
        if ($v === null) {
            return '–';
        }
        return number_format((float) $v, $decimals, ',', '.');
    }

    /** Value profile name (custom or standard system profile) attached to a variable, or ''. */
    private function variableProfile(int $id): string
    {
        if ($id <= 0 || !@IPS_VariableExists($id)) {
            return '';
        }
        $var = IPS_GetVariable($id);
        return $var['VariableCustomProfile'] ?: $var['VariableProfile'];
    }

    /** [{Value, Name}, ...] from a variable's profile, or [] if it has none. */
    private function variableAssociations(int $id): array
    {
        $profile = $this->variableProfile($id);
        if ($profile === '' || !@IPS_VariableProfileExists($profile)) {
            return [];
        }
        $assoc = IPS_GetVariableProfile($profile)['Associations'] ?? [];
        return array_map(static fn (array $a) => ['Value' => $a['Value'], 'Name' => $a['Name']], $assoc);
    }

    /** Resolves an instance's own variable ID by ident (Sonos Status/Volume/Playlist/..., humidity calculator Hint/Result/..., etc). */
    private function varIdByIdent(int $instanceId, string $ident): int
    {
        if ($instanceId <= 0) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $instanceId);
        return $id ?: 0;
    }

    private const GENERIC_NAMES = [
        'state', 'level', 'zustand', 'wert', 'status', 'value', 'variable',
        'unbenannt', 'unnamed', 'neues objekt', 'new object',
    ];

    private function isGenericName(string $name): bool
    {
        return $name === '' || in_array(mb_strtolower(trim($name)), self::GENERIC_NAMES, true);
    }

    /**
     * The name a user would actually recognise, with no manual entry
     * required: the variable's own object name if it's descriptive, or --
     * since many integrations (Homematic in particular) name the leaf
     * variable itself something generic like "STATE" while the actual
     * device carries the real name -- the nearest ancestor with a
     * non-generic name instead.
     */
    private function deviceName(int $varId): string
    {
        if ($varId <= 0) {
            return '';
        }
        $name = IPS_GetName($varId);
        if (!$this->isGenericName($name)) {
            return $name;
        }
        $parentId = IPS_GetParent($varId);
        for ($depth = 0; $parentId > 0 && $depth < 4; $depth++) {
            $parentName = IPS_GetName($parentId);
            if (!$this->isGenericName($parentName)) {
                return $parentName;
            }
            $parentId = IPS_GetParent($parentId);
        }
        return $name;
    }

    /** The room's own name: whichever category this instance is filed under, so no manual room-name entry is needed either. */
    private function roomName(): string
    {
        $parentId = IPS_GetParent($this->InstanceID);
        if ($parentId > 0) {
            $parentName = IPS_GetName($parentId);
            if ($parentName !== '') {
                return $parentName;
            }
        }
        return IPS_GetName($this->InstanceID);
    }

    private function collectData(): array
    {
        $presenceId = $this->ReadPropertyInteger('var_presence');
        $presence   = $presenceId > 0 ? (bool) $this->readVarById($presenceId) : null;

        $sonos       = $this->collectSonos();
        $lights      = $this->collectLights();
        $shutters    = $this->collectShutters();
        $sensors     = $this->collectSensors();
        $statusVars  = $this->collectStatusVars();
        $thermostats = $this->collectThermostats();
        $humidity    = $this->collectHumidity();
        $automations = $this->collectAutomations();
        $smokeDetectors = $this->collectSmokeDetectors();
        $vacuums = $this->collectVacuums();

        return [
            'roomName'       => $this->roomName(),
            'presence'       => $presence,
            'sonos'          => $sonos,
            'lights'         => $lights,
            'shutters'       => $shutters,
            'sensors'        => $sensors,
            'statusVars'     => $statusVars,
            'thermostats'    => $thermostats,
            'humidity'       => $humidity,
            'automations'    => $automations,
            'smokeDetectors' => $smokeDetectors,
            'vacuums'        => $vacuums,
            'updated'        => date('d.m. H:i'),
        ];
    }

    private function collectSonos(): ?array
    {
        $sonosId = $this->ReadPropertyInteger('sonos_instance');
        if ($sonosId <= 0 || !@IPS_InstanceExists($sonosId)) {
            return null;
        }

        $statusId      = $this->varIdByIdent($sonosId, 'Status');
        $volumeId      = $this->varIdByIdent($sonosId, 'Volume');
        $muteId        = $this->varIdByIdent($sonosId, 'Mute');
        $playlistId    = $this->varIdByIdent($sonosId, 'Playlist');
        $groupId       = $this->varIdByIdent($sonosId, 'MemberOfGroup');
        $groupVolumeId = $this->varIdByIdent($sonosId, 'GroupVolume');
        $artistId      = $this->varIdByIdent($sonosId, 'Artist');
        $titleId       = $this->varIdByIdent($sonosId, 'Title');
        $albumId       = $this->varIdByIdent($sonosId, 'Album');
        $coverId       = $this->varIdByIdent($sonosId, 'CoverURL');
        $nowPlayingId  = $this->varIdByIdent($sonosId, 'nowPlaying');

        // Mirrors the Sonos module's own logic: once a player has joined a
        // group (MemberOfGroup != 0), volume control shifts to GroupVolume.
        $isGrouped = $groupId > 0 && (int) $this->readVarById($groupId) !== 0;

        // "nowPlaying" is always present regardless of the DetailedInformation
        // instance property, unlike Artist/Title/Album/CoverURL which only
        // exist when that property is enabled. Use it as the guaranteed
        // fallback for the track display; the discrete fields (when present)
        // give a nicer split of title vs. artist.
        $nowPlaying = $nowPlayingId > 0 ? (string) $this->readVarById($nowPlayingId) : '';
        $title      = $titleId > 0 ? (string) $this->readVarById($titleId) : '';
        $artist     = $artistId > 0 ? (string) $this->readVarById($artistId) : '';
        if ($title === '' && $nowPlaying !== '') {
            $parts  = explode(' | ', $nowPlaying, 2);
            $title  = $parts[0];
            $artist = $artist !== '' ? $artist : ($parts[1] ?? '');
        }

        return [
            'status'         => $statusId > 0 ? (int) $this->readVarById($statusId) : null,
            'volume'         => $volumeId > 0 ? (int) $this->readVarById($volumeId) : null,
            'mute'           => $muteId > 0 ? (bool) $this->readVarById($muteId) : null,
            'playlist'       => $playlistId > 0 ? (string) $this->readVarById($playlistId) : null,
            'playlistOptions' => $playlistId > 0 ? $this->variableAssociations($playlistId) : [],
            'group'          => $groupId > 0 ? (string) $this->readVarById($groupId) : null,
            'groupOptions'   => $groupId > 0 ? $this->variableAssociations($groupId) : [],
            'groupVolume'    => $groupVolumeId > 0 ? (int) $this->readVarById($groupVolumeId) : null,
            'isGrouped'      => $isGrouped,
            'nowPlaying'     => $nowPlaying,
            'artist'         => $artist,
            'title'          => $title,
            'album'          => $albumId > 0 ? (string) $this->readVarById($albumId) : '',
            'cover'          => $coverId > 0 ? (string) $this->readVarById($coverId) : '',
        ];
    }

    private function collectLights(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('lights'), true) ?: [] as $i => $row) {
            $nodeId = (int) ($row['variable'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }
            $nameOverride = ($row['name'] ?? '') !== '' ? $row['name'] : null;
            $light = $this->buildLight($nodeId, 'light_' . $i, $nameOverride);
            if ($light !== null) {
                $out[] = $light;
            }
        }
        return $out;
    }

    /**
     * Classifies a configured lights-list node into a uniform shape the
     * dashboard can render the same way regardless of manufacturer: a
     * top-level Hue/Govee device instance is recognised via its module
     * GUID and its on/brightness/color child variables located by ident;
     * a plain switch or dimmer variable (e.g. Homematic) is used directly.
     */
    private function buildLight(int $nodeId, string $ident, ?string $nameOverride): ?array
    {
        if (@IPS_InstanceExists($nodeId)) {
            $moduleId = IPS_GetInstance($nodeId)['ModuleInfo']['ModuleID'] ?? '';
            $idents   = self::LIGHT_MODULE_IDENTS[$moduleId] ?? null;
            if ($idents !== null) {
                [$onIdent, $brightIdent, $colorIdent] = $idents;
                $onId     = $this->varIdByIdent($nodeId, $onIdent);
                $brightId = $this->varIdByIdent($nodeId, $brightIdent);
                $colorId  = $this->varIdByIdent($nodeId, $colorIdent);
                if ($onId > 0) {
                    return [
                        'ident'      => $ident,
                        'name'       => $nameOverride ?? $this->deviceName($nodeId),
                        'kind'       => $colorId > 0 ? 'color' : ($brightId > 0 ? 'dimmer' : 'switch'),
                        'on'         => (bool) $this->readVarById($onId),
                        'brightness' => $brightId > 0 ? (float) $this->readVarById($brightId) : null,
                        'color'      => $colorId > 0 ? (int) $this->readVarById($colorId) : null,
                    ];
                }
            }

            // Unrecognised instance type (e.g. a Homematic channel selected
            // as the node instead of its raw variable): fall back to the
            // classic Homematic switch/dimmer idents, same as the light
            // manufacturer classifiers above.
            $switchId = $this->varIdByIdent($nodeId, 'STATE');
            if ($switchId > 0) {
                return $this->buildLight($switchId, $ident, $nameOverride);
            }
            $levelId = $this->varIdByIdent($nodeId, 'LEVEL');
            if ($levelId > 0) {
                return $this->buildLight($levelId, $ident, $nameOverride);
            }
            return null;
        }

        if (@IPS_VariableExists($nodeId)) {
            $isBool = (int) IPS_GetVariable($nodeId)['VariableType'] === 0;
            $raw    = GetValue($nodeId);
            return [
                'ident'      => $ident,
                'name'       => $nameOverride ?? $this->deviceName($nodeId),
                'kind'       => $isBool ? 'switch' : 'dimmer',
                'on'         => $isBool ? (bool) $raw : ((float) $raw > 0),
                'brightness' => $isBool ? null : (float) $raw,
                'color'      => null,
            ];
        }

        return null;
    }

    private function collectShutters(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('shutters'), true) ?: [] as $i => $row) {
            $nodeId = (int) ($row['variable'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }
            $nameOverride = ($row['name'] ?? '') !== '' ? $row['name'] : null;
            $shutter = $this->buildShutter($nodeId, 'shutter_' . $i, $nameOverride);
            if ($shutter !== null) {
                $out[] = $shutter;
            }
        }
        return $out;
    }

    /**
     * Classifies a configured shutters-list node: a TaHoma device instance
     * is either bidirectional (has a position ident -- percentage control)
     * or unidirectional/RTS (only core_OpenClosedState -- open/stop/close
     * only, no feedback); a plain 0-100 variable (e.g. Homematic actuator)
     * is treated as bidirectional directly.
     */
    private function buildShutter(int $nodeId, string $ident, ?string $nameOverride): ?array
    {
        if (@IPS_InstanceExists($nodeId)) {
            $moduleId = IPS_GetInstance($nodeId)['ModuleInfo']['ModuleID'] ?? '';
            if ($moduleId !== self::SHUTTER_MODULE_TAHOMA) {
                return null;
            }
            $posId = $this->firstVarIdByIdent($nodeId, self::SHUTTER_POSITION_IDENTS);
            if ($posId > 0) {
                return [
                    'ident' => $ident,
                    'name'  => $nameOverride ?? $this->deviceName($nodeId),
                    'kind'  => 'position',
                    'value' => (float) $this->readVarById($posId),
                ];
            }
            $stateId = $this->varIdByIdent($nodeId, self::SHUTTER_STATE_IDENT);
            if ($stateId > 0) {
                return [
                    'ident' => $ident,
                    'name'  => $nameOverride ?? $this->deviceName($nodeId),
                    'kind'  => 'updown',
                    'state' => (string) $this->readVarById($stateId),
                ];
            }
            return null;
        }

        if (@IPS_VariableExists($nodeId)) {
            return [
                'ident' => $ident,
                'name'  => $nameOverride ?? $this->deviceName($nodeId),
                'kind'  => 'position',
                'value' => (float) GetValue($nodeId),
            ];
        }

        return null;
    }

    private function collectSensors(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('sensors'), true) ?: [] as $row) {
            $varId = (int) ($row['variable'] ?? 0);
            if ($varId <= 0 || !@IPS_VariableExists($varId)) {
                continue;
            }
            $type = $row['type'] ?? 'generic';
            $raw  = GetValue($varId);
            $out[] = [
                'name'  => ($row['name'] ?? '') !== '' ? $row['name'] : $this->deviceName($varId),
                'type'  => $type,
                'bool'  => in_array($type, self::SENSOR_BOOL_TYPES, true) ? (bool) $raw : null,
                'value' => in_array($type, self::SENSOR_BOOL_TYPES, true) ? null : (float) $raw,
            ];
        }
        return $out;
    }

    private function collectStatusVars(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('statusVars'), true) ?: [] as $i => $row) {
            $varId = (int) ($row['variable'] ?? 0);
            if ($varId <= 0 || !@IPS_VariableExists($varId)) {
                continue;
            }
            $out[] = [
                'ident'   => 'status_' . $i,
                'name'    => ($row['name'] ?? '') !== '' ? $row['name'] : $this->deviceName($varId),
                'value'   => (string) GetValue($varId),
                'options' => $this->variableAssociations($varId),
            ];
        }
        return $out;
    }

    /**
     * Arbitrary variables to switch/toggle per room -- e.g. a system
     * variable that enables/disables a light automation. A plain Boolean
     * with no value profile renders as a toggle switch; anything with
     * profile associations reuses the same button-group as status
     * variables; anything else falls back to a read-only value.
     */
    private function collectAutomations(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('automations'), true) ?: [] as $i => $row) {
            $varId = (int) ($row['variable'] ?? 0);
            if ($varId <= 0 || !@IPS_VariableExists($varId)) {
                continue;
            }
            $options = $this->variableAssociations($varId);
            $isBool  = (int) IPS_GetVariable($varId)['VariableType'] === 0;
            $out[] = [
                'ident'   => 'automation_' . $i,
                'name'    => ($row['name'] ?? '') !== '' ? $row['name'] : $this->deviceName($varId),
                'isBool'  => $isBool && count($options) === 0,
                'on'      => $isBool ? (bool) GetValue($varId) : null,
                'value'   => (string) GetValue($varId),
                'options' => $options,
            ];
        }
        return $out;
    }

    /**
     * Rauchmelder: either a Nest Protect instance (own module, GUID below --
     * read-only Smoke/CO/Heat/Battery/Wired/Hushed/LastTest/ReplaceBy
     * variables) or a Homematic smoke-detector channel (verified live:
     * ident SMOKE_DETECTOR_ALARM_STATUS, Integer, 0 = OK / != 0 = alarm),
     * or a plain variable pointed at directly.
     */
    private const NEST_PROTECT_MODULE  = '{8A2725A7-390A-4F9A-A9E9-A18CA10E537D}';
    private const SMOKE_ALARM_IDENTS   = ['SMOKE_DETECTOR_ALARM_STATUS', 'STATE'];

    private function collectSmokeDetectors(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('smokeDetectors'), true) ?: [] as $i => $row) {
            $nodeId = (int) ($row['variable'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }
            $nameOverride = ($row['name'] ?? '') !== '' ? $row['name'] : null;
            $detector = $this->buildSmokeDetector($nodeId, 'smoke_' . $i, $nameOverride);
            if ($detector !== null) {
                $out[] = $detector;
            }
        }
        return $out;
    }

    private function buildSmokeDetector(int $nodeId, string $ident, ?string $nameOverride): ?array
    {
        if (@IPS_InstanceExists($nodeId)) {
            $moduleId = IPS_GetInstance($nodeId)['ModuleInfo']['ModuleID'] ?? '';
            if ($moduleId === self::NEST_PROTECT_MODULE) {
                $smokeId = $this->varIdByIdent($nodeId, 'Smoke');
                if ($smokeId <= 0) {
                    return null;
                }
                $coId       = $this->varIdByIdent($nodeId, 'CO');
                $heatId     = $this->varIdByIdent($nodeId, 'Heat');
                $batteryId  = $this->varIdByIdent($nodeId, 'Battery');
                $wiredId    = $this->varIdByIdent($nodeId, 'Wired');
                $hushedId   = $this->varIdByIdent($nodeId, 'Hushed');
                $lastTestId = $this->varIdByIdent($nodeId, 'LastTest');
                $replaceId  = $this->varIdByIdent($nodeId, 'ReplaceBy');
                return [
                    'ident'     => $ident,
                    'name'      => $nameOverride ?? $this->deviceName($nodeId),
                    'kind'      => 'nest',
                    'smoke'     => (bool) $this->readVarById($smokeId),
                    'co'        => $coId > 0 ? (bool) $this->readVarById($coId) : null,
                    'heat'      => $heatId > 0 ? (bool) $this->readVarById($heatId) : null,
                    'battery'   => $batteryId > 0 ? (int) $this->readVarById($batteryId) : null,
                    'wired'     => $wiredId > 0 ? (bool) $this->readVarById($wiredId) : null,
                    'hushed'    => $hushedId > 0 ? (bool) $this->readVarById($hushedId) : null,
                    'lastTest'  => $lastTestId > 0 ? (int) $this->readVarById($lastTestId) : null,
                    'replaceBy' => $replaceId > 0 ? (int) $this->readVarById($replaceId) : null,
                ];
            }

            // Unrecognised instance (e.g. a Homematic smoke-detector channel
            // selected as the node): fall back to the classic alarm-status ident.
            $alarmId = $this->firstVarIdByIdent($nodeId, self::SMOKE_ALARM_IDENTS);
            if ($alarmId <= 0) {
                return null;
            }
            return [
                'ident' => $ident,
                'name'  => $nameOverride ?? $this->deviceName($nodeId),
                'kind'  => 'generic',
                'alarm' => (int) $this->readVarById($alarmId) !== 0,
            ];
        }

        if (@IPS_VariableExists($nodeId)) {
            $isBool = (int) IPS_GetVariable($nodeId)['VariableType'] === 0;
            $raw    = $this->readVarById($nodeId);
            return [
                'ident' => $ident,
                'name'  => $nameOverride ?? $this->deviceName($nodeId),
                'kind'  => 'generic',
                'alarm' => $isBool ? (bool) $raw : ((int) $raw !== 0),
            ];
        }

        return null;
    }

    /** Saugroboter: an [[project_ecovacs]] "Ecovacs Saugroboter" instance -- Battery/Charging/State/FanSpeed/LastClean variables, read the same way as every other device-instance list here. */
    private const ECOVACS_VACUUM_MODULE = '{EB763124-CE12-4CA8-943D-63BC93C61281}';

    private function collectVacuums(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('vacuums'), true) ?: [] as $i => $row) {
            $nodeId = (int) ($row['variable'] ?? 0);
            if ($nodeId <= 0 || !@IPS_InstanceExists($nodeId)) {
                continue;
            }
            $moduleId = IPS_GetInstance($nodeId)['ModuleInfo']['ModuleID'] ?? '';
            if ($moduleId !== self::ECOVACS_VACUUM_MODULE) {
                continue;
            }

            $stateId = $this->varIdByIdent($nodeId, 'State');
            if ($stateId <= 0) {
                continue;
            }
            $batteryId  = $this->varIdByIdent($nodeId, 'Battery');
            $chargingId = $this->varIdByIdent($nodeId, 'Charging');
            $fanSpeedId = $this->varIdByIdent($nodeId, 'FanSpeed');
            $lastCleanId = $this->varIdByIdent($nodeId, 'LastClean');

            $nameOverride = ($row['name'] ?? '') !== '' ? $row['name'] : null;
            $out[] = [
                'ident'     => 'vacuum_' . $i,
                'name'      => $nameOverride ?? $this->deviceName($nodeId),
                'battery'   => $batteryId > 0 ? (int) $this->readVarById($batteryId) : 0,
                'charging'  => $chargingId > 0 ? (bool) $this->readVarById($chargingId) : false,
                'state'     => (string) $this->readVarById($stateId),
                'fanSpeed'  => $fanSpeedId > 0 ? (int) $this->readVarById($fanSpeedId) : 0,
                'lastClean' => $lastCleanId > 0 ? (string) $this->readVarById($lastCleanId) : '',
            ];
        }
        return $out;
    }

    private function collectThermostats(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('thermostats'), true) ?: [] as $i => $row) {
            $nodeId = (int) ($row['node'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }

            $sollId = $istId = $modeId = $valveId = 0;
            if (@IPS_InstanceExists($nodeId)) {
                $sollId  = $this->firstVarIdByIdent($nodeId, self::THERMOSTAT_SET_IDENTS);
                $istId   = $this->firstVarIdByIdent($nodeId, self::THERMOSTAT_ACTUAL_IDENTS);
                $modeId  = $this->firstVarIdByIdent($nodeId, self::THERMOSTAT_MODE_IDENTS);
                // Only the separate valve actuator has this, not every wall
                // thermostat -- e.g. Badezimmer_Heizung:4 next to the
                // configured Badezimmer_Termostat:2. Purely informational.
                $valveId = $this->firstVarIdByIdentAcrossSiblings($nodeId, self::THERMOSTAT_VALVE_IDENTS);
            } elseif (@IPS_VariableExists($nodeId)) {
                $sollId = $nodeId;
            }
            if ($sollId <= 0 && $istId <= 0) {
                continue;
            }

            $out[] = [
                'ident'       => 'thermostat_' . $i,
                'name'        => ($row['name'] ?? '') !== '' ? $row['name'] : $this->deviceName($nodeId),
                'soll'        => $sollId > 0 ? (float) $this->readVarById($sollId) : null,
                'ist'         => $istId > 0 ? (float) $this->readVarById($istId) : null,
                'mode'        => $modeId > 0 ? (string) $this->readVarById($modeId) : null,
                'modeOptions' => $modeId > 0 ? $this->variableAssociations($modeId) : [],
                'valve'       => $valveId > 0 ? (float) $this->readVarById($valveId) : null,
            ];
        }
        return $out;
    }

    private function collectHumidity(): ?array
    {
        $humId   = $this->ReadPropertyInteger('humidity_instance');
        $hasCalc = $humId > 0 && @IPS_InstanceExists($humId);

        // The room's own humidity sensor (from the generic sensors list)
        // belongs here rather than nested inside the thermostat tile -- a
        // room only ever has the one ambient reading regardless of how
        // many thermostats it has.
        $sensorHumidity = null;
        foreach ($this->collectSensors() as $sensor) {
            if ($sensor['type'] === 'humidity') {
                $sensorHumidity = $sensor['value'];
                break;
            }
        }

        if (!$hasCalc && $sensorHumidity === null) {
            return null;
        }

        $hintId = $resultId = $dpOutId = $dpInId = 0;
        if ($hasCalc) {
            $hintId   = $this->varIdByIdent($humId, 'Hint');
            $resultId = $this->varIdByIdent($humId, 'Result');
            $dpOutId  = $this->varIdByIdent($humId, 'DewPointOutdoor');
            $dpInId   = $this->varIdByIdent($humId, 'DewPointIndoor');
        }

        return [
            'hasCalc'     => $hasCalc,
            'hint'        => $hintId > 0 ? (bool) $this->readVarById($hintId) : null,
            'result'      => $resultId > 0 ? (string) $this->readVarById($resultId) : '',
            'dewPointOut' => $dpOutId > 0 ? (float) $this->readVarById($dpOutId) : null,
            'dewPointIn'  => $dpInId > 0 ? (float) $this->readVarById($dpInId) : null,
            'sensor'      => $sensorHumidity,
        ];
    }

    // ─── Rendering (TileKit, siehe docs/ui-kachel.md im Workspace) ─────────────
    //
    // view() beschreibt den Raum als Gruppen von Kacheln. Daraus entstehen
    // sowohl das HTML (renderTile) als auch die Live-Updates (ops) – dieselbe
    // Quelle, damit Erstrender und Push nie auseinanderlaufen.

    private const SENSOR_VIEW = [
        // Typ => [Icon aus, Icon an, Text aus, Text an, Zustand bei "an"]
        'window'   => ['🔒', '🔓', 'Zu', 'Offen', 'warn'],
        'door'     => ['🔒', '🔓', 'Zu', 'Offen', 'warn'],
        'smoke'    => ['✅', '🔥', 'Ruhe', 'Alarm', 'alarm'],
        'siren'    => ['🔕', '🚨', 'Ruhe', 'Alarm', 'alarm'],
        'presence' => ['🚶', '🏠', 'Abwesend', 'Anwesend', 'ok'],
    ];

    private const FAN_SPEEDS = [1000 => 'Leise', 0 => 'Normal', 1 => 'Stark', 2 => 'Max+'];

    private function view(array $d): array
    {
        $groups = [];
        $add    = static function (string $name, array $tiles) use (&$groups): void {
            if (count($tiles) > 0) {
                $groups[] = ['name' => $name, 'tiles' => $tiles];
            }
        };

        // Klima: Thermostate + Luftfeuchte/Lueften
        $klima = [];
        foreach ($d['thermostats'] as $t) {
            $klima[] = ['kind' => 'thermo', 'id' => $t['ident'], 'name' => count($d['thermostats']) > 1 ? $t['name'] : '',
                'soll' => $t['soll'], 'ist' => $t['ist'] !== null ? $this->fmtNum($t['ist'], 1) . ' °C' : null,
                'valve' => $t['valve'] !== null ? (int) round(max(0, min(100, $t['valve']))) : null,
                'mode' => $t['mode'], 'options' => $t['modeOptions']];
        }
        $h = $d['humidity'];
        if ($h !== null) {
            if ($h['sensor'] !== null) {
                $klima[] = ['kind' => 'value', 'id' => 'hum_sensor', 'name' => 'Luftfeuchte', 'text' => $this->fmtNum($h['sensor'], 1) . ' %'];
            }
            if ($h['hasCalc'] && ($h['dewPointOut'] !== null || $h['dewPointIn'] !== null)) {
                $klima[] = ['kind' => 'value', 'id' => 'hum_dp_out', 'name' => 'Taupunkt außen', 'text' => $this->fmtNum($h['dewPointOut'], 1) . ' °C'];
                $klima[] = ['kind' => 'value', 'id' => 'hum_dp_in', 'name' => 'Taupunkt innen', 'text' => $this->fmtNum($h['dewPointIn'], 1) . ' °C'];
            }
            if ($h['hasCalc'] && $h['hint'] !== null) {
                $klima[] = ['kind' => 'status', 'id' => 'hum_hint', 'name' => 'Lüften', 'text' => $h['hint'] ? 'Empfohlen' : 'Nicht nötig',
                    'icon' => $h['hint'] ? '🌬️' : '✅', 'state' => $h['hint'] ? 'warn' : '', 'sub' => $h['result'], 'cls' => $h['result'] !== '' ? 'tk-span2' : ''];
            }
        }
        $add('Klima', $klima);

        // Status: Praesenz + Status-Variablen
        $status = [];
        if ($d['presence'] !== null) {
            $status[] = ['kind' => 'status', 'id' => 'presence', 'name' => 'Präsenz', 'text' => $d['presence'] ? 'Anwesend' : 'Abwesend',
                'icon' => $d['presence'] ? '🏠' : '🚶', 'state' => $d['presence'] ? 'ok' : ''];
        }
        foreach ($d['statusVars'] as $row) {
            $status[] = $this->selectTile($row);
        }
        $add('Status', $status);

        $auto = [];
        foreach ($d['automations'] as $a) {
            $auto[] = $a['isBool']
                ? ['kind' => 'toggle', 'id' => $a['ident'], 'name' => $a['name'], 'on' => (bool) $a['on'], 'text' => $a['on'] ? 'An' : 'Aus', 'icon' => '⏻']
                : $this->selectTile($a);
        }
        $add('Automatisierung', $auto);

        if ($d['sonos'] !== null) {
            $add('Musik', [['kind' => 'sonos', 'id' => 'sonos'] + $d['sonos']]);
        }

        // Licht: "Alle Lichter" als normaler Button (Wunsch 09.10.), danach jede Lampe als Button-Kachel
        $lights = [];
        if (count($d['lights']) > 1) {
            // "mindestens eine an" statt "alle an" – sonst muesste man zum Ausschalten erst alles einschalten
            $anyOn = array_reduce($d['lights'], static fn (bool $c, array $l): bool => $c || ($l['kind'] === 'dimmer' ? ($l['brightness'] ?? 0) > 0 : (bool) $l['on']), false);
            $lights[] = ['kind' => 'toggle', 'id' => 'lights_all', 'name' => 'Alle Lichter', 'on' => $anyOn, 'text' => $anyOn ? 'An' : 'Aus', 'icon' => '💡'];
        }
        foreach ($d['lights'] as $l) {
            $on = $l['kind'] === 'dimmer' ? ($l['brightness'] ?? 0) > 0 : (bool) $l['on'];
            if ($l['kind'] === 'switch') {
                $lights[] = ['kind' => 'toggle', 'id' => $l['ident'] . '_on', 'name' => $l['name'], 'on' => $on, 'text' => $on ? 'An' : 'Aus', 'icon' => '💡'];
            } else {
                $lights[] = ['kind' => 'light', 'id' => $l['ident'], 'name' => $l['name'], 'type' => $l['kind'], 'on' => $on,
                    'brightness' => (int) round($l['brightness'] ?? 0),
                    'color' => $l['color'] !== null ? '#' . str_pad(dechex(max(0, (int) $l['color'])), 6, '0', STR_PAD_LEFT) : null];
            }
        }
        $add('Licht', $lights);

        $shutters = [];
        foreach ($d['shutters'] as $s) {
            $shutters[] = ['kind' => 'shutter', 'id' => $s['ident'], 'name' => $s['name'], 'type' => $s['kind'],
                'value' => $s['kind'] === 'position' ? (int) round($s['value']) : null];
        }
        $add('Rollläden', $shutters);

        $sensors = [];
        foreach ($d['sensors'] as $i => $s) {
            if ($s['type'] === 'humidity' && $h !== null) {
                continue; // steht schon unter Klima
            }
            $id = 'sensor_' . $i;
            if ($s['bool'] !== null) {
                [$offIcon, $onIcon, $offText, $onText, $onState] = self::SENSOR_VIEW[$s['type']] ?? ['⚪', '🟢', 'Aus', 'An', ''];
                $sensors[] = ['kind' => 'status', 'id' => $id, 'name' => $s['name'], 'text' => $s['bool'] ? $onText : $offText,
                    'icon' => $s['bool'] ? $onIcon : $offIcon, 'state' => $s['bool'] ? $onState : ''];
            } else {
                $sensors[] = ['kind' => 'value', 'id' => $id, 'name' => $s['name'], 'text' => $this->fmtNum($s['value'], 1) . ($s['type'] === 'humidity' ? ' %' : '')];
            }
        }
        $add('Sensoren', $sensors);

        $smoke = [];
        $multi = count($d['smokeDetectors']) > 1;
        foreach ($d['smokeDetectors'] as $sd) {
            $prefix = $multi ? $sd['name'] . ': ' : '';
            if ($sd['kind'] !== 'nest') {
                $smoke[] = $this->alarmTile($sd['ident'], $prefix . 'Rauchmelder', $sd['alarm'], '🔥');
                continue;
            }
            $sub = [];
            if ($sd['lastTest'] !== null) {
                $sub[] = 'Test ' . date('d.m.Y', $sd['lastTest']);
            }
            if ($sd['replaceBy'] !== null) {
                $sub[] = 'Austausch ' . date('m/Y', $sd['replaceBy']);
            }
            $tile = $this->alarmTile($sd['ident'] . '_smoke', $prefix . 'Rauch', $sd['smoke'], '🔥');
            if ($sd['hushed'] === true && !$sd['smoke']) {
                $tile['text']  = 'Stummgeschaltet';
                $tile['state'] = 'warn';
            }
            $tile['sub'] = implode(' · ', $sub);
            $smoke[]     = $tile;
            if ($sd['co'] !== null) {
                $smoke[] = $this->alarmTile($sd['ident'] . '_co', $prefix . 'CO', $sd['co'], '☠️');
            }
            if ($sd['heat'] !== null) {
                $smoke[] = $this->alarmTile($sd['ident'] . '_heat', $prefix . 'Hitze', $sd['heat'], '🌡️');
            }
            if ($sd['battery'] !== null || $sd['wired'] !== null) {
                $smoke[] = ['kind' => 'value', 'id' => $sd['ident'] . '_batt', 'name' => $prefix . 'Batterie',
                    'text' => $sd['battery'] !== null ? $sd['battery'] . ' %' : '–', 'sub' => $sd['wired'] === null ? '' : ($sd['wired'] ? 'Netzstrom' : 'Batteriebetrieb')];
            }
        }
        $add('Rauchmelder', $smoke);

        $vac = [];
        foreach ($d['vacuums'] as $vc) {
            $b     = (int) $vc['battery'];
            $vac[] = ['kind' => 'vacuum', 'id' => $vc['ident'], 'name' => $vc['name'], 'state' => $vc['state'],
                'batt' => $b . ' %' . ($vc['charging'] ? ' ⚡' : ''), 'battState' => $b <= 20 ? 'alarm' : ($b <= 50 ? 'warn' : ''),
                'fan' => (string) $vc['fanSpeed'], 'last' => $vc['lastClean']];
        }
        $add('Saugroboter', $vac);

        return ['title' => $d['roomName'], 'updated' => $d['updated'], 'groups' => $groups];
    }

    private function alarmTile(string $id, string $name, bool $alarm, string $icon): array
    {
        return ['kind' => 'status', 'id' => $id, 'name' => $name, 'text' => $alarm ? 'ALARM' : 'OK', 'icon' => $alarm ? $icon : '✅', 'state' => $alarm ? 'alarm' : 'ok'];
    }

    /** Variable mit Profil-Assoziationen → Auswahl-Kachel (Buttonreihe), sonst reine Anzeige. */
    private function selectTile(array $row): array
    {
        if (count($row['options']) === 0) {
            return ['kind' => 'value', 'id' => $row['ident'], 'name' => $row['name'], 'text' => $row['value'] !== '' ? $row['value'] : '–', 'cls' => 'small-value'];
        }
        $opts = array_map(static fn ($o) => [(string) $o['Value'], (string) $o['Name']], $row['options']);
        return ['kind' => 'seg', 'id' => $row['ident'], 'name' => $row['name'], 'value' => (string) $row['value'], 'options' => $opts,
            'cls' => count($opts) > 3 ? 'tk-span2' : ''];
    }

    /** Flache Liste der Live-Updates: [op, id, wert]. Siehe tk.on('__all__') im JS. */
    private function ops(array $v): array
    {
        $ops = [];
        foreach ($v['groups'] as $g) {
            foreach ($g['tiles'] as $t) {
                $id = $t['id'];
                switch ($t['kind']) {
                    case 'value':
                        $ops[] = ['t', $id . '_val', $t['text']];
                        if (isset($t['sub'])) {
                            $ops[] = ['t', $id . '_sub', $t['sub']];
                        }
                        break;
                    case 'status':
                        array_push($ops, ['t', $id . '_val', $t['text']], ['t', $id . '_icon', $t['icon']], ['s', $id, $t['state']]);
                        if (isset($t['sub'])) {
                            $ops[] = ['t', $id . '_sub', $t['sub']];
                        }
                        break;
                    case 'toggle':
                        array_push($ops, ['o', $id . '_card', $t['on']], ['t', $id . '_val', $t['text']]);
                        break;
                    case 'seg':
                        $ops[] = ['g', $id, $t['value']];
                        break;
                    case 'thermo':
                        $ops[] = ['d', $id . '_soll', $t['soll']];
                        if ($t['ist'] !== null) {
                            $ops[] = ['t', $id . '_ist', $t['ist']];
                        }
                        if ($t['valve'] !== null) {
                            array_push($ops, ['w', $id . '_valve_fill', $t['valve']], ['t', $id . '_valve_pct', $t['valve'] . ' %']);
                        }
                        if ($t['mode'] !== null) {
                            $ops[] = ['g', $id . '_mode', (string) $t['mode']];
                        }
                        break;
                    case 'light':
                        array_push($ops, ['o', $id . '_card', $t['on']], ['v', $id . '_brightness_range', $t['brightness']], ['t', $id . '_brightness_val', $t['brightness'] . ' %']);
                        if ($t['color'] !== null) {
                            $ops[] = ['v', $id . '_color_input', $t['color']];
                        }
                        break;
                    case 'shutter':
                        if ($t['value'] !== null) {
                            array_push($ops, ['v', $id . '_range', $t['value']], ['t', $id . '_val', $t['value'] . ' % zu']);
                        }
                        break;
                    case 'vacuum':
                        array_push($ops, ['t', $id . '_state', $t['state']], ['t', $id . '_batt', $t['batt']], ['s', $id . '_batt', $t['battState']], ['g', $id . '_fanspeed', $t['fan']], ['t', $id . '_last', $t['last']]);
                        break;
                    case 'sonos':
                        $vol = (int) (($t['isGrouped'] ? $t['groupVolume'] : $t['volume']) ?? 0);
                        array_push($ops, ['t', 'sonos_title', $t['title'] !== '' ? $t['title'] : '–'], ['t', 'sonos_artist', $t['artist']],
                            ['t', 'sonos_play', $t['status'] === 2 ? '⏸' : '▶'], ['o', 'sonos_play', $t['status'] === 2], ['o', 'sonos_mute', (bool) $t['mute']],
                            ['v', 'sonos_volume_range', $vol], ['t', 'sonos_volume_val', $vol . ' %'], ['src', 'sonos_cover', $t['cover']]);
                        if ($t['playlist'] !== null) {
                            $ops[] = ['v', 'sonos_playlist_select', $t['playlist']];
                        }
                        if ($t['group'] !== null) {
                            $ops[] = ['v', 'sonos_group_select', $t['group']];
                        }
                        break;
                }
            }
        }
        return ['updated' => $v['updated'], 'ops' => $ops];
    }

    private function renderTile(array $t): string
    {
        $id  = $t['id'];
        $cls = $t['cls'] ?? '';
        switch ($t['kind']) {
            case 'value':
                $sub = isset($t['sub']) ? "<span id=\"{$id}_sub\" class=\"tk-sub\">" . self::tkEsc($t['sub']) . '</span>' : '';
                return "<div class=\"tk-tile {$cls}\" id=\"{$id}_card\"><span class=\"tk-name\">" . self::tkEsc($t['name']) . '</span>'
                    . "<span id=\"{$id}_val\" class=\"tk-value\">" . self::tkEsc($t['text']) . "</span>{$sub}</div>";
            case 'status':
                return self::tkStatusTile($id, $t['name'], $t['text'], $t['icon'], $t['state'], null, $t['sub'] ?? '', $cls);
            case 'toggle':
                return self::tkToggleTile($id, $t['name'], $t['on'], $t['text'], $t['icon']);
            case 'seg':
                return "<div class=\"tk-tile {$cls}\" id=\"{$id}_card\"><span class=\"tk-name\">" . self::tkEsc($t['name']) . '</span>'
                    . $this->segButtons($id, $t['options'], $t['value']) . '</div>';
            case 'thermo':
                return $this->renderThermo($t);
            case 'light':
                return $this->renderLight($t);
            case 'shutter':
                return $this->renderShutter($t);
            case 'vacuum':
                return $this->renderVacuum($t);
            case 'sonos':
                return $this->renderSonos($t);
        }
        return '';
    }

    /** Buttonreihe; Klick ruft RequestAction($ident, Wert) und markiert sofort. */
    private function segButtons(string $ident, array $options, ?string $current): string
    {
        $html = "<div class=\"tk-seg\" id=\"{$ident}_seg\">";
        foreach ($options as [$value, $label]) {
            $on   = $current !== null && $value === $current ? ' class="tk-on"' : '';
            $vEsc = self::tkEsc($value);
            $html .= "<button type=\"button\"{$on} data-value=\"{$vEsc}\" onclick=\"pick('{$ident}', this)\">" . self::tkEsc($label) . '</button>';
        }
        return $html . '</div>';
    }

    private function renderThermo(array $t): string
    {
        $id    = $t['id'];
        $name  = $t['name'] !== '' ? '<span class="tk-name">' . self::tkEsc($t['name']) . '</span>' : '';
        $stats = '';
        if ($t['ist'] !== null) {
            $stats .= "<div><span class=\"tk-name\">Ist</span><span id=\"{$id}_ist\" class=\"tk-value\">" . self::tkEsc($t['ist']) . '</span></div>';
        }
        if ($t['valve'] !== null) {
            $stats .= "<div><span class=\"tk-name\">Ventil <span id=\"{$id}_valve_pct\">{$t['valve']} %</span></span>"
                . "<div class=\"valve-bar\"><div id=\"{$id}_valve_fill\" class=\"valve-fill\" style=\"width:{$t['valve']}%\"></div></div></div>";
        }
        $modes = count($t['options']) > 0
            ? $this->segButtons($id . '_mode', array_map(static fn ($o) => [(string) $o['Value'], (string) $o['Name']], $t['options']), $t['mode'] !== null ? (string) $t['mode'] : null)
            : '';
        return "<div class=\"tk-tile tk-span2 thermo\" id=\"{$id}_card\">{$name}<div class=\"thermo-body\">"
            . $this->renderThermoDial($id . '_soll', $t['soll']) . "<div class=\"thermo-stats\">{$stats}</div></div>{$modes}</div>";
    }

    /** Licht mit Dimmer/Farbe: die ganze Kachel ist der Ein/Aus-Button, Regler und Farbwahl liegen darin. */
    private function renderLight(array $t): string
    {
        $id    = $t['id'];
        $on    = $t['on'] ? ' tk-on' : '';
        $click = $t['type'] === 'dimmer' ? "dimToggle('{$id}')" : "lightToggle('{$id}')";
        $color = $t['color'] !== null
            ? "<input id=\"{$id}_color_input\" type=\"color\" class=\"tk-color\" value=\"{$t['color']}\" onchange=\"requestAction('{$id}_color', parseInt(this.value.substring(1),16))\">"
            : '';
        // Kopfzeile: Name links, Farbwahl + Lampensymbol rechts; darunter der Dimmer ueber die volle Breite
        return "<div class=\"tk-tile tk-click light{$on}\" id=\"{$id}_card\" role=\"button\" onclick=\"{$click}\">"
            . '<span class="tk-row"><span class="tk-name">' . self::tkEsc($t['name']) . '</span>'
            . "<span class=\"light-head\" onclick=\"event.stopPropagation()\">{$color}</span><span class=\"tk-icon\">💡</span></span>"
            . "<div class=\"light-ctrl\" onclick=\"event.stopPropagation()\">"
            . "<input id=\"{$id}_brightness_range\" type=\"range\" class=\"tk-range\" min=\"0\" max=\"100\" value=\"{$t['brightness']}\""
            . " oninput=\"tk.setText('{$id}_brightness_val', this.value + ' %')\" onchange=\"requestAction('{$id}_brightness', parseInt(this.value))\">"
            . "<span id=\"{$id}_brightness_val\" class=\"tk-sub light-pct\">{$t['brightness']} %</span></div></div>";
    }

    private function renderShutter(array $t): string
    {
        $id   = $t['id'];
        $name = '<span class="tk-name">' . self::tkEsc($t['name']) . '</span>';
        if ($t['type'] === 'updown') {
            // RTS/unidirektional: keine Rueckmeldung, nur Auf/Stopp/Zu
            return "<div class=\"tk-tile shutter\" id=\"{$id}_card\">{$name}<div class=\"tk-seg\">"
                . "<button onclick=\"requestAction('{$id}_state','open')\">▲</button><button onclick=\"requestAction('{$id}_state','stop')\">■</button>"
                . "<button onclick=\"requestAction('{$id}_state','closed')\">▼</button></div></div>";
        }
        return "<div class=\"tk-tile shutter\" id=\"{$id}_card\"><span class=\"tk-row\">{$name}<span id=\"{$id}_val\" class=\"tk-sub\">{$t['value']} % zu</span></span>"
            . "<input id=\"{$id}_range\" type=\"range\" class=\"tk-range\" min=\"0\" max=\"100\" value=\"{$t['value']}\""
            . " oninput=\"tk.setText('{$id}_val', this.value + ' % zu')\" onchange=\"requestAction('{$id}_position', parseInt(this.value))\">"
            . "<div class=\"tk-seg\"><button onclick=\"requestAction('{$id}_position',0)\">▲ Auf</button><button onclick=\"requestAction('{$id}_position',100)\">▼ Zu</button></div></div>";
    }

    private function renderVacuum(array $t): string
    {
        $id   = $t['id'];
        $bcls = $t['battState'] !== '' ? ' tk-' . $t['battState'] : '';
        $fan  = $this->segButtons($id . '_fanspeed', array_map(static fn ($k, $l) => [(string) $k, $l], array_keys(self::FAN_SPEEDS), self::FAN_SPEEDS), $t['fan']);
        return "<div class=\"tk-tile tk-span2 vac\" id=\"{$id}_card\"><span class=\"tk-row\"><span class=\"tk-name\">🤖 " . self::tkEsc($t['name']) . '</span>'
            . "<span id=\"{$id}_batt\" class=\"tk-status{$bcls}\">" . self::tkEsc($t['batt']) . '</span></span>'
            . "<span id=\"{$id}_state\" class=\"tk-status\">" . self::tkEsc($t['state']) . '</span>'
            . "<div class=\"tk-seg\"><button onclick=\"tk.action('{$id}_clean')\">▶</button><button onclick=\"tk.action('{$id}_pause')\">⏸</button>"
            . "<button onclick=\"tk.action('{$id}_stop')\">⏹</button><button onclick=\"tk.action('{$id}_dock')\">🏠</button></div>{$fan}"
            . "<span id=\"{$id}_last\" class=\"tk-sub\">" . self::tkEsc($t['last']) . '</span></div>';
    }

    private function renderSonos(array $t): string
    {
        $vol     = (int) (($t['isGrouped'] ? $t['groupVolume'] : $t['volume']) ?? 0);
        $volId   = $t['isGrouped'] ? 'sonos_groupvolume' : 'sonos_volume';
        $playing = $t['status'] === 2; // SonosAccess::PLAY
        $cover   = '<img id="sonos_cover" class="sonos-cover" src="' . self::tkEsc($t['cover']) . '" alt="" onerror="this.style.visibility=\'hidden\'">';
        $select  = static function (string $id, array $opts, ?string $cur): string {
            if (count($opts) === 0) {
                return '';
            }
            $o = '';
            foreach ($opts as $opt) {
                $v  = (string) $opt['Value'];
                $o .= '<option value="' . self::tkEsc($v) . '"' . ($cur === $v ? ' selected' : '') . '>' . self::tkEsc($opt['Name']) . '</option>';
            }
            return "<select id=\"{$id}_select\" class=\"tk-select\" onchange=\"requestAction('{$id}', this.value)\">{$o}</select>";
        };
        return '<div class="tk-tile tk-span2 sonos" id="sonos_card">'
            . "<div class=\"sonos-now\">{$cover}<div class=\"sonos-info\"><span id=\"sonos_title\" class=\"tk-text sonos-title\">" . self::tkEsc($t['title'] !== '' ? $t['title'] : '–') . '</span>'
            . '<span id="sonos_artist" class="tk-sub">' . self::tkEsc($t['artist']) . '</span></div></div>'
            . '<div class="tk-seg"><button onclick="requestAction(\'sonos_status\',0)">⏮</button>'
            . '<button id="sonos_play"' . ($playing ? ' class="tk-on"' : '') . ' onclick="sonosPlay()">' . ($playing ? '⏸' : '▶') . '</button>'
            . '<button onclick="requestAction(\'sonos_status\',1)">⏹</button><button onclick="requestAction(\'sonos_status\',4)">⏭</button>'
            . '<button id="sonos_mute"' . ($t['mute'] ? ' class="tk-on"' : '') . ' onclick="sonosMute()">🔇</button></div>'
            . "<div class=\"light-ctrl\"><span class=\"tk-sub\">🔊</span><input id=\"sonos_volume_range\" type=\"range\" class=\"tk-range\" min=\"0\" max=\"100\" value=\"{$vol}\""
            . " oninput=\"tk.setText('sonos_volume_val', this.value + ' %')\" onchange=\"requestAction('{$volId}', parseInt(this.value))\"><span id=\"sonos_volume_val\" class=\"tk-sub light-pct\">{$vol} %</span></div>"
            . $select('sonos_playlist', $t['playlistOptions'], $t['playlist']) . $select('sonos_group', $t['groupOptions'], $t['group']) . '</div>';
    }

    // ─── Thermostat-Drehregler (wie HeatingDashboard) ───────────────────────

    /** Thumb (x,y) on the 120x120/r45/center60,60 dial arc for a value in [min,max]. */
    private function dialThumbPos(float $value, float $min, float $max): array
    {
        $frac = $max > $min ? max(0, min(1, ($value - $min) / ($max - $min))) : 0;
        $rad  = deg2rad(-135 + $frac * 270);
        return [round(60 + 45 * sin($rad), 1), round(60 - 45 * cos($rad), 1)];
    }

    private function dialArcPath(float $frac): string
    {
        $frac     = max(0, min(1, $frac));
        $sweepDeg = $frac * 270;
        $endRad   = deg2rad(-135 + $sweepDeg);
        $ex       = round(60 + 45 * sin($endRad), 1);
        $ey       = round(60 - 45 * cos($endRad), 1);
        $largeArc = $sweepDeg > 180 ? 1 : 0;
        return "M 28.2 91.8 A 45 45 0 {$largeArc} 1 {$ex} {$ey}";
    }

    /** Blau (kalt) bis Rot (warm), damit der Regler sichtbar waermer wird. */
    private function dialColor(float $frac): string
    {
        $frac = max(0, min(1, $frac));
        $cold = [63, 169, 245];
        $hot  = [242, 85, 90];
        $rgb  = [];
        for ($i = 0; $i < 3; $i++) {
            $rgb[$i] = (int) round($cold[$i] + ($hot[$i] - $cold[$i]) * $frac);
        }
        return sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
    }

    private function renderThermoDial(string $ident, ?float $value): string
    {
        [$min, $max, $step] = self::THERMOSTAT_RANGE;
        $val       = $value ?? $min;
        $frac      = $max > $min ? max(0, min(1, ($val - $min) / ($max - $min))) : 0;
        [$tx, $ty] = $this->dialThumbPos($val, $min, $max);
        $color     = $this->dialColor($frac);
        $valStr    = $value !== null ? $this->fmtNum($value, 1) . '°' : '–';
        return "<div class=\"dial\" data-ident=\"{$ident}\" data-min=\"{$min}\" data-max=\"{$max}\" data-step=\"{$step}\" data-value=\"{$val}\">"
            . '<svg class="dial-svg" viewBox="0 0 120 120">'
            . '<path d="M 28.2 91.8 A 45 45 0 1 1 91.8 91.8" fill="none" stroke="#1e2d40" stroke-width="8" stroke-linecap="round"/>'
            . '<path class="dial-arc" d="' . $this->dialArcPath($frac) . "\" fill=\"none\" stroke=\"{$color}\" stroke-width=\"8\" stroke-linecap=\"round\"/>"
            . "<circle class=\"dial-thumb\" cx=\"{$tx}\" cy=\"{$ty}\" r=\"7\" fill=\"{$color}\"/></svg>"
            . "<div class=\"dial-center\"><span class=\"dial-value\" style=\"color:{$color}\">{$valStr}</span><span class=\"tk-sub\">Soll</span></div></div>";
    }

    private function buildDashboardHTML(): string
    {
        $v    = $this->view($this->collectData());
        $body = '';
        foreach ($v['groups'] as $g) {
            $body .= self::tkGroup($g['name']);
            foreach ($g['tiles'] as $t) {
                $body .= $this->renderTile($t);
            }
        }
        if ($body === '') {
            $body = '<div class="tk-empty tk-full">Noch nichts konfiguriert.</div>';
        }

        $css = <<<'CSS'
.small-value .tk-value{font-size:15px}
/* Thermostat: Drehregler + Ist/Ventil, Betriebsart als Buttonreihe */
.thermo{gap:8px;justify-content:flex-start}
.thermo-body{display:flex;align-items:center;gap:14px}
.thermo-stats{display:flex;flex-direction:column;gap:8px;flex:1;min-width:0}
.dial{position:relative;width:104px;height:104px;flex:none;touch-action:none}
.dial-svg{width:104px;height:104px;display:block;cursor:pointer}
.dial-arc{transition:stroke .15s}
.dial-thumb{transition:fill .15s}
.dial-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none}
.dial-value{font-size:var(--tk-fs-value);font-weight:700;transition:color .15s}
.valve-bar{height:8px;margin-top:4px;background:var(--tk-border);border-radius:4px;overflow:hidden}
.valve-fill{height:100%;background:var(--tk-accent);border-radius:4px;transition:width .3s}
/* Licht: ganze Kachel schaltet, Regler/Farbe darin */
.light,.shutter{justify-content:flex-start;gap:6px}
.light .tk-row{margin-top:0}
.light-head{margin-left:auto;display:flex}
.light-head:empty{display:none}
.shutter .tk-sub{white-space:nowrap}
.light-ctrl{display:flex;align-items:center;gap:8px;cursor:default}
.light-pct{min-width:34px;text-align:right}
.tk-color{width:30px;height:30px;flex:none;border:1px solid var(--tk-border);border-radius:7px;padding:0;background:none;cursor:pointer}
.vac,.sonos{justify-content:flex-start;gap:6px}
.sonos-now{display:flex;gap:10px;align-items:center;min-width:0}
.sonos-cover{width:56px;height:56px;border-radius:8px;object-fit:cover;background:var(--tk-surface-2);flex:none}
.sonos-info{display:flex;flex-direction:column;gap:2px;min-width:0}
.sonos-title{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
CSS;
        $js = <<<'JS'
// Auswahl (Status, Betriebsart, Saugstaerke): sofort markieren, dann schreiben
function pick(ident, btn) {
  Array.prototype.forEach.call(btn.parentElement.children, function (b) { b.classList.toggle('tk-on', b === btn); });
  requestAction(ident, btn.getAttribute('data-value'));
}
function lightToggle(id) {
  var c = tk.el(id + '_card'), next = !(c && c.classList.contains('tk-on'));
  if (c) c.classList.toggle('tk-on', next);
  requestAction(id + '_on', next);
}
function dimToggle(id) {
  var c = tk.el(id + '_card'), next = c && c.classList.contains('tk-on') ? 0 : 100;
  if (c) c.classList.toggle('tk-on', next > 0);
  tk.el(id + '_brightness_range').value = next; tk.setText(id + '_brightness_val', next + ' %');
  requestAction(id + '_brightness', next);
}
function sonosPlay() { var b = tk.el('sonos_play'); requestAction('sonos_status', b && b.classList.contains('tk-on') ? 3 : 2); }
function sonosMute() { var b = tk.el('sonos_mute'), next = !(b && b.classList.contains('tk-on')); if (b) b.classList.toggle('tk-on', next); requestAction('sonos_mute', next); }

// Drehregler (Ziehen setzt Soll, Loslassen schreibt)
var dials = {};
function dialColor(f) { f = Math.max(0, Math.min(1, f)); var c = [63, 169, 245], h = [242, 85, 90]; return 'rgb(' + c.map(function (v, i) { return Math.round(v + (h[i] - v) * f); }).join(',') + ')'; }
function dialArc(f) { f = Math.max(0, Math.min(1, f)); var s = f * 270, r = (-135 + s) * Math.PI / 180; return 'M 28.2 91.8 A 45 45 0 ' + (s > 180 ? 1 : 0) + ' 1 ' + (60 + 45 * Math.sin(r)).toFixed(1) + ' ' + (60 - 45 * Math.cos(r)).toFixed(1); }
document.querySelectorAll('.dial').forEach(function (root) {
  var svg = root.querySelector('.dial-svg'), thumb = root.querySelector('.dial-thumb'), arc = root.querySelector('.dial-arc'), val = root.querySelector('.dial-value');
  var min = +root.dataset.min, max = +root.dataset.max, step = +root.dataset.step, cur = +root.dataset.value, drag = false;
  function show(v) {
    var f = max > min ? Math.max(0, Math.min(1, (v - min) / (max - min))) : 0, r = (-135 + f * 270) * Math.PI / 180, col = dialColor(f);
    thumb.setAttribute('cx', (60 + 45 * Math.sin(r)).toFixed(1)); thumb.setAttribute('cy', (60 - 45 * Math.cos(r)).toFixed(1)); thumb.setAttribute('fill', col);
    arc.setAttribute('d', dialArc(f)); arc.setAttribute('stroke', col);
    val.textContent = v.toFixed(1).replace('.', ',') + '°'; val.style.color = col;
  }
  function at(e) {
    var pt = svg.createSVGPoint(); pt.x = e.clientX; pt.y = e.clientY; var m = svg.getScreenCTM(); if (!m) return;
    var p = pt.matrixTransform(m.inverse()), a = Math.max(-135, Math.min(135, Math.atan2(p.x - 60, 60 - p.y) * 180 / Math.PI));
    cur = Math.round((min + (a + 135) / 270 * (max - min)) / step) * step; show(cur);
  }
  svg.addEventListener('pointerdown', function (e) { drag = true; svg.setPointerCapture(e.pointerId); at(e); });
  svg.addEventListener('pointermove', function (e) { if (drag) at(e); });
  function end() { if (drag) { drag = false; requestAction(root.dataset.ident, cur); } }
  svg.addEventListener('pointerup', end); svg.addEventListener('pointercancel', end);
  dials[root.dataset.ident] = { show: show, busy: function () { return drag; } };
});

// Live-Updates: flache Liste aus PHP (ops) anwenden
var STATES = ['ok', 'warn', 'alarm'];
tk.on('__all__', function (p) {
  p.ops.forEach(function (o) {
    var k = o[0], id = o[1], v = o[2], e = tk.el(id);
    if (k === 't') tk.setText(id, v);
    else if (k === 'o') tk.setOn(id, v);
    else if (k === 'v') { if (e && document.activeElement !== e && String(e.value) !== String(v)) e.value = v; }
    else if (k === 'w') { if (e) e.style.width = v + '%'; }
    else if (k === 'src') { if (e && v && e.getAttribute('src') !== v) { e.style.visibility = ''; e.src = v; } }
    else if (k === 'd') { var d = dials[id]; if (d && v !== null && !d.busy()) d.show(+v); }
    else if (k === 'g') { var g = tk.el(id + '_seg'); if (g) Array.prototype.forEach.call(g.children, function (b) { b.classList.toggle('tk-on', b.getAttribute('data-value') === String(v)); }); }
    else if (k === 's') {
      var card = tk.el(id + '_card'), st = tk.el(id + '_status') || e;
      if (card) { card.classList.toggle('tk-is-alarm', v === 'alarm'); card.classList.toggle('tk-is-warn', v === 'warn'); }
      if (st) STATES.forEach(function (s) { st.classList.toggle('tk-' + s, s === v); });
    }
  });
});
JS;
        return $this->tkPage($v['title'], '🏠', $v['updated'], "<div class=\"tk-grid\">{$body}</div>", $css, $js, 'null');
    }
}
