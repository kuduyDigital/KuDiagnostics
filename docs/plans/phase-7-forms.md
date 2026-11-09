# Phase 7c — Forms plugins (plan)

Research: `RESEARCH.md` §10b. Sources read 2026-11-09: Contact Form 7 6.2.4.1 (6.1.7 for WordPress 6.9), Flamingo 2.6.4, WPForms Lite 2.0.4 and Fluent Forms 6.2.17. Gravity Forms was read from its docs only. Status: **proposed, waiting for approval of D15–D17**. No code yet.

Exit gate (`ROADMAP.md`, forms part):
- Forms and entries can be read through each plugin's own API, on both test pairs.
- Entry changes can be undone, and permanent delete is never offered.
- Visitors' personal data is masked by default and never appears in audit rows.
- Changing where submissions are emailed needs confirmation.
- Secrets in plugin settings are never returned.

## What the plugins offer

| Plugin | Forms | Entries on the site | Own abilities |
|---|---|---|---|
| Contact Form 7 | post type `wpcf7_contact_form`; `wpcf7_save_contact_form()` | none (needs Flamingo) | none |
| Flamingo (CF7 add-on) | — | post type `flamingo_inbound`; `Flamingo_Inbound_Message` | none |
| WPForms Lite | post type `wpforms`; `wpforms()->obj( 'form' )` | **none** (Pro only) | reads always on; writes off by default |
| Fluent Forms | own tables; `fluentFormApi()` | own tables; `SubmissionService` | **off by default** (option `_fluentform_mcp_settings`) |
| Gravity Forms (paid) | `GFAPI` | `GFAPI` | not checked (docs only) |

## Decision D15 — how KUDUY reads and writes forms data

| Option | What it means | For | Against |
|---|---|---|---|
| **A. Own tools on each plugin's public PHP API, one shared tool set (recommended)** | One adapter per plugin behind the same `kuduy-forms-*` tools: CF7 and Flamingo classes, `wpforms()->obj( 'form' )`, `fluentFormApi()` and `SubmissionService`, and `GFAPI`. Permission checks use the plugin's own functions: `wpcf7_*` and `flamingo_*` meta caps, `wpforms_current_user_can()`, Fluent `Acl::hasPermission( $perm, $form_id )`, and Gravity capabilities | Works on every site, with no setting to switch on. Same rules as each plugin's admin screens. One tool set for Claude | We keep five adapters up to date |
| B. Plugins' own abilities where they are on, own tools for the rest | WPForms read abilities; Fluent abilities when the site turned them on; own adapters for CF7, Flamingo, Gravity and Fluent when its abilities are off | Uses the plugins' own code | Fluent's are off on most sites. WPForms Lite returns only "safe" settings. Two paths per plugin, and results differ by site |
| C. Read only | Forms and entries read through A; nothing changes | Smallest risk | Inbox work (mark read, trash spam) is still done by hand |

## Decision D16 — what may change

| Option | Entries | Forms |
|---|---|---|
| **A. Inbox actions with undo; CF7 form edits; mail routing confirm-first (recommended)** | Mark read or unread, star, trash and restore, and spam or not spam where the plugin keeps that on the site. Add an internal note (Fluent, Gravity). **Permanent delete not offered.** Flamingo spam and not-spam are confirm-first when Akismet is active, because Flamingo then sends the message to Akismet | Create and update CF7 forms (title, form markup, messages, additional settings) through `wpcf7_save_contact_form()`. Changing `mail` / `mail_2` (recipient, sender, Cc/Bcc headers) is **confirm-first and needs `kuduy:admin`**: the result lists old and new routing and does nothing until `confirm: true`. WPForms, Fluent and Gravity forms are read only for now |
| B. As A, but mail routing refused | as A | CF7 edits without `mail` / `mail_2` |
| C. Nothing changes | read only | read only |

## Decision D17 — visitors' personal data in entries

| Option | What it means |
|---|---|
| **A. Mask by field type (recommended, like D12)** | Masked by default: values of email, phone, name and address fields, IP, user agent, uploaded-file URLs, and the Flamingo `from` address. Other values, such as a message text, are shown. `include_pii: true` plus the plugin's entry-edit permission shows everything. Audit rows and snapshot input never hold entry values (`MasksAuditArgs`) |
| B. Mask every value | Every field value is masked unless `include_pii`. Safer, but Claude can't read or sort an inbox without asking for full data |

Scopes: the existing `kuduy:read`, `kuduy:write` and `kuduy:admin` are kept, as in D13.

## Tools (module `src/Modules/Forms/`, one adapter per plugin; each tool is available when at least one supported plugin is active)

Every call names the plugin (`cf7`, `wpforms`, `fluentform`, `gravityforms`) and the form id. Entries of CF7 forms come from Flamingo.

