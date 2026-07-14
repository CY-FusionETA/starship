# Starship — Procurement Workflow by Role

How the four business roles move a purchase from request → order → goods-in, and
where each one touches the system. Status names in `code font` are the actual
values Starship stores.

---

## 1. Roles at a glance

| Business role | System role (gate) | Can do | Seeded today? |
|---|---|---|---|
| **Project Staff** | `staff` | Raise/edit MRs (draft), add catalogue + one-off items, upload delivery orders | ✅ `staff@fusioneta.com` |
| **Project Manager** | *(approval = `admin` gate)* | Approve / reject MRs | ⚠️ No dedicated PM login — approval is currently **superadmin-only** |
| **Procurement Executive** | `purchaser` | Raise POs from approved MRs, pick supplier, send to Xero | ⚠️ Role exists in code, **no user seeded** |
| **Finance / AP** | `ap` | Receive goods (DO upload + OCR), confirm receipt, invoice matching in Xero | ⚠️ Role exists in code, **no user seeded** |
| Superadmin (Simon) | `admin` | Everything above + settings, Xero connect, deletes | ✅ `simon@fusioneta.com` |

> **Gap to close before go-live:** only `admin` and `staff` are seeded. `purchaser`
> and `ap` are recognised by the route guards but have no users yet, and MR approval
> is hard-gated to `admin`. To give Project Managers / Procurement / Finance their
> own logins, seed users with those roles (and, if PMs should approve without being
> superadmin, widen the approve/reject gate from `admin` to include a manager role).

---

## 2. End-to-end flow

```
 PROJECT STAFF        PROJECT MANAGER       PROCUREMENT EXEC        FINANCE / AP
 ─────────────        ───────────────       ────────────────        ────────────
 Raise MR ──────────► Review in /approvals
 (draft)               │
                       ├─ Reject ─► (rejected, back to staff)
                       │
                       └─ Approve ─► (approved) ──► Raise PO(s) ──────► Goods arrive
                                                     pick supplier +      with DO
                                                     qty per line          │
                                                     (issued) ──► Xero     Upload DO photo
                                                     auto-sync             → Gemini OCR
                                                                           → relink to PO
                                                                           → Confirm receipt
                                                                              │
                                                                           PO line status →
                                                                           partially/fully_received
                                                                              │
                                                                           Invoice matched
                                                                           in Xero (PO already there)
```

---

## 3. Step by step

### Stage 1 — Request (Project Staff)
- **Where:** `Requisitions → New` (`/requisitions/new`).
- Search the catalogue and add parts; use **＋ New product** to add a reusable
  catalogue item, or **＋ One-off** for a free-text line used only on this MR.
- Fill MR No., Project, Requested by, dates. Save → MR is created as `draft`.
- Staff can **Edit** or **Delete** their own MR while it is still `draft`
  (delete is blocked once a PO has been raised from it).

### Stage 2 — Approval (Project Manager / currently Superadmin)
- **Where:** the **Approvals inbox** (`/approvals`) lists every `draft` MR.
- **Approve** → MR becomes `approved` and is now eligible for a PO.
- **Reject** → MR is sent back; staff can edit and resubmit.
- *Today this gate is `admin`-only — see the gap note above.*

### Stage 3 — Purchase Order (Procurement Executive)
- **Where:** open the approved MR → **Create PO** (`/requisitions/{id}/create-po`).
- Choose the **supplier** and the quantity to order per line. One MR can spawn
  several POs (e.g. split across suppliers).
- MR line status moves to `partially_ordered` → `fully_ordered` as lines are covered.
- The new PO is `issued` and **auto-pushes to Xero** as a Purchase Order
  (`xero_po_id` recorded). Superadmin can force a re-sync from the PO page.
- PO header (number / date) is editable; a PO can be deleted only if nothing has
  been received against it (deleting releases the ordered qty back to the MR).

### Stage 4 — Goods receipt (Finance / AP)
- Supplier delivers with a **Delivery Order (DO)**.
- **Where:** `Delivery Orders → New` (`/delivery-orders/new`) — upload the DO photo.
  **Gemini OCR** reads the supplier, DO number and line items.
- **Relink** the DO to the correct PO if auto-match missed, then **Confirm**.
- Confirming posts received quantities against the PO lines; PO line status moves to
  `partially_received` → `fully_received`.
- **WhatsApp shortcut:** a registered sender can photograph a DO/invoice to the
  Wazzup hotline number → OCR → instant reply with the parsed details (no login).

### Stage 5 — Invoice & finance close (Finance / AP)
- Because the PO already lives in Xero, Finance matches the supplier invoice against
  it in Xero for 3-way match (PO ↔ goods received ↔ invoice) and payment.

---

## 4. Quick reference — who touches what

| Screen | Staff | PM | Procurement | Finance |
|---|:--:|:--:|:--:|:--:|
| `/requisitions` (create / edit draft) | ✅ | ✅ | ✅ | – |
| `/approvals` (approve / reject) | – | ✅* | – | – |
| `/requisitions/{id}/create-po` | ✅ | – | ✅ | – |
| `/purchase-orders` (Xero sync) | view | view | ✅ | view |
| `/delivery-orders` (upload / confirm) | ✅ | – | ✅ | ✅ |
| `/settings`, Xero connect, deletes | – | – | – | admin only |

\* PM approval is currently served by the `admin` gate; seed a manager role to
separate it from superadmin.

---

*Generated 2026-07-14. Reflects the role gates in `index.php` / `src/Auth.php` as of
commit `7af6e6a`.*
