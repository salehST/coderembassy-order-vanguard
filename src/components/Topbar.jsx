/**
 * Screen title, theme switch, and current user.
 */
import { __ } from "@wordpress/i18n";
import { Menu, Moon, Sun } from "lucide-react";
import { getNavItem } from "../navigation";

const initials = (name) => {
  const parts = String(name || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  if (parts.length > 1) {
    return (parts[0][0] + parts[1][0]).toUpperCase();
  }

  return parts[0]?.slice(0, 2).toUpperCase() || "AD";
};

export default function Topbar({
  route,
  theme,
  boot,
  onToggleTheme,
  user,
  mobileMenuOpen,
  onOpenMenu,
}) {
  const current = getNavItem(route);
  const title = current.label();
  const isDark = theme === "dark";
  const ThemeIcon = isDark ? Sun : Moon;
  const themeLabel = isDark
    ? __("Use light mode", "coderembassy-order-vanguard")
    : __("Use dark mode", "coderembassy-order-vanguard");

  return (
    <header className="ceog-topbar">
      <div className="ceog-topbar__leading">
        <button
          id="ceog-mobile-menu-trigger"
          type="button"
          className="ceog-icon-button ceog-mobile-menu-trigger"
          onClick={onOpenMenu}
          aria-label={__("Open navigation", "coderembassy-order-vanguard")}
          aria-expanded={mobileMenuOpen}
          aria-controls="ceog-mobile-navigation"
          title={__("Open navigation", "coderembassy-order-vanguard")}
        >
          <Menu size={21} aria-hidden="true" />
        </button>
        <div>
          <p className="ceog-topbar__eyebrow">
            {__("Order Vanguard", "coderembassy-order-vanguard")}
          </p>
          <h1 className="ceog-topbar__title">{title}</h1>
        </div>
      </div>

      <div className="ceog-topbar__actions">
        <button
          type="button"
          className="ceog-icon-button"
          onClick={onToggleTheme}
          aria-label={themeLabel}
          aria-pressed={isDark}
          title={themeLabel}
        >
          <ThemeIcon size={18} aria-hidden="true" />
        </button>

        <div className="ceog-user">
          {user?.avatarUrl ? (
            <img className="ceog-user__avatar" src={user.avatarUrl} alt="" />
          ) : (
            <span className="ceog-user__initial" aria-hidden="true">
              {initials(user?.displayName)}
            </span>
          )}
          <span className="ceog-user__name">
            {user?.displayName || __("Admin", "coderembassy-order-vanguard")}
          </span>
        </div>
      </div>
    </header>
  );
}
