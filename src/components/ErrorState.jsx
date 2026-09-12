/**
 * Friendly recoverable REST error.
 */
import { __ } from "@wordpress/i18n";
import { RefreshCw, TriangleAlert } from "lucide-react";

export default function ErrorState({ message, onRetry }) {
  return (
    <div className="ceog-error" role="alert">
      <TriangleAlert size={20} aria-hidden="true" />
      <div>
        <strong>
          {__(
            "Order Vanguard could not connect",
            "coderembassy-order-vanguard",
          )}
        </strong>
        <p>{message}</p>
      </div>
      <button type="button" className="ceog-button" onClick={onRetry}>
        <RefreshCw size={16} aria-hidden="true" />
        {__("Retry", "coderembassy-order-vanguard")}
      </button>
    </div>
  );
}
