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
        $this->RegisterPropertyInteger('var_ventilation', 0);
        $this->RegisterPropertyInteger('sonos_instance', 0);

        $this->RegisterPropertyString('lights', '[]');
        $this->RegisterPropertyString('shutters', '[]');
        $this->RegisterPropertyString('sensors', '[]');

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

        $lights   = json_decode($this->ReadPropertyString('lights'), true) ?: [];
        $shutters = json_decode($this->ReadPropertyString('shutters'), true) ?: [];
        $sensors  = json_decode($this->ReadPropertyString('sensors'), true) ?: [];

        $hasAnything = $this->ReadPropertyInteger('var_presence') > 0
            || $this->ReadPropertyInteger('var_ventilation') > 0
            || $this->ReadPropertyInteger('sonos_instance') > 0
            || count($lights) > 0 || count($shutters) > 0 || count($sensors) > 0;

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
            if ($Ident === 'vent_mode') {
                $this->forwardToProperty('var_ventilation', $Value, $Ident);
                return;
            }

            if (strpos($Ident, 'sonos_') === 0) {
                $this->forwardSonosAction(substr($Ident, strlen('sonos_')), $Value, $Ident);
                return;
            }

            if (strpos($Ident, 'light_') === 0) {
                $this->forwardListAction('lights', (int) substr($Ident, strlen('light_')), $Value, $Ident);
                return;
            }

            if (strpos($Ident, 'shutter_') === 0) {
                $this->forwardListAction('shutters', (int) substr($Ident, strlen('shutter_')), $Value, $Ident);
                return;
            }

            $this->LogMessage("RoomDashboard RequestAction: unknown ident {$Ident}", KL_WARNING);
        } catch (\Throwable $e) {
            $this->LogMessage('RoomDashboard RequestAction ' . $Ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    private function forwardToProperty(string $prop, $value, string $pushIdent): void
    {
        $targetId = $this->ReadPropertyInteger($prop);
        if ($targetId <= 0) {
            return;
        }
        $cast = $this->castToVarType($targetId, $value);
        RequestAction($targetId, $cast);
        $this->pushValue($pushIdent, $cast);
    }

    private function forwardSonosAction(string $key, $value, string $pushIdent): void
    {
        $sonosId = $this->ReadPropertyInteger('sonos_instance');
        if ($sonosId <= 0) {
            return;
        }
        $map = [
            'status' => 'Status', 'volume' => 'Volume', 'groupvolume' => 'GroupVolume',
            'mute' => 'Mute', 'playlist' => 'Playlist', 'group' => 'MemberOfGroup',
        ];
        if (!isset($map[$key])) {
            return;
        }
        $targetId = $this->sonosVarId($sonosId, $map[$key]);
        if ($targetId <= 0) {
            return;
        }
        $cast = $this->castToVarType($targetId, $value);
        RequestAction($targetId, $cast);
        $this->pushValue($pushIdent, $cast);
    }

    private function forwardListAction(string $listProp, int $index, $value, string $pushIdent): void
    {
        $rows = json_decode($this->ReadPropertyString($listProp), true) ?: [];
        if (!isset($rows[$index]['variable'])) {
            return;
        }
        $targetId = (int) $rows[$index]['variable'];
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

    /** Resolves a Sonos instance's own variable ID by ident (Status, Volume, Playlist, ...). */
    private function sonosVarId(int $sonosId, string $ident): int
    {
        if ($sonosId <= 0) {
            return 0;
        }
        $id = @IPS_GetObjectIDByIdent($ident, $sonosId);
        return $id ?: 0;
    }

    private function collectData(): array
    {
        $presenceId = $this->ReadPropertyInteger('var_presence');
        $presence   = $presenceId > 0 ? (bool) $this->readVarById($presenceId) : null;

        $ventId    = $this->ReadPropertyInteger('var_ventilation');
        $ventValue = $ventId > 0 ? $this->readVarById($ventId) : null;
        $ventAssoc = $ventId > 0 ? $this->variableAssociations($ventId) : [];

        $sonos   = $this->collectSonos();
        $lights  = $this->collectLights();
        $shutters = $this->collectShutters();
        $sensors = $this->collectSensors();

        return [
            'roomName'    => IPS_GetName($this->InstanceID),
            'presence'    => $presence,
            'ventValue'   => $ventValue === null ? null : (string) $ventValue,
            'ventOptions' => $ventAssoc,
            'sonos'       => $sonos,
            'lights'      => $lights,
            'shutters'    => $shutters,
            'sensors'     => $sensors,
            'updated'     => date('d.m. H:i'),
        ];
    }

    private function collectSonos(): ?array
    {
        $sonosId = $this->ReadPropertyInteger('sonos_instance');
        if ($sonosId <= 0 || !@IPS_InstanceExists($sonosId)) {
            return null;
        }

        $statusId      = $this->sonosVarId($sonosId, 'Status');
        $volumeId      = $this->sonosVarId($sonosId, 'Volume');
        $muteId        = $this->sonosVarId($sonosId, 'Mute');
        $playlistId    = $this->sonosVarId($sonosId, 'Playlist');
        $groupId       = $this->sonosVarId($sonosId, 'MemberOfGroup');
        $groupVolumeId = $this->sonosVarId($sonosId, 'GroupVolume');
        $artistId      = $this->sonosVarId($sonosId, 'Artist');
        $titleId       = $this->sonosVarId($sonosId, 'Title');
        $albumId       = $this->sonosVarId($sonosId, 'Album');
        $coverId       = $this->sonosVarId($sonosId, 'CoverURL');

        // Mirrors the Sonos module's own logic: once a player has joined a
        // group (MemberOfGroup != 0), volume control shifts to GroupVolume.
        $isGrouped = $groupId > 0 && (int) $this->readVarById($groupId) !== 0;

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
            'artist'         => $artistId > 0 ? (string) $this->readVarById($artistId) : '',
            'title'          => $titleId > 0 ? (string) $this->readVarById($titleId) : '',
            'album'          => $albumId > 0 ? (string) $this->readVarById($albumId) : '',
            'cover'          => $coverId > 0 ? (string) $this->readVarById($coverId) : '',
        ];
    }

    private function collectLights(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('lights'), true) ?: [] as $i => $row) {
            $varId = (int) ($row['variable'] ?? 0);
            if ($varId <= 0 || !@IPS_VariableExists($varId)) {
                continue;
            }
            $isBool = (int) IPS_GetVariable($varId)['VariableType'] === 0;
            $raw    = GetValue($varId);
            $out[]  = [
                'ident'  => 'light_' . $i,
                'name'   => $row['name'] !== '' ? $row['name'] : 'Licht',
                'isBool' => $isBool,
                'on'     => $isBool ? (bool) $raw : ((float) $raw > 0),
                'value'  => $isBool ? null : (float) $raw,
            ];
        }
        return $out;
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
                'name'  => $row['name'] !== '' ? $row['name'] : 'Rollladen',
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
                'name'  => $row['name'] !== '' ? $row['name'] : 'Sensor',
                'type'  => $type,
                'bool'  => in_array($type, self::SENSOR_BOOL_TYPES, true) ? (bool) $raw : null,
                'value' => in_array($type, self::SENSOR_BOOL_TYPES, true) ? null : (float) $raw,
            ];
        }
        return $out;
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
        if ($light['isBool']) {
            $checked = $light['on'] ? ' checked' : '';
            return "<div class=\"light-tile\"><span class=\"light-name\">{$nameEsc}</span>"
                . "<label class=\"toggle\"><input id=\"{$ident}_input\" type=\"checkbox\"{$checked} onchange=\"requestAction('{$ident}', this.checked)\">"
                . '<span class="toggle-track"><span class="toggle-thumb"></span></span></label></div>';
        }
        $val = (int) round($light['value']);
        return "<div class=\"light-tile\"><span class=\"light-name\">{$nameEsc}</span>"
            . "<input id=\"{$ident}_range\" type=\"range\" min=\"0\" max=\"100\" value=\"{$val}\" class=\"light-slider\" "
            . "oninput=\"document.getElementById('{$ident}_val').textContent=this.value+'%'\" onchange=\"requestAction('{$ident}', parseInt(this.value))\">"
            . "<span id=\"{$ident}_val\" class=\"light-value\">{$val}%</span></div>";
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

        $ventBlock = '';
        if ($this->ReadPropertyInteger('var_ventilation') > 0) {
            $ventSelect = count($d['ventOptions']) > 0
                ? $this->renderSelect('vent_mode', $d['ventOptions'], $d['ventValue'])
                : "<span class=\"cur-value\">" . htmlspecialchars($d['ventValue'] ?? '–', ENT_QUOTES) . '</span>';
            $ventBlock = '<div class="pv-block"><div class="pv-title">🌬️ Lüftung</div>' . $ventSelect . '</div>';
        }

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
</style>
</head>
<body>
<div class="header">
  <span>🏠 {$roomNameEsc} <span id="updated" class="updated">Stand {$updatedEsc}</span></span>
</div>

<div class="status-row">{$presenceBadge}</div>

{$ventBlock}
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

    var ventSelect = document.getElementById('vent_mode_select');
    if (ventSelect && val.ventValue != null) ventSelect.value = val.ventValue;

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
      if (light.isBool) {
        var input = document.getElementById(light.ident + '_input');
        if (input) input.checked = light.on;
      } else {
        var range = document.getElementById(light.ident + '_range');
        var lbl = document.getElementById(light.ident + '_val');
        if (range) range.value = Math.round(light.value);
        if (lbl) lbl.textContent = Math.round(light.value) + '%';
      }
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
