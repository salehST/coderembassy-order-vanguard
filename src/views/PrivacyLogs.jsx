/**
 * Privacy and local log-storage settings.
 */
import { useEffect, useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import {
  Clock3,
  Database,
  Network,
  Save,
  ShieldCheck,
  Trash2,
  TriangleAlert,
} from "lucide-react";
import ToggleField from "../components/ToggleField";

const makeDraft = (settings = {}) => ({
  log_full_ip: Boolean(settings.log_full_ip),
  log_retention_days: Number(settings.log_retention_days || 30),
  trusted_proxy: settings.trusted_proxy || "none",
  delete_data_on_uninstall: Boolean(settings.delete_data_on_uninstall),
});

export default function PrivacyLogs({ payload, settingsBusy, onSave }) {
  const settings = payload?.settings || {};
  const [draft, setDraft] = useState(makeDraft(settings));
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    setDraft(makeDraft(payload?.settings));
    setSaved(false);
  }, [payload?.settings]);

  const update = (key, value) => {
    setDraft((current) => ({ ...current, [key]: value }));
    setSaved(false);
  };

  const save = async () => {
    setSaved(Boolean(await onSave(draft)));
  };

  return (
    <div className="ceog-page ceog-settings-stack">
      <header className="ceog-page__header">
        <div>
          <p className="ceog-section-kicker">
            {__("Privacy & Logs", "coderembassy-order-vanguard")}
          </p>
          <h2>
            {__("Local, privacy-first records", "coderembassy-order-vanguard")}
          </h2>
          <p>
            {__(
              "Order Vanguard stores protection events on this WordPress site and sends nothing to external services.",
              "coderembassy-order-vanguard",
            )}
          </p>
        </div>
        <span className="ceog-status ceog-status--success">
          <ShieldCheck size={15} aria-hidden="true" />
          {__("Local only", "coderembassy-order-vanguard")}
        </span>
      </header>

      <div className="ceog-settings-grid">
        <section className="ceog-settings-panel">
          <div className="ceog-settings-panel__heading">
            <Database size={20} aria-hidden="true" />
            <div>
              <h3>{__("IP display", "coderembassy-order-vanguard")}</h3>
              <p>
                {__(
                  "Repeat-attacker correlation always uses a one-way HMAC hash.",
                  "coderembassy-order-vanguard",
                )}
              </p>
            </div>
          </div>
          <ToggleField
            id="ceog-log-full-ip"
            label={__("Store full IP addresses", "coderembassy-order-vanguard")}
            description={
              draft.log_full_ip
                ? __(
                    "Full addresses will be visible in new log rows.",
                    "coderembassy-order-vanguard",
                  )
                : __(
                    "IP addresses are anonymized for display.",
                    "coderembassy-order-vanguard",
                  )
            }
            checked={draft.log_full_ip}
            onChange={(value) => update("log_full_ip", value)}
            tone={draft.log_full_ip ? "warning" : "default"}
          />
          {draft.log_full_ip && (
            <div className="ceog-risk ceog-risk--warning" role="status">
              <TriangleAlert size={18} aria-hidden="true" />
              <p>
                {__(
                  "Full IP addresses are personal data. Enable this only when your privacy policy and legal basis cover the additional storage.",
                  "coderembassy-order-vanguard",
                )}
              </p>
            </div>
          )}
        </section>

        <section className="ceog-settings-panel">
          <div className="ceog-settings-panel__heading">
            <Network size={20} aria-hidden="true" />
            <div>
              <h3>{__("Trusted proxy", "coderembassy-order-vanguard")}</h3>
              <p>
                {__(
                  "Proxy headers remain untrusted unless you explicitly select your infrastructure.",
                  "coderembassy-order-vanguard",
                )}
              </p>
            </div>
          </div>
          <label className="ceog-field" htmlFor="ceog-trusted-proxy">
            <span>{__("Client IP source", "coderembassy-order-vanguard")}</span>
            <select
              id="ceog-trusted-proxy"
              value={draft.trusted_proxy}
              onChange={(event) => update("trusted_proxy", event.target.value)}
            >
              <option value="none">
                {__(
                  "Server connection (recommended)",
                  "coderembassy-order-vanguard",
                )}
              </option>
              <option value="cloudflare">
                {__(
                  "Cloudflare CF-Connecting-IP",
                  "coderembassy-order-vanguard",
                )}
              </option>
              <option value="xff">
                {__("X-Forwarded-For first hop", "coderembassy-order-vanguard")}
              </option>
            </select>
          </label>
          <p className="ceog-field-note">
            {__(
              "Selecting the wrong proxy can let attackers spoof their source address.",
              "coderembassy-order-vanguard",
            )}
          </p>
        </section>

        <section className="ceog-settings-panel">
          <div className="ceog-settings-panel__heading">
            <Clock3 size={20} aria-hidden="true" />
            <div>
              <h3>{__("Retention", "coderembassy-order-vanguard")}</h3>
              <p>
                {__(
                  "Daily pruning keeps the attack-volume table bounded.",
                  "coderembassy-order-vanguard",
                )}
              </p>
            </div>
          </div>
          <label className="ceog-field" htmlFor="ceog-log-retention">
            <span>{__("Retention period", "coderembassy-order-vanguard")}</span>
            <select
              id="ceog-log-retention"
              value={draft.log_retention_days}
              onChange={(event) =>
                update("log_retention_days", Number(event.target.value))
              }
            >
              <option value={7}>
                {__("7 days", "coderembassy-order-vanguard")}
              </option>
              <option value={30}>
                {__("30 days", "coderembassy-order-vanguard")}
              </option>
              <option value={90}>
                {__("90 days", "coderembassy-order-vanguard")}
              </option>
            </select>
          </label>
          <p className="ceog-field-note">
            {__(
              "Daily background pruning permanently removes activity rows older than the selected period.",
              "coderembassy-order-vanguard",
            )}
          </p>
        </section>

        <section className="ceog-settings-panel">
          <div className="ceog-settings-panel__heading">
            <Trash2 size={20} aria-hidden="true" />
            <div>
              <h3>{__("Uninstall cleanup", "coderembassy-order-vanguard")}</h3>
              <p>
                {__(
                  "Choose whether uninstalling Order Vanguard removes its local records.",
                  "coderembassy-order-vanguard",
                )}
              </p>
            </div>
          </div>
          <ToggleField
            id="ceog-delete-data"
            label={__(
              "Delete all data on uninstall",
              "coderembassy-order-vanguard",
            )}
            description={__(
              "Removes the log table and plugin options permanently.",
              "coderembassy-order-vanguard",
            )}
            checked={draft.delete_data_on_uninstall}
            onChange={(value) => update("delete_data_on_uninstall", value)}
            tone="danger"
          />
        </section>
      </div>

      <div className="ceog-save-bar">
        {saved && (
          <span className="ceog-saved" role="status">
            <ShieldCheck size={15} aria-hidden="true" />
            {__("Privacy settings saved", "coderembassy-order-vanguard")}
          </span>
        )}
        <button
          type="button"
          className="ceog-button ceog-button--primary"
          onClick={save}
          disabled={settingsBusy}
        >
          <Save size={16} aria-hidden="true" />
          {settingsBusy
            ? __("Saving...", "coderembassy-order-vanguard")
            : __("Save privacy settings", "coderembassy-order-vanguard")}
        </button>
      </div>
    </div>
  );
}
