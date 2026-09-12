/**
 * Help workspace.
 */
import { __ } from "@wordpress/i18n";
import { BadgeHelp } from "lucide-react";

export default function Placeholder() {
  return (
    <div className="ceog-page">
      <section className="ceog-empty">
        <div className="ceog-empty__icon">
          <BadgeHelp size={25} aria-hidden="true" />
        </div>
        <h2>{__("Order Vanguard help", "coderembassy-order-vanguard")}</h2>
        <p>
          {__(
            "Review the monitoring guidance, privacy controls, and checkout compatibility notes in the plugin documentation.",
            "coderembassy-order-vanguard",
          )}
        </p>
      </section>
    </div>
  );
}