| # | Tool | Scope | Capability (per plugin) | Undo |
|---|---|---|---|---|
| 1 | `kuduy-forms-status`: plugins found, versions, where entries are stored (none for CF7 without Flamingo or for WPForms Lite), plugin abilities on or off | read | `edit_posts` | — |
| 2 | `kuduy-forms-list`: forms the user may read: plugin, id, title, number of entries, shortcode | read | CF7 `wpcf7_read_contact_form`; WPForms `view_form_single`; Fluent `fluentform_forms_manager` or `fluentform_entries_viewer`; Gravity `gravityforms_edit_forms` or `gravityforms_view_entries` | — |
| 3 | `kuduy-forms-get`: fields (name, label, type, required, choices), messages and confirmations, mail and notification routing (recipient, sender, Cc/Bcc). Never integration keys | read | as 2, per form | — |
| 4 | `kuduy-forms-entries`: entries of one form, filtered by status, date and search, with paging; values masked per D17 | read | Flamingo `flamingo_edit_inbound_messages`; Fluent `fluentform_entries_viewer`; Gravity `gravityforms_view_entries` | — |
| 5 | `kuduy-forms-entry-get`: one entry with every field, its status and notes (note authors' emails masked) | read | as 4, per entry | — |
| 6 | `kuduy-forms-entry-update`: `read`, `starred`, `status` (`active`, `trash`, `spam`) on one entry | write | Flamingo `flamingo_edit_inbound_message`, `flamingo_delete_inbound_message` (trash) and `flamingo_spam_inbound_message`; Fluent `fluentform_manage_entries`; Gravity `gravityforms_edit_entries` and `gravityforms_delete_entries` (trash) | previous read, starred and status values |
| 7 | `kuduy-forms-entry-note`: add an internal note (Fluent, Gravity) | write | Fluent `fluentform_manage_entries`; Gravity `gravityforms_edit_entry_notes` | undo deletes the note |
| 8 | `kuduy-forms-cf7-save`: create or patch a CF7 form; `mail` / `mail_2` confirm-first under `kuduy:admin` (D16 A) | write (admin for mail) | `wpcf7_edit_contact_forms` (create), `wpcf7_edit_contact_form` (id) | previous properties restored through `wpcf7_save_contact_form()`; a created form is deleted |

Not offered: permanent delete of entries or forms, exports, payments and transactions (Fluent, WPForms), integration and add-on settings, form submission on a visitor's behalf.

Jobs: none in this step. Bulk trash would break the rule that nothing which removes things runs in bulk (D9). Bulk mark-read can come later.

**Gravity Forms:** built and tested only once a licensed ZIP is available locally (git-ignored, like Elementor Pro: `KUDUY_LOCAL_PLUGINS`). Until then its adapter is not shipped, and `FEATURES.md` says **Blocked**.

## Redaction and personal data

- Never returned:
  - CF7 option `wpcf7` (reCAPTCHA, Turnstile, Stripe, Brevo keys);
  - Fluent and WPForms integration keys;
  - Gravity add-on settings;
  - Flamingo `_akismet` and `_recaptcha` details (kept to a spam yes/no).
- Entry values are masked by field type (D17). Fluent field types are known from `form_fields`; CF7 types come from `scan_form_tags()`, matched to Flamingo fields by name; Gravity uses field `type`.
- Audit rows of tools 6 to 8 mask entry values and mail routing as `[personal data]`, except for the ids.

## Tests (Playground)

The 7.1 pair runs `KUDUY_PLUGINS=contact-form-7@6.2.4.1,flamingo@2.6.4,fluentform@6.2.17,wpforms-lite@2.0.4`. The 6.9 pair runs the same with `contact-form-7@6.1.7`. Test entries are created through the plugins' own APIs (`Flamingo_Inbound_Message::add()`, Fluent's submission model).

- F1: status and list across the four plugins; forms a user may not read are left out.
- F2: get, covering CF7 tags, Fluent fields, and WPForms fields and notifications; no integration keys in any result.
- F3: entries and entry-get for Flamingo and Fluent. Masking by type; full data with `include_pii` and the right permission; refused without it.
- F4: entry-update and undo (read, starred, trash, restore, spam), and Flamingo spam confirm-first when Akismet is active.
- F5: notes and undo (Fluent).
- F6: CF7 save: create, patch, undo; mail routing confirm-first and refused without `kuduy:admin`; form markup filtered for a user without `unfiltered_html`.
- F7: refusals, covering an author or editor without the plugin's permission, Fluent form scope, an unknown plugin or form, and permanent delete not reachable.
- CI: one forms job per WordPress pair.

Then a staging run with claude.ai. Staging has no forms plugin today, so CF7 + Flamingo and Fluent Forms would be installed there for the check, then removed again. I'll ask before installing.

## Order

1. Shared module, adapters for CF7 + Flamingo, status, list and get (F1, F2).
2. Entries, entry-get and entry-update for Flamingo (F3, F4).
3. Fluent Forms adapter: forms, entries, update, notes (F3–F5, F7).
4. WPForms Lite adapter: forms only (F1, F2).
5. CF7 save (F6), CI job.
6. Release and staging check. Gravity Forms follows when a licensed ZIP is available.
