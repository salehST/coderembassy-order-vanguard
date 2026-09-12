/**
 * Accessible searchable multi-select with optional manual key entry.
 */
import { useEffect, useMemo, useRef, useState } from "@wordpress/element";
import { __, sprintf } from "@wordpress/i18n";
import { Check, ChevronDown, Plus, Search, X } from "lucide-react";

export default function SearchMultiSelect({
  id,
  label,
  description,
  icon: Icon,
  options = [],
  value = [],
  onChange,
  searchPlaceholder,
  emptyText,
  allowManual = false,
  manualPlaceholder = "",
}) {
  const rootRef = useRef(null);
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [manual, setManual] = useState("");
  const selected = Array.isArray(value) ? value : [];
  const optionRows = Array.isArray(options) ? options : [];

  useEffect(() => {
    const closeOutside = (event) => {
      if (rootRef.current && !rootRef.current.contains(event.target)) {
        setOpen(false);
      }
    };

    document.addEventListener("mousedown", closeOutside);

    return () => document.removeEventListener("mousedown", closeOutside);
  }, []);

  const filteredOptions = useMemo(() => {
    const needle = search.trim().toLowerCase();

    return optionRows.filter((option) => {
      if (!needle) {
        return true;
      }

      return [option.label, option.value].some((item) =>
        String(item || "")
          .toLowerCase()
          .includes(needle),
      );
    });
  }, [optionRows, search]);

  const labels = useMemo(() => {
    const output = {};
    optionRows.forEach((option) => {
      output[option.value] = option.label;
    });

    return output;
  }, [optionRows]);

  const toggle = (key) => {
    onChange(
      selected.includes(key)
        ? selected.filter((item) => item !== key)
        : [...selected, key],
    );
  };

  const addManual = (event) => {
    event.preventDefault();
    const key = manual
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9_-]/g, "");

    if (key && !selected.includes(key)) {
      onChange([...selected, key]);
    }

    setManual("");
  };

  return (
    <div className="ceog-choice-field" ref={rootRef}>
      <div className="ceog-choice-field__heading">
        {Icon && <Icon size={17} aria-hidden="true" />}
        <div>
          <span id={id + "-label"}>{label}</span>
          <small>{description}</small>
        </div>
      </div>

      {selected.length > 0 && (
        <div
          className="ceog-choice-tokens"
          aria-label={__("Selected values", "coderembassy-order-vanguard")}
        >
          {selected.map((key) => (
            <span className="ceog-choice-token" key={key}>
              <span>{labels[key] || key}</span>
              <code>{key}</code>
              <button
                type="button"
                onClick={() => toggle(key)}
                aria-label={sprintf(
                  /* translators: %s: selected role or payment-method key. */
                  __("Remove %s", "coderembassy-order-vanguard"),
                  key,
                )}
                title={sprintf(
                  /* translators: %s: selected role or payment-method key. */
                  __("Remove %s", "coderembassy-order-vanguard"),
                  key,
                )}
              >
                <X size={13} aria-hidden="true" />
              </button>
            </span>
          ))}
        </div>
      )}

      <div className="ceog-choice-menu">
        <div className="ceog-choice-search">
          <Search size={16} aria-hidden="true" />
          <input
            id={id}
            type="search"
            role="combobox"
            value={search}
            placeholder={searchPlaceholder}
            aria-labelledby={id + "-label"}
            aria-expanded={open}
            aria-controls={id + "-options"}
            aria-autocomplete="list"
            onFocus={() => setOpen(true)}
            onChange={(event) => {
              setSearch(event.target.value);
              setOpen(true);
            }}
            onKeyDown={(event) => {
              if (event.key === "Escape") {
                setOpen(false);
              }
            }}
          />
          <button
            type="button"
            onClick={() => setOpen(!open)}
            aria-label={__("Toggle options", "coderembassy-order-vanguard")}
            title={__("Toggle options", "coderembassy-order-vanguard")}
          >
            <ChevronDown size={16} aria-hidden="true" />
          </button>
        </div>

        {open && (
          <div id={id + "-options"} className="ceog-choice-options">
            {filteredOptions.length > 0 ? (
              filteredOptions.map((option) => {
                const checked = selected.includes(option.value);

                return (
                  <label
                    key={option.value}
                    className={checked ? "is-selected" : ""}
                  >
                    <input
                      type="checkbox"
                      checked={checked}
                      onChange={() => toggle(option.value)}
                    />
                    <span>
                      <strong>{option.label}</strong>
                      <code>{option.value}</code>
                    </span>
                    {checked && <Check size={15} aria-hidden="true" />}
                  </label>
                );
              })
            ) : (
              <p>{emptyText}</p>
            )}
          </div>
        )}
      </div>

      {allowManual && (
        <form className="ceog-choice-manual" onSubmit={addManual}>
          <input
            type="text"
            value={manual}
            placeholder={manualPlaceholder}
            autoCapitalize="none"
            autoCorrect="off"
            spellCheck="false"
            onChange={(event) => setManual(event.target.value)}
          />
          <button
            type="submit"
            className="ceog-button"
            disabled={!manual.trim()}
          >
            <Plus size={15} aria-hidden="true" />
            {__("Add key", "coderembassy-order-vanguard")}
          </button>
        </form>
      )}
    </div>
  );
}
