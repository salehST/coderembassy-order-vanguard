/**
 * Store API rate-limit, batch, and advanced controls.
 */
import { useEffect, useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import {
  Blocks,
  CircleCheck,
  Gauge,
  ListChecks,
  LockKeyhole,
  Save,
  ShieldCheck,
  TriangleAlert,
} from "lucide-react";
import ToggleField from "../components/ToggleField";

const makeDraft = (settings = {}) => ({
  rate_limit_enabled: settings.rate_limit_enabled !== false,
  rate_limit_limit: String(settings.rate_limit_limit || 25),
  rate_limit_seconds: String(settings.rate_limit_seconds || 10),
  strict_session: Boolean(settings.strict_session),
  emergency_lockdown: Boolean(settings.emergency_lockdown),
});

export default function StoreApiGuard({
  payload,
  loading,
  settingsBusy,
  onSave,
}) {
  const settings = payload?.settings || {};
  const meta = payload?.meta || {};
  const [draft, setDraft] = useState(makeDraft(settings));
  const [strictAcknowledged, setStrictAcknowledged] = useState(false);
  const [emergencyAcknowledged, setEmergencyAcknowledged] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    setDraft(makeDraft(payload?.settings));
    setStrictAcknowledged(false);
    setEmergencyAcknowledged(false);
    setSaved(false);
  }, [payload?.settings]);

  const update = (key, value) => {
    setDraft((current) => ({ ...current, [key]: value }));
    setSaved(false);
  };

  const save = async () => {
    const ok = await onSave({
      rate_limit_enabled: draft.rate_limit_enabled,
      rate_limit_limit: Number(draft.rate_limit_limit),
      rate_limit_seconds: Number(draft.rate_limit_seconds),
      strict_session: draft.strict_session,
      emergency_lockdown: draft.emergency_lockdown,
    });
    setSaved(Boolean(ok));
  };

  if (loading || !payload) {
    return (
      <div className="ceog-loading" aria-busy="true">
        <div className="ceog-skeleton ceog-skeleton--store-api" />
        <div className="ceog-settings-grid">
          <div className="ceog-skeleton ceog-skeleton--stat" />
          <div className="ceog-skeleton ceog-skeleton--stat" />
        </div>
      </div>
    );
  }

  return (
    <div className="ceog-page ceog-store-api-page">
      <header className="ceog-page__header">
        <div>
          <p className="ceog-section-kicker">
            {__("Store API protection", "coderembassy-order-vanguard")}
          </p>
          <h2>{__("Store API Guard", "coderembassy-order-vanguard")}</h2>
          <p>
            {__(
              "Protect cart mutations, checkout requests, and every operation embedded in a Store API batch.",
              "coderembassy-order-vanguard",
            )}
          </p>
        </div>
        <span
          className={
            meta.safeMode || !meta.enforcing
              ? "ceog-status ceog-status--warning"
              : "ceog-status ceog-status--success"
          }
        >
          <ShieldCheck size={15} aria-hidden="true" />
          {meta.safeMode
            ? __("Safe Mode", "coderembassy-order-vanguard")
            : meta.enforcing
            ? __("Enforcing", "coderembassy-order-vanguard")
            : __("Monitoring", "coderembassy-order-vanguard")}
        </span>
      </header>

      {meta.safeMode && (
        <div className="ceog-warning" role="status">
          <TriangleAlert size={18} aria-hidden="true" />
          <span>
            {__(
              "Safe Mode keeps Store API blocking and Order Vanguard rate limiting suspended while logging stays active.",
              "coderembassy-order-vanguard",
            )}
          </span>
        </div>
      )}

      {settings.emergency_lockdown && (
        <div className="ceog-emergency-banner" role="alert">
          <TriangleAlert size={20} aria-hidden="true" />
          <div>
            <strong>
              {__(
                "Emergency Lockdown is enabled",
                "coderembassy-order-vanguard",
              )}
            </strong>
            <span>
              {__(
                "Store API checkout returns 404 whenever Order Vanguard is enforcing. Disable this after the active attack has passed.",
                "coderembassy-order-vanguard",
              )}
            </span>
          </div>
        </div>
      )}

      <div className="ceog-settings-grid">
        <section className="ceog-settings-panel ceog-store-api-panel">
          <div className="ceog-settings-panel__heading">
            <Gauge size={20} aria-hidden="true" />
            <div>
              <h3>
                {__("Cart mutation rate limit", "coderembassy-order-vanguard")}
              </h3>
              <p>
                {__(
                  "Uses WooCommerce's native Store API limiter for POST cart and batch traffic.",
                  "coderembassy-order-vanguard",
                )}
              </p>
            </div>
          </div>

          <ToggleField
            id="ceog-store-rate-limit"
            label={__(
              "Enable Order Vanguard rate limiting",
              "coderembassy-order-vanguard",
            )}
            description={__(
              "Configured in Monitor mode and activated only while Enforce mode is effective.",
              "coderembassy-order-vanguard",
            )}
            checked={draft.rate_limit_enabled}
            onChange={(value) => update("rate_limit_enabled", value)}
          />

          <div className="ceog-number-grid">
            <label className="ceog-field" htmlFor="ceog-rate-limit-count">
              <span>{__("Requests", "coderembassy-order-vanguard")}</span>
              <input
                id="ceog-rate-limit-count"
                type="number"
                min="1"
                max="1000"
                value={draft.rate_limit_limit}
                onChange={(event) =>
                  update("rate_limit_limit", event.target.value)
                }
                disabled={!draft.rate_limit_enabled}
              />
            </label>
            <label className="ceog-field" htmlFor="ceog-rate-limit-seconds">
              <span>
                {__("Time window (seconds)", "coderembassy-order-vanguard")}
              </span>
              <input
                id="ceog-rate-limit-seconds"
                type="number"
                min="1"
                max="3600"
                value={draft.rate_limit_seconds}
                onChange={(event) =>
                  update("rate_limit_seconds", event.target.value)
                }
                disabled={!draft.rate_limit_enabled}
              />
            </label>
          </div>

          <div className="ceog-limiter-status">
            <div>
              <span>{__("Cart and batch", "coderembassy-order-vanguard")}</span>
              <strong>
                {draft.rate_limit_enabled
                  ? __(
                      "Order Vanguard configured",
                      "coderembassy-order-vanguard",
                    )
                  : __(
                      "Order Vanguard disabled",
                      "coderembassy-order-vanguard",
                    )}
              </strong>
            </div>
            <div>
              <span>{__("Place order", "coderembassy-order-vanguard")}</span>
              <strong>
                {meta.nativeRateLimitEnabled
                  ? __(
                      "WooCommerce limiter enabled",
                      "coderembassy-order-vanguard",
                    )
                  : __(
                      "WooCommerce limiter not enabled",
                      "coderembassy-order-vanguard",
                    )}
              </strong>
            </div>
          </div>
        </section>

        <section className="ceog-settings-panel ceog-store-api-panel">
          <div className="ceog-settings-panel__heading">
            <Blocks size={20} aria-hidden="true" />
            <div>
              <h3>{__("Batch inspection", "coderembassy-order-vanguard")}</h3>
              <p>
                {__(
                  "Prevents protected operations from being hidden inside one Store API request.",
                  "coderembassy-order-vanguard",
                )}
              </p>
            </div>
          </div>

          <div className="ceog-batch-state">
            <CircleCheck size={20} aria-hidden="true" />
            <div>
              <strong>
                {meta.batchInspectionActive
                  ? __("Inspection active", "coderembassy-order-vanguard")
                  : __("Inspection unavailable", "coderembassy-order-vanguard")}
              </strong>
              <span>
                {__(
                  "Every embedded path and method is checked before WooCommerce executes the batch.",
                  "coderembassy-order-vanguard",
                )}
              </span>
            </div>
          </div>

          <dl className="ceog-store-api-facts">
            <div>
              <dt>
                <ListChecks size={16} aria-hidden="true" />
                {__("Maximum operations", "coderembassy-order-vanguard")}
              </dt>
              <dd>25</dd>
            </div>
            <div>
              <dt>{__("Malformed batches", "coderembassy-order-vanguard")}</dt>
              <dd>
                {meta.enforcing
                  ? __("Rejected", "coderembassy-order-vanguard")
                  : __("Logged", "coderembassy-order-vanguard")}
              </dd>
            </div>
            <div>
              <dt>
                {__("Whitelist precedence", "coderembassy-order-vanguard")}
              </dt>
              <dd>{__("Always", "coderembassy-order-vanguard")}</dd>
            </div>
          </dl>
        </section>

        <section className="ceog-settings-panel ceog-settings-panel--wide ceog-store-api-advanced">
          <div className="ceog-settings-panel__heading">
            <LockKeyhole size={20} aria-hidden="true" />
            <div>
              <h3>
                {__("Advanced request controls", "coderembassy-order-vanguard")}
              </h3>
              <p>
                {__(
                  "Opt-in compatibility-sensitive rules for stores under elevated attack pressure.",
                  "coderembassy-order-vanguard",
                )}
              </p>
            </div>
          </div>

          <div className="ceog-environment-row">
            <div>
              <span>
                {__("WooCommerce Checkout", "coderembassy-order-vanguard")}
              </span>
              <strong>
                {meta.checkoutBlockDetected
                  ? __("Checkout block detected", "coderembassy-order-vanguard")
                  : __(
                      "Classic checkout detected",
                      "coderembassy-order-vanguard",
                    )}
              </strong>
            </div>
            <div>
              <span>
                {__("Effective behavior", "coderembassy-order-vanguard")}
              </span>
              <strong>
                {meta.enforcing
                  ? __("Blocking enabled", "coderembassy-order-vanguard")
                  : __("Logging only", "coderembassy-order-vanguard")}
              </strong>
            </div>
          </div>

          <div className="ceog-advanced-setting">
            <ToggleField
              id="ceog-store-strict-session"
              label={__(
                "Strict Session Requirement",
                "coderembassy-order-vanguard",
              )}
              description={__(
                "Requires an existing WooCommerce session for Store API cart mutations and batch equivalents.",
                "coderembassy-order-vanguard",
              )}
              checked={draft.strict_session}
              onChange={(value) => update("strict_session", value)}
              disabled={!draft.strict_session && !strictAcknowledged}
              tone="warning"
            />
            <div className="ceog-risk ceog-risk--warning">
              <TriangleAlert size={18} aria-hidden="true" />
              <p>
                {__(
                  "This may break headless, custom, mobile-app, and some express checkout flows. Use Monitor Mode first.",
                  "coderembassy-order-vanguard",
                )}
              </p>
            </div>
            <label className="ceog-acknowledgement">
              <input
                type="checkbox"
                checked={strictAcknowledged}
                onChange={(event) =>
                  setStrictAcknowledged(event.target.checked)
                }
              />
              <span>
                {__("I understand the risks", "coderembassy-order-vanguard")}
              </span>
            </label>
          </div>

          <div className="ceog-advanced-setting">
            <ToggleField
              id="ceog-store-emergency-lockdown"
              label={__(
                "Emergency Store API Checkout Lockdown",
                "coderembassy-order-vanguard",
              )}
              description={__(
                "Returns 404 for direct and batch-wrapped Store API checkout operations.",
                "coderembassy-order-vanguard",
              )}
              checked={draft.emergency_lockdown}
              onChange={(value) => update("emergency_lockdown", value)}
              disabled={
                meta.checkoutBlockDetected ||
                (!draft.emergency_lockdown && !emergencyAcknowledged)
              }
              tone="danger"
            />
            <div
              className="ceog-risk ceog-risk--danger"
              role={draft.emergency_lockdown ? "alert" : undefined}
            >
              <TriangleAlert size={18} aria-hidden="true" />
              <p>
                {meta.checkoutBlockDetected
                  ? __(
                      "Emergency Lockdown cannot be enabled while the WooCommerce Checkout block is active.",
                      "coderembassy-order-vanguard",
                    )
                  : __(
                      "For classic-checkout stores under active attack only. This stops Store API checkout and must never be used as normal protection.",
                      "coderembassy-order-vanguard",
                    )}
              </p>
            </div>
            <label className="ceog-acknowledgement">
              <input
                type="checkbox"
                checked={emergencyAcknowledged}
                onChange={(event) =>
                  setEmergencyAcknowledged(event.target.checked)
                }
                disabled={meta.checkoutBlockDetected}
              />
              <span>
                {__("I understand the risks", "coderembassy-order-vanguard")}
              </span>
            </label>
          </div>
        </section>
      </div>

      <div className="ceog-save-bar">
        {saved && (
          <span className="ceog-saved" role="status">
            <CircleCheck size={15} aria-hidden="true" />
            {__("Store API settings saved", "coderembassy-order-vanguard")}
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
            : __("Save Store API settings", "coderembassy-order-vanguard")}
        </button>
      </div>
    </div>
  );
}
