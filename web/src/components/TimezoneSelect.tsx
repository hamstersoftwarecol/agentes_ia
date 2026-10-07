"use client";

import { Select } from "./ui";

/** Zonas horarias del navegador; garantiza que el valor actual siempre esté en la lista. */
export function TimezoneSelect({
  value,
  onChange,
  error,
  disabled,
}: {
  value: string;
  onChange: (value: string) => void;
  error?: string;
  disabled?: boolean;
}) {
  const zones = Intl.supportedValuesOf("timeZone");
  if (value && !zones.includes(value)) zones.unshift(value);

  return (
    <Select
      label="Zona horaria"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      error={error}
      disabled={disabled}
    >
      {zones.map((zone) => (
        <option key={zone} value={zone}>
          {zone.replaceAll("_", " ")}
        </option>
      ))}
    </Select>
  );
}
