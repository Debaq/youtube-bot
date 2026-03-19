import { useState, useCallback } from 'react';
import { DJ_PRESETS } from '../types';
import { setPreset } from '../api';

interface PresetSelectorProps {
  onStatusMessage: (msg: string) => void;
}

export default function PresetSelector({ onStatusMessage }: PresetSelectorProps) {
  const [selectedIndex, setSelectedIndex] = useState(0);

  const handleChange = useCallback(
    async (e: React.ChangeEvent<HTMLSelectElement>) => {
      const idx = Number(e.target.value);
      setSelectedIndex(idx);
      const preset = DJ_PRESETS[idx];
      try {
        await setPreset(preset.name);
        onStatusMessage(`Preset cambiado a: ${preset.name}`);
      } catch (err) {
        onStatusMessage(`Error al cambiar preset: ${err}`);
      }
    },
    [onStatusMessage]
  );

  const activePreset = DJ_PRESETS[selectedIndex];

  return (
    <div className="preset-selector">
      <h3 className="preset-selector__title">Preset del DJ</h3>
      <div className="preset-selector__wrapper">
        <select
          className="preset-selector__select"
          value={selectedIndex}
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
