/**
 * Accessible binary setting with a stable switch footprint.
 */
export default function ToggleField({
  id,
  label,
  description,
  checked,
  onChange,
  disabled = false,
  tone = "default",
}) {
  return (
    <label
      className={
        "ceog-toggle-row ceog-toggle-row--" +
        tone +
        (disabled ? " is-disabled" : "")
      }
      htmlFor={id}
    >
      <span className="ceog-toggle-row__copy">
        <strong>{label}</strong>
        <small>{description}</small>
      </span>
      <input
        id={id}
        type="checkbox"
        checked={checked}
        onChange={(event) => onChange(event.target.checked)}
        disabled={disabled}
      />
      <span className="ceog-switch" aria-hidden="true" />
    </label>
  );
}
