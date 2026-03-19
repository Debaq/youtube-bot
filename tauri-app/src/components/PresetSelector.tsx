import { useCallback } from 'react';
import { DJ_PRESETS } from '../types';
import { setPreset } from '../api';

interface PresetSelectorProps {
  onStatusMessage: (msg: string) => void;
  selectedPreset: string;
  onPresetChange: (presetName: string) => void;
}

export default function PresetSelector({
  onStatusMessage,
  selectedPreset,
  onPresetChange,
}: PresetSelectorProps) {
  const selectedIndex = DJ_PRESETS.findIndex((p) => p.name === selectedPreset);
  const activeIndex = selectedIndex >= 0 ? selectedIndex : 0;

  const handleChange = useCallback(
    async (e: React.ChangeEvent<HTMLSelectElement>) => {
      const idx = Number(e.target.value);
      const preset = DJ_PRESETS[idx];
      try {
        await setPreset(preset.name);
        onPresetChange(preset.name);
        onStatusMessage(`Preset cambiado a: ${preset.name}`);
      } catch (err) {
        onStatusMessage(`Error al cambiar preset: ${err}`);
      }
    },
    [onStatusMessage, onPresetChange]
  );

  const activePreset = DJ_PRESETS[activeIndex];

  return (
    <div className="preset-selector">
      <h3 className="preset-selector__title">Preset del DJ</h3>
      <div className="preset-selector__wrapper">
        <select
          className="preset-selector__select"
          value={activeIndex}
          onChange={handleChange}
        >
          {DJ_PRESETS.map((preset, idx) => (
            <option key={preset.name} value={idx}>
              {preset.name}
            </option>
          ))}
        </select>
        <div className="preset-selector__description">
          {activePreset.description}
        </div>
      </div>
    </div>
  );
}
