# System - ArticleGuard

**ArticleGuard** is a lightweight, state-based system plugin for **Joomla 4, 5, and 6** that protects articles from unauthorized editing, saving, and publishing state changes across both the Backend and Frontend.

Designed specifically for multi-author portals, editorial teams, and community-driven websites, ArticleGuard enforces strict editorial workflows—preventing accidental or unauthorized modifications once an article reaches a protected state (e.g., *Published* or *Archived*), while keeping privileged user groups completely unrestricted.

---

## Key Features

- **State-Based Editorial Protection:** Lock articles based on their current or target publishing state (*Published*, *Unpublished*, *Archived*, *Trashed*).
- **Dual-Mode Architecture (Traditional & Workflow):** Seamlessly intercepts and validates both standard Joomla article states and native **Joomla Workflow** transitions (both inside the edit form and in bulk list actions).
- **Multi-Layer Security (UI + Server-Side POST):**
  - Hides frontend edit buttons (`access-edit`) in article and category views.
  - Switches edit forms to read-only mode, hides save toolbars, and displays a clear warning notice.
  - Enforces server-side validation on `onContentBeforeSave`, `onContentBeforeChangeState`, and `onWorkflowBeforeTransition`, throwing a `403 Forbidden` exception on unauthorized POST attempts.
- **Workflow Form Recovery:** Automatically restores the real database state and current workflow stage if a form save error occurs.
- **Role & Group Exemptions:** Super Users (`core.admin`) are always exempt, and administrators can define additional privileged user groups free from plugin restrictions.
- **Optional Audit Logging:** Logs denied edit and state-change attempts (including context, article ID, state, and user ID) to `plg_articleguard.log.php`.
- **Modern Joomla Architecture:** Built using the PSR-4 namespace standard, Dependency Injection container (`services/provider.php`), and native Joomla Event Subscribers.

---

## Requirements

- **Joomla:** 4.x / 5.x / 6.x
- **PHP:** 8.1 or higher

---

## Installation

1. Download the latest `articleguard.zip` package from the [Releases](https://github.com) page.
2. Log in to your Joomla Administrator panel.
3. Navigate to **System -> Install -> Extensions**.
4. Upload and install `articleguard.zip`.
5. Go to **System -> Manage -> Plugins**, search for **System - ArticleGuard**, configure its parameters, and enable the plugin.

---

## Configuration

Open the **System - ArticleGuard** plugin settings to configure:

1. **Protected Article States:** Select the article states that should be locked against editing and state transitions (e.g., *Published*, *Archived*).
2. **Groups free from restrictions:** Select user groups that are exempt from plugin restrictions and retain full editing rights regardless of article state.
3. **Enable Decision Logging:** Toggle logging of unauthorized edit or transition attempts to the Joomla logs directory (`plg_articleguard.log.php`).

---

## Repository Structure

```text
├── language/
│   ├── en-GB/
│   │   ├── en-GB.plg_system_articleguard.ini
│   │   └── en-GB.plg_system_articleguard.sys.ini
│   └── pl-PL/
│       ├── pl-PL.plg_system_articleguard.ini
│       └── pl-PL.plg_system_articleguard.sys.ini
├── services/
│   └── provider.php
├── src/
│   └── Extension/
│       └── Articleguard.php
├── articleguard.php
├── articleguard.xml
├── LICENSE
├── README.md
└── update.xml
```

---

## License

This project is licensed under the **GNU General Public License version 2 or later** — see the [LICENSE](LICENSE) file for details.

---

## Emergency Recovery Procedure

If an external extension conflict occurs or an administrator without Super User privileges inadvertently locks all article states, you can disable the plugin directly at the database level:

1. Log in to your server database via **phpMyAdmin**.
2. Locate the `#__extensions` table (where `#__` represents your site's custom database prefix).
3. Find the entry matching `element = "articleguard"` and `folder = "system"`.
4. Edit the row and change the **`enabled`** column value from `1` to **`0`**.
5. Save changes. The plugin is immediately disabled at the core level.

