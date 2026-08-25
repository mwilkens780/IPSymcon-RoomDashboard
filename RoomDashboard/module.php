<?php

declare(strict_types=1);

class RoomDashboard extends IPSModule
{
    private const SENSOR_BOOL_TYPES = ['window', 'door', 'smoke', 'siren'];

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

        $hasAnything = $this->ReadPropertyInteger('var_presence') > 0
            || $this->ReadPropertyInteger('sonos_instance') > 0
            || $this->ReadPropertyInteger('humidity_instance') > 0
            || count($lights) > 0 || count($shutters) > 0 || count($sensors) > 0
            || count($statusVars) > 0 || count($thermostats) > 0;

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
            $data = $this->collectData();
            $this->pushValue('__all__', $data);
            $this->SetStatus(102);
        } catch (\Throwable $e) {
            $this->LogMessage('RoomDashboard Refresh: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
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
            if (strpos($Ident, 'sonos_') === 0) {
                $this->forwardSonosAction(substr($Ident, strlen('sonos_')), $Value, $Ident);
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
                $this->forwardListAction('shutters', (int) substr($Ident, strlen('shutter_')), $Value, $Ident);
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
        $this->pushValue($pushIdent, $cast);
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
            $this->pushValue($pushIdent, (string) $targetInstanceId);
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
        $this->pushValue($pushIdent, '0');
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
        $this->pushValue($pushIdent, $cast);
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
            if ($idents === null) {
                return;
            }
            [$onIdent, $brightIdent, $colorIdent] = $idents;
            $identMap = ['on' => $onIdent, 'brightness' => $brightIdent, 'color' => $colorIdent];
            if (!isset($identMap[$control])) {
                return;
            }
            $targetId = $this->varIdByIdent($nodeId, $identMap[$control]);
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
        $this->pushValue($pushIdent, $cast);
    }

    /**
     * Homematic (classic and IP) datapoint idents for a heating thermostat
     * channel. Tried in order since the exact ident can differ between
     * device generations; the first one that resolves on the configured
     * node wins.
     */
    private const THERMOSTAT_SET_IDENTS    = ['SET_POINT_TEMPERATURE', 'SETPOINT'];
    private const THERMOSTAT_ACTUAL_IDENTS = ['ACTUAL_TEMPERATURE'];
    private const THERMOSTAT_MODE_IDENTS   = ['CONTROL_MODE', 'SET_POINT_MODE'];
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

        $targetId = 0;
        if (@IPS_InstanceExists($nodeId)) {
            $identsByControl = [
                'soll' => self::THERMOSTAT_SET_IDENTS,
                'mode' => self::THERMOSTAT_MODE_IDENTS,
            ];
            if (!isset($identsByControl[$control])) {
                return;
            }
            $targetId = $this->firstVarIdByIdent($nodeId, $identsByControl[$control]);
        } elseif (@IPS_VariableExists($nodeId) && $control === 'soll') {
            $targetId = $nodeId;
        }

        if ($targetId <= 0) {
            return;
        }
        $cast = $this->castToVarType($targetId, $value);
        RequestAction($targetId, $cast);
        $this->pushValue($pushIdent, $cast);
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

    private function pushValue(string $key, $value): void
    {
        $this->UpdateVisualizationValue(json_encode(['key' => $key, 'value' => $value]));
    }

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

        return [
            'roomName'    => $this->roomName(),
            'presence'    => $presence,
            'sonos'       => $sonos,
            'lights'      => $lights,
            'shutters'    => $shutters,
            'sensors'     => $sensors,
            'statusVars'  => $statusVars,
            'thermostats' => $thermostats,
            'humidity'    => $humidity,
            'updated'     => date('d.m. H:i'),
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
            if ($idents === null) {
                return null;
            }
            [$onIdent, $brightIdent, $colorIdent] = $idents;
            $onId     = $this->varIdByIdent($nodeId, $onIdent);
            $brightId = $this->varIdByIdent($nodeId, $brightIdent);
            $colorId  = $this->varIdByIdent($nodeId, $colorIdent);
            if ($onId <= 0) {
                return null;
            }
            return [
                'ident'      => $ident,
                'name'       => $nameOverride ?? $this->deviceName($nodeId),
                'kind'       => $colorId > 0 ? 'color' : ($brightId > 0 ? 'dimmer' : 'switch'),
                'on'         => (bool) $this->readVarById($onId),
                'brightness' => $brightId > 0 ? (float) $this->readVarById($brightId) : null,
                'color'      => $colorId > 0 ? (int) $this->readVarById($colorId) : null,
            ];
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
            $varId = (int) ($row['variable'] ?? 0);
            if ($varId <= 0 || !@IPS_VariableExists($varId)) {
                continue;
            }
            $out[] = [
                'ident' => 'shutter_' . $i,
                'name'  => ($row['name'] ?? '') !== '' ? $row['name'] : $this->deviceName($varId),
                'value' => (float) GetValue($varId),
            ];
        }
        return $out;
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

    private function collectThermostats(): array
    {
        // Reuse the room's own humidity sensor (from the generic sensors
        // list) inside the thermostat tile too, instead of asking for it
        // to be configured a second time -- a room only ever has the one
        // ambient reading regardless of how many thermostats it has.
        $humidity = null;
        foreach ($this->collectSensors() as $sensor) {
            if ($sensor['type'] === 'humidity') {
                $humidity = $sensor['value'];
                break;
            }
        }

        $out = [];
        foreach (json_decode($this->ReadPropertyString('thermostats'), true) ?: [] as $i => $row) {
            $nodeId = (int) ($row['node'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }

            $sollId = $istId = $modeId = 0;
            if (@IPS_InstanceExists($nodeId)) {
                $sollId = $this->firstVarIdByIdent($nodeId, self::THERMOSTAT_SET_IDENTS);
                $istId  = $this->firstVarIdByIdent($nodeId, self::THERMOSTAT_ACTUAL_IDENTS);
                $modeId = $this->firstVarIdByIdent($nodeId, self::THERMOSTAT_MODE_IDENTS);
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
                'humidity'    => $humidity,
            ];
        }
        return $out;
    }

    private function collectHumidity(): ?array
    {
        $humId = $this->ReadPropertyInteger('humidity_instance');
        if ($humId <= 0 || !@IPS_InstanceExists($humId)) {
            return null;
        }
        $hintId   = $this->varIdByIdent($humId, 'Hint');
        $resultId = $this->varIdByIdent($humId, 'Result');
        $dpOutId  = $this->varIdByIdent($humId, 'DewPointOutdoor');
        $dpInId   = $this->varIdByIdent($humId, 'DewPointIndoor');

        return [
            'hint'        => $hintId > 0 ? (bool) $this->readVarById($hintId) : null,
            'result'      => $resultId > 0 ? (string) $this->readVarById($resultId) : '',
            'dewPointOut' => $dpOutId > 0 ? (float) $this->readVarById($dpOutId) : null,
            'dewPointIn'  => $dpInId > 0 ? (float) $this->readVarById($dpInId) : null,
        ];
    }

    // ─── Rendering ──────────────────────────────────────────────────────────────

    private function renderStatusBadge(string $id, string $label, ?bool $active, bool $warnWhenActive = false): string
    {
        if ($active === null) {
            return '';
        }
        $cls  = $active ? ($warnWhenActive ? 'badge-warn' : 'badge-on') : 'badge-off';
        $text = htmlspecialchars($label . ($active ? ': an' : ': aus'), ENT_QUOTES);
        return "<span id=\"{$id}\" class=\"badge {$cls}\">{$text}</span>";
    }

    private function renderSelect(string $ident, array $options, ?string $current, string $cls = 'mode-select'): string
    {
        $optionsHtml = '';
        foreach ($options as $opt) {
            $value    = (string) $opt['Value'];
            $selected = $current !== null && $value === $current ? ' selected' : '';
            $optionsHtml .= '<option value="' . htmlspecialchars($value, ENT_QUOTES) . '"' . $selected . '>'
                . htmlspecialchars($opt['Name'], ENT_QUOTES) . '</option>';
        }
        return "<select id=\"{$ident}_select\" class=\"{$cls}\" onchange=\"requestAction('{$ident}', this.value)\">{$optionsHtml}</select>";
    }

    private function renderLightTile(array $light): string
    {
        $nameEsc = htmlspecialchars($light['name'], ENT_QUOTES);
        $ident   = $light['ident'];

        if ($light['kind'] === 'switch') {
            $checked = $light['on'] ? ' checked' : '';
            return "<div class=\"light-tile\"><span class=\"light-name\">{$nameEsc}</span>"
                . "<label class=\"toggle\"><input id=\"{$ident}_on_input\" type=\"checkbox\"{$checked} onchange=\"requestAction('{$ident}_on', this.checked)\">"
                . '<span class="toggle-track"><span class="toggle-thumb"></span></span></label></div>';
        }

        if ($light['kind'] === 'dimmer') {
            $val = (int) round($light['brightness'] ?? 0);
            return "<div class=\"light-tile\"><span class=\"light-name\">{$nameEsc}</span>"
                . "<input id=\"{$ident}_brightness_range\" type=\"range\" min=\"0\" max=\"100\" value=\"{$val}\" class=\"light-slider\" "
                . "oninput=\"document.getElementById('{$ident}_brightness_val').textContent=this.value+'%'\" onchange=\"requestAction('{$ident}_brightness', parseInt(this.value))\">"
                . "<span id=\"{$ident}_brightness_val\" class=\"light-value\">{$val}%</span></div>";
        }

        // 'color': switch + brightness + color picker, uniform regardless of manufacturer.
        $checked  = $light['on'] ? ' checked' : '';
        $val      = (int) round($light['brightness'] ?? 0);
        $colorHex = '#' . str_pad(dechex(max(0, (int) ($light['color'] ?? 0))), 6, '0', STR_PAD_LEFT);
        return <<<COLORLIGHT
<div class="light-tile light-tile-color">
  <div class="light-tile-head">
    <span class="light-name">{$nameEsc}</span>
    <label class="toggle"><input id="{$ident}_on_input" type="checkbox"{$checked} onchange="requestAction('{$ident}_on', this.checked)">
      <span class="toggle-track"><span class="toggle-thumb"></span></span></label>
  </div>
  <div class="light-tile-controls">
    <input id="{$ident}_color_input" type="color" class="color-picker" value="{$colorHex}" onchange="requestAction('{$ident}_color', parseInt(this.value.substring(1),16))">
    <input id="{$ident}_brightness_range" type="range" min="0" max="100" value="{$val}" class="light-slider"
      oninput="document.getElementById('{$ident}_brightness_val').textContent=this.value+'%'" onchange="requestAction('{$ident}_brightness', parseInt(this.value))">
    <span id="{$ident}_brightness_val" class="light-value">{$val}%</span>
  </div>
</div>
COLORLIGHT;
    }

    private function renderShutterTile(array $shutter): string
    {
        $nameEsc = htmlspecialchars($shutter['name'], ENT_QUOTES);
        $ident   = $shutter['ident'];
        $val     = (int) round($shutter['value']);
        return "<div class=\"light-tile\"><span class=\"light-name\">{$nameEsc}</span>"
            . "<input id=\"{$ident}_range\" type=\"range\" min=\"0\" max=\"100\" value=\"{$val}\" class=\"light-slider\" "
            . "oninput=\"document.getElementById('{$ident}_val').textContent=this.value+'%'\" onchange=\"requestAction('{$ident}', parseInt(this.value))\">"
            . "<span id=\"{$ident}_val\" class=\"light-value\">{$val}%</span></div>";
    }

    private function renderSensorTile(array $sensor): string
    {
        $nameEsc = htmlspecialchars($sensor['name'], ENT_QUOTES);
        $icons   = ['window' => '🪟', 'door' => '🚪', 'humidity' => '💧', 'smoke' => '🔥', 'siren' => '🚨', 'generic' => '📟'];
        $icon    = $icons[$sensor['type']] ?? '📟';

        if ($sensor['bool'] !== null) {
            $labels = [
                'window' => ['Zu', 'Offen'], 'door' => ['Zu', 'Offen'],
                'smoke' => ['Ruhe', 'Alarm'], 'siren' => ['Ruhe', 'Alarm'],
            ];
            [$offText, $onText] = $labels[$sensor['type']] ?? ['Aus', 'An'];
            $cls  = $sensor['bool'] ? 'badge-warn' : 'badge-off';
            $text = htmlspecialchars(($sensor['bool'] ? $onText : $offText), ENT_QUOTES);
            return "<div class='cur-tile'><span class='cur-label'>{$icon} {$nameEsc}</span><span class='badge {$cls}' style='align-self:flex-start'>{$text}</span></div>";
        }

        $unit = $sensor['type'] === 'humidity' ? ' %' : '';
        $valStr = $this->fmtNum($sensor['value'], 1) . $unit;
        return $this->renderStatTile('', "{$icon} {$nameEsc}", $valStr);
    }

    private function renderStatTile(string $id, string $label, string $value): string
    {
        $idAttr = $id !== '' ? " id=\"{$id}\"" : '';
        return "<div class='cur-tile'><span class='cur-label'>{$label}</span><span{$idAttr} class='cur-value'>{$value}</span></div>";
    }

    private function renderStatusVarTile(array $row): string
    {
        $nameEsc = htmlspecialchars($row['name'], ENT_QUOTES);
        if (count($row['options']) > 0) {
            $buttonsHtml = '';
            foreach ($row['options'] as $opt) {
                $value    = (string) $opt['Value'];
                $active   = $value === $row['value'] ? ' status-btn-active' : '';
                $valueEsc = htmlspecialchars($value, ENT_QUOTES);
                $labelEsc = htmlspecialchars($opt['Name'], ENT_QUOTES);
                $buttonsHtml .= "<button type=\"button\" class=\"status-btn{$active}\" data-value=\"{$valueEsc}\" "
                    . "onclick=\"statusVarSelect('{$row['ident']}', '{$valueEsc}', this)\">{$labelEsc}</button>";
            }
            return "<div class=\"light-tile\"><span class=\"light-name\">{$nameEsc}</span>"
                . "<div class=\"status-btn-group\" id=\"{$row['ident']}_group\">{$buttonsHtml}</div></div>";
        }
        return $this->renderStatTile('', $nameEsc, htmlspecialchars($row['value'], ENT_QUOTES));
    }

    /** Thumb (x,y) on the 120x120/r45/center60,60 dial arc for a value in [min,max]. Mirrors HeatingDashboard's dial. */
    private function dialThumbPos(float $value, float $min, float $max): array
    {
        $frac     = $max > $min ? max(0, min(1, ($value - $min) / ($max - $min))) : 0;
        $angleDeg = -135 + $frac * 270;
        $rad      = deg2rad($angleDeg);
        return [round(60 + 45 * sin($rad), 1), round(60 - 45 * cos($rad), 1)];
    }

    private function renderThermoDial(string $ident, ?float $value): string
    {
        [$min, $max, $step] = self::THERMOSTAT_RANGE;
        $val    = $value ?? $min;
        [$tx, $ty] = $this->dialThumbPos($val, $min, $max);
        $valStr = $value !== null ? $this->fmtNum($value, 1) . '°' : '–';

        return "<div class=\"dial\" data-ident=\"{$ident}\" data-min=\"{$min}\" data-max=\"{$max}\" data-step=\"{$step}\" data-value=\"{$val}\">"
            . '<svg class="dial-svg" viewBox="0 0 120 120">'
            . '<path class="dial-track" d="M 28.2 91.8 A 45 45 0 1 1 91.8 91.8" fill="none" stroke="#1e2d40" stroke-width="8" stroke-linecap="round"/>'
            . "<circle class=\"dial-thumb\" cx=\"{$tx}\" cy=\"{$ty}\" r=\"7\" fill=\"#7ec8f0\"/>"
            . '</svg>'
            . "<div class=\"dial-value\">{$valStr}</div>"
            . '</div>';
    }

    private function renderModeButtons(string $ident, array $options, ?string $current): string
    {
        $html = "<div class=\"mode-row\" data-ident=\"{$ident}\">";
        foreach ($options as $opt) {
            $value     = (string) $opt['Value'];
            $activeCls = $current === $value ? ' mode-active' : '';
            $valueEsc  = htmlspecialchars($value, ENT_QUOTES);
            $labelEsc  = htmlspecialchars($opt['Name'], ENT_QUOTES);
            $html .= "<button type=\"button\" class=\"mode-btn{$activeCls}\" data-value=\"{$valueEsc}\" "
                . "onclick=\"requestAction('{$ident}', '{$valueEsc}')\">{$labelEsc}</button>";
        }
        return $html . '</div>';
    }

    private function renderThermostatTile(array $t): string
    {
        $nameEsc = htmlspecialchars($t['name'], ENT_QUOTES);
        $ident   = $t['ident'];
        $dial    = $this->renderThermoDial($ident . '_soll', $t['soll']);

        $statsHtml = '';
        if ($t['ist'] !== null) {
            $statsHtml .= $this->renderStatTile($ident . '_ist', 'Ist', $this->fmtNum($t['ist'], 1) . ' °C');
        }
        if ($t['humidity'] !== null) {
            $statsHtml .= $this->renderStatTile($ident . '_humidity', 'Feuchtigkeit', $this->fmtNum($t['humidity'], 1) . ' %');
        }

        $modeHtml = count($t['modeOptions']) > 0
            ? $this->renderModeButtons($ident . '_mode', $t['modeOptions'], $t['mode'])
            : '';

        return <<<THERMO
<div class="thermo-tile">
  <div class="light-name">🌡️ {$nameEsc}</div>
  <div class="thermo-body">
    {$dial}
    <div class="thermo-stats">{$statsHtml}</div>
  </div>
  {$modeHtml}
</div>
THERMO;
    }

    private function renderHumidityPanel(?array $humidity): string
    {
        if ($humidity === null) {
            return '';
        }
        $resultEsc = htmlspecialchars($humidity['result'] !== '' ? $humidity['result'] : '–', ENT_QUOTES);
        $hintBadge = $this->renderStatusBadge('humidity_hint', 'Lüften empfohlen', $humidity['hint'], true);
        $dewHtml = '';
        if ($humidity['dewPointOut'] !== null || $humidity['dewPointIn'] !== null) {
            $dewHtml = '<div class="current-grid">'
                . $this->renderStatTile('humidity_dp_out', 'Taupunkt außen', $this->fmtNum($humidity['dewPointOut'], 1) . ' °C')
                . $this->renderStatTile('humidity_dp_in', 'Taupunkt innen', $this->fmtNum($humidity['dewPointIn'], 1) . ' °C')
                . '</div>';
        }
        return <<<HUMID
<div class="pv-block">
  <div class="pv-title">💧 Luftfeuchtigkeit</div>
  <span id="humidity_result" class="humidity-result">{$resultEsc}</span>
  <div class="status-row">{$hintBadge}</div>
  {$dewHtml}
</div>
HUMID;
    }

    private function renderSonosPanel(?array $sonos): string
    {
        if ($sonos === null) {
            return '';
        }
        $status  = $sonos['status'];
        $playing = $status === 2; // SonosAccess::PLAY
        $volume  = $sonos['isGrouped'] ? $sonos['groupVolume'] : $sonos['volume'];
        $volumeIdent = $sonos['isGrouped'] ? 'sonos_groupvolume' : 'sonos_volume';
        $volumeVal   = $volume ?? 0;

        $coverHtml = $sonos['cover'] !== ''
            ? '<img id="sonos_cover" class="sonos-cover" src="' . htmlspecialchars($sonos['cover'], ENT_QUOTES) . '" onerror="this.style.visibility=\'hidden\'">'
            : '<div id="sonos_cover" class="sonos-cover sonos-cover-empty">🎵</div>';

        $title  = htmlspecialchars($sonos['title'] !== '' ? $sonos['title'] : '–', ENT_QUOTES);
        $artist = htmlspecialchars($sonos['artist'], ENT_QUOTES);

        $muteChecked = $sonos['mute'] ? ' checked' : '';

        $playlistSelect = count($sonos['playlistOptions']) > 0
            ? $this->renderSelect('sonos_playlist', $sonos['playlistOptions'], $sonos['playlist'], 'mode-select')
            : '';
        $groupSelect = count($sonos['groupOptions']) > 0
            ? $this->renderSelect('sonos_group', $sonos['groupOptions'], $sonos['group'], 'mode-select')
            : '';

        return <<<SONOS
<div class="pv-block">
  <div class="pv-title">🎵 Sonos</div>
  <div class="sonos-now-playing">
    {$coverHtml}
    <div class="sonos-track-info">
      <span id="sonos_title" class="sonos-title">{$title}</span>
      <span id="sonos_artist" class="sonos-artist">{$artist}</span>
    </div>
  </div>
  <div class="sonos-controls">
    <button type="button" class="sonos-btn" onclick="requestAction('sonos_status', 0)">⏮</button>
    <button type="button" id="sonos_playpause" class="sonos-btn sonos-btn-main" data-playing="{$this->boolAttr($playing)}" onclick="sonosTogglePlay()">{$this->playPauseIcon($playing)}</button>
    <button type="button" class="sonos-btn" onclick="requestAction('sonos_status', 1)">⏹</button>
    <button type="button" class="sonos-btn" onclick="requestAction('sonos_status', 4)">⏭</button>
    <label class="toggle sonos-mute"><input id="sonos_mute_input" type="checkbox"{$muteChecked} onchange="requestAction('sonos_mute', this.checked)">
      <span class="toggle-track"><span class="toggle-thumb"></span></span></label>
  </div>
  <div class="sonos-volume-row">
    <span id="sonos_volume_label" class="light-name">🔊 {$this->fmtNum($volumeVal)}%</span>
    <input id="sonos_volume_range" type="range" min="0" max="100" value="{$volumeVal}" class="light-slider"
      oninput="setText('sonos_volume_label', '🔊 ' + this.value + '%')" onchange="requestAction('{$volumeIdent}', parseInt(this.value))">
  </div>
  {$playlistSelect}
  {$groupSelect}
</div>
SONOS;
    }

    private function boolAttr(bool $v): string
    {
        return $v ? '1' : '0';
    }

    private function playPauseIcon(bool $playing): string
    {
        return $playing ? '⏸' : '▶';
    }

    private function buildDashboardHTML(): string
    {
        $d = $this->collectData();

        $presenceBadge = $this->renderStatusBadge('presence_badge', '🧍 Präsenz', $d['presence']);

        $statusVarsHtml = '';
        foreach ($d['statusVars'] as $row) {
            $statusVarsHtml .= $this->renderStatusVarTile($row);
        }
        $statusVarsBlock = $statusVarsHtml !== ''
            ? '<div class="pv-block"><div class="pv-title">🔧 Status</div><div class="tile-grid">' . $statusVarsHtml . '</div></div>'
            : '';

        $thermoHtml = '';
        foreach ($d['thermostats'] as $t) {
            $thermoHtml .= $this->renderThermostatTile($t);
        }
        $thermoBlock = $thermoHtml !== ''
            ? '<div class="pv-block"><div class="pv-title">🌡️ Temperatur</div><div class="thermo-stack">' . $thermoHtml . '</div></div>'
            : '';

        $humidityBlock = $this->renderHumidityPanel($d['humidity']);

        $sonosBlock = $this->renderSonosPanel($d['sonos']);

        $lightsHtml = '';
        foreach ($d['lights'] as $light) {
            $lightsHtml .= $this->renderLightTile($light);
        }
        $lightsBlock = $lightsHtml !== ''
            ? '<div class="pv-block"><div class="pv-title">💡 Lichter</div><div class="tile-grid">' . $lightsHtml . '</div></div>'
            : '';

        $shuttersHtml = '';
        foreach ($d['shutters'] as $shutter) {
            $shuttersHtml .= $this->renderShutterTile($shutter);
        }
        $shuttersBlock = $shuttersHtml !== ''
            ? '<div class="pv-block"><div class="pv-title">🪟 Rollläden</div><div class="tile-grid">' . $shuttersHtml . '</div></div>'
            : '';

        $sensorsHtml = '';
        foreach ($d['sensors'] as $sensor) {
            $sensorsHtml .= $this->renderSensorTile($sensor);
        }
        $sensorsBlock = $sensorsHtml !== ''
            ? '<div class="pv-block"><div class="pv-title">📡 Sensoren</div><div class="current-grid">' . $sensorsHtml . '</div></div>'
            : '';

        $roomNameEsc = htmlspecialchars($d['roomName'], ENT_QUOTES);
        $updatedEsc  = htmlspecialchars($d['updated'], ENT_QUOTES);
        $initJson    = json_encode($d);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
html{height:100%}
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-y:auto;overflow-x:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;background:#0d1b2a;color:#d0e8ff;display:flex;flex-direction:column;padding:10px;gap:10px}
.header{display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:14px;font-weight:600;border-bottom:1px solid #1e3a5f;padding-bottom:6px;flex:none}
.updated{font-size:10px;color:#3a5a7a;font-weight:400}
.badge{padding:3px 8px;border-radius:12px;font-size:12px;border:1px solid transparent;white-space:nowrap}
.badge-off{background:#1a2535;border-color:#2a3a50;color:#4a6a8a}
.badge-on{background:#12405a;border-color:#2a7aa0;color:#7ec8f0}
.badge-warn{background:#4a2010;border-color:#8a4020;color:#f08060}
.status-row{display:flex;gap:6px;flex-wrap:wrap;flex:none}
.current-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;flex:none}
.tile-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;flex:none}
.cur-tile{display:flex;flex-direction:column;gap:1px;background:#131f33;border-radius:8px;padding:6px 8px}
.cur-label{font-size:10px;color:#4a6a8a;text-transform:uppercase;letter-spacing:.03em}
.cur-value{font-size:15px;font-weight:700;color:#d0e8ff}
.pv-block{display:flex;flex-direction:column;gap:8px;flex:none;background:#0f1c30;border-radius:10px;padding:8px}
.pv-title{font-size:12px;font-weight:700;color:#d0e8ff}
.mode-select{width:100%;background:#131f33;color:#d0e8ff;border:1px solid #2a3a50;border-radius:6px;padding:6px 8px;font-size:12px}
.light-tile{display:flex;flex-direction:column;gap:4px;background:#131f33;border-radius:8px;padding:6px 8px}
.light-name{font-size:11px;color:#8aa8c8}
.light-value{font-size:10px;color:#4a6a8a;align-self:flex-end}
.light-slider{width:100%;accent-color:#7ec8f0}
.toggle{position:relative;width:44px;height:24px;flex:none;display:inline-block}
.toggle input{opacity:0;position:absolute;width:100%;height:100%;margin:0;cursor:pointer;z-index:1}
.toggle-track{position:absolute;inset:0;background:#1a2535;border:1px solid #2a3a50;border-radius:12px;transition:.15s}
.toggle-thumb{position:absolute;top:2px;left:2px;width:18px;height:18px;background:#8aa8c8;border-radius:50%;transition:.15s}
.toggle input:checked ~ .toggle-track{background:#12405a;border-color:#2a7aa0}
.toggle input:checked ~ .toggle-track .toggle-thumb{transform:translateX(20px);background:#7ec8f0}
.sonos-now-playing{display:flex;gap:10px;align-items:center}
.sonos-cover{width:56px;height:56px;border-radius:8px;object-fit:cover;background:#131f33;flex:none}
.sonos-cover-empty{display:flex;align-items:center;justify-content:center;font-size:24px}
.sonos-track-info{display:flex;flex-direction:column;gap:2px;overflow:hidden}
.sonos-title{font-size:13px;font-weight:700;color:#d0e8ff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sonos-artist{font-size:11px;color:#8aa8c8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sonos-controls{display:flex;align-items:center;gap:8px;justify-content:center}
.sonos-btn{background:#131f33;border:1px solid #2a3a50;color:#d0e8ff;border-radius:8px;font-size:16px;padding:6px 10px;cursor:pointer}
.sonos-btn-main{background:#1e4a6e;border-color:#3a8abf;color:#7ec8f0;font-size:18px}
.sonos-mute{margin-left:auto}
.sonos-volume-row{display:flex;flex-direction:column;gap:4px}
.thermo-stack{display:flex;flex-direction:column;gap:8px}
.thermo-tile{display:flex;flex-direction:column;gap:6px;background:#131f33;border-radius:8px;padding:8px}
.thermo-body{display:flex;align-items:center;gap:10px}
.thermo-stats{display:flex;flex-direction:column;gap:6px;flex:1}
.dial{display:flex;flex-direction:column;align-items:center;gap:2px;width:90px;flex:none;touch-action:none}
.dial-svg{width:76px;height:76px;cursor:pointer}
.dial-value{font-size:14px;font-weight:700;margin-top:-46px;pointer-events:none}
.mode-row{display:flex;gap:4px;flex-wrap:wrap}
.mode-btn{flex:1;min-width:56px;background:#1a2535;border:1px solid #2a3a50;color:#8aa8c8;border-radius:6px;font-size:10px;padding:5px 2px;cursor:pointer}
.mode-btn.mode-active{background:#1e4a6e;border-color:#3a8abf;color:#7ec8f0;font-weight:700}
.humidity-result{font-size:12px;color:#8aa8c8;line-height:1.4}
.light-tile-color{gap:6px}
.light-tile-head{display:flex;justify-content:space-between;align-items:center;gap:6px}
.light-tile-controls{display:flex;align-items:center;gap:8px}
.color-picker{width:26px;height:26px;flex:none;border:1px solid #2a3a50;border-radius:6px;padding:0;background:none;cursor:pointer}
.status-btn-group{display:flex;flex-wrap:wrap;gap:4px}
.status-btn{background:#1a2535;border:1px solid #2a3a50;color:#8aa8c8;border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer}
.status-btn-active{background:#12405a;border-color:#2a7aa0;color:#7ec8f0}
</style>
</head>
<body>
<div class="header">
  <span>🏠 {$roomNameEsc} <span id="updated" class="updated">Stand {$updatedEsc}</span></span>
</div>

<div class="status-row">{$presenceBadge}</div>

{$thermoBlock}
{$humidityBlock}
{$statusVarsBlock}
{$sonosBlock}
{$lightsBlock}
{$shuttersBlock}
{$sensorsBlock}

<script>
(function() {
  var cs = getComputedStyle(document.body);
  var vExtra = (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
  document.body.style.height = 'calc(100% - ' + vExtra + 'px)';
})();

var state = {$initJson};

function setText(id, text) {
  var el = document.getElementById(id);
  if (el) el.textContent = text;
}

function sonosTogglePlay() {
  var btn = document.getElementById('sonos_playpause');
  var playing = btn.getAttribute('data-playing') === '1';
  requestAction('sonos_status', playing ? 3 : 2); // Pause : Play
}

function statusVarSelect(ident, value, btn) {
  requestAction(ident, value);
  var group = btn.parentElement;
  Array.prototype.forEach.call(group.children, function(b) { b.classList.remove('status-btn-active'); });
  btn.classList.add('status-btn-active');
}

var dialRoots = {};

function valueToThumb(value, min, max) {
  var frac = max > min ? Math.max(0, Math.min(1, (value - min) / (max - min))) : 0;
  var angleDeg = -135 + frac * 270;
  var rad = angleDeg * Math.PI / 180;
  return { x: 60 + 45 * Math.sin(rad), y: 60 - 45 * Math.cos(rad) };
}

function initDial(root) {
  var svg = root.querySelector('.dial-svg');
  var thumb = root.querySelector('.dial-thumb');
  var valueEl = root.querySelector('.dial-value');
  var min = parseFloat(root.dataset.min);
  var max = parseFloat(root.dataset.max);
  var step = parseFloat(root.dataset.step);
  var ident = root.dataset.ident;
  var dragging = false;
  var currentValue = parseFloat(root.dataset.value);

  function svgPoint(clientX, clientY) {
    var pt = svg.createSVGPoint();
    pt.x = clientX; pt.y = clientY;
    var ctm = svg.getScreenCTM();
    if (!ctm) return { x: 60, y: 60 };
    return pt.matrixTransform(ctm.inverse());
  }

  function updateVisual(value) {
    var pos = valueToThumb(value, min, max);
    thumb.setAttribute('cx', pos.x.toFixed(1));
    thumb.setAttribute('cy', pos.y.toFixed(1));
    if (valueEl) valueEl.textContent = value.toFixed(1).replace('.', ',') + '°';
  }

  function setFromClient(clientX, clientY) {
    var p = svgPoint(clientX, clientY);
    var dx = p.x - 60, dy = p.y - 60;
    var angleDeg = Math.atan2(dx, -dy) * 180 / Math.PI;
    angleDeg = Math.max(-135, Math.min(135, angleDeg));
    var frac = (angleDeg + 135) / 270;
    var value = min + frac * (max - min);
    value = Math.round(value / step) * step;
    currentValue = value;
    updateVisual(value);
  }

  svg.addEventListener('pointerdown', function(e) {
    dragging = true;
    svg.setPointerCapture(e.pointerId);
    setFromClient(e.clientX, e.clientY);
  });
  svg.addEventListener('pointermove', function(e) {
    if (dragging) setFromClient(e.clientX, e.clientY);
  });
  function endDrag() {
    if (!dragging) return;
    dragging = false;
    requestAction(ident, currentValue);
  }
  svg.addEventListener('pointerup', endDrag);
  svg.addEventListener('pointercancel', endDrag);

  root._updateVisual = updateVisual;
  dialRoots[ident] = root;
}
document.querySelectorAll('.dial').forEach(initDial);

function updateModeButtons(ident, value) {
  var row = document.querySelector('.mode-row[data-ident="' + ident + '"]');
  if (!row) return;
  row.querySelectorAll('.mode-btn').forEach(function(btn) {
    btn.classList.toggle('mode-active', btn.dataset.value === value);
  });
}

window.handleMessage = function(raw) {
  var msg = JSON.parse(raw);
  var key = msg.key, val = msg.value;

  if (key === '__all__') {
    state = val;
    setText('updated', 'Stand ' + val.updated);

    var presenceBadge = document.getElementById('presence_badge');
    if (presenceBadge && val.presence != null) {
      presenceBadge.className = 'badge ' + (val.presence ? 'badge-on' : 'badge-off');
      presenceBadge.textContent = '🧍 Präsenz: ' + (val.presence ? 'an' : 'aus');
    }

    (val.statusVars || []).forEach(function(row) {
      var group = document.getElementById(row.ident + '_group');
      if (group) {
        Array.prototype.forEach.call(group.children, function(b) {
          b.classList.toggle('status-btn-active', b.getAttribute('data-value') === String(row.value));
        });
      }
    });

    (val.thermostats || []).forEach(function(t) {
      var dialRoot = dialRoots[t.ident + '_soll'];
      if (dialRoot && t.soll != null) dialRoot._updateVisual(t.soll);
      if (t.ist != null) setText(t.ident + '_ist', t.ist.toFixed(1).replace('.', ',') + ' °C');
      if (t.humidity != null) setText(t.ident + '_humidity', t.humidity.toFixed(1).replace('.', ',') + ' %');
      if (t.mode != null) updateModeButtons(t.ident + '_mode', t.mode);
    });

    if (val.humidity) {
      setText('humidity_result', val.humidity.result || '–');
      setText('humidity_dp_out', val.humidity.dewPointOut != null ? val.humidity.dewPointOut.toFixed(1).replace('.', ',') + ' °C' : '–');
      setText('humidity_dp_in', val.humidity.dewPointIn != null ? val.humidity.dewPointIn.toFixed(1).replace('.', ',') + ' °C' : '–');
      var hintBadge = document.getElementById('humidity_hint');
      if (hintBadge && val.humidity.hint != null) {
        hintBadge.className = 'badge ' + (val.humidity.hint ? 'badge-warn' : 'badge-off');
        hintBadge.textContent = 'Lüften empfohlen' + (val.humidity.hint ? ': an' : ': aus');
      }
    }

    if (val.sonos) {
      setText('sonos_title', val.sonos.title || '–');
      setText('sonos_artist', val.sonos.artist || '');
      var cover = document.getElementById('sonos_cover');
      if (cover && cover.tagName === 'IMG' && val.sonos.cover) cover.src = val.sonos.cover;
      var playBtn = document.getElementById('sonos_playpause');
      if (playBtn) {
        var playing = val.sonos.status === 2;
        playBtn.setAttribute('data-playing', playing ? '1' : '0');
        playBtn.textContent = playing ? '⏸' : '▶';
      }
      var muteInput = document.getElementById('sonos_mute_input');
      if (muteInput && val.sonos.mute != null) muteInput.checked = val.sonos.mute;
      var volRange = document.getElementById('sonos_volume_range');
      var vol = val.sonos.isGrouped ? val.sonos.groupVolume : val.sonos.volume;
      if (volRange && vol != null) volRange.value = vol;
      if (vol != null) setText('sonos_volume_label', '🔊 ' + vol + '%');
      var playlistSelect = document.getElementById('sonos_playlist_select');
      if (playlistSelect && val.sonos.playlist != null) playlistSelect.value = val.sonos.playlist;
      var groupSelect = document.getElementById('sonos_group_select');
      if (groupSelect && val.sonos.group != null) groupSelect.value = val.sonos.group;
    }

    (val.lights || []).forEach(function(light) {
      var onInput = document.getElementById(light.ident + '_on_input');
      if (onInput && light.on != null) onInput.checked = light.on;
      var range = document.getElementById(light.ident + '_brightness_range');
      var lbl = document.getElementById(light.ident + '_brightness_val');
      if (range && light.brightness != null) range.value = Math.round(light.brightness);
      if (lbl && light.brightness != null) lbl.textContent = Math.round(light.brightness) + '%';
      var colorInput = document.getElementById(light.ident + '_color_input');
      if (colorInput && light.color != null) colorInput.value = '#' + ('000000' + light.color.toString(16)).slice(-6);
    });

    (val.shutters || []).forEach(function(shutter) {
      var range = document.getElementById(shutter.ident + '_range');
      var lbl = document.getElementById(shutter.ident + '_val');
      if (range) range.value = Math.round(shutter.value);
      if (lbl) lbl.textContent = Math.round(shutter.value) + '%';
    });
  }
};
</script>
</body>
</html>
HTML;
    }
}
