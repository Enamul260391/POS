<!-- BEGIN:nextjs-agent-rules -->

# This is NOT the Next.js you know

This version has breaking changes — APIs, conventions, and file structure may all differ from your training data. Read the relevant guide in `node_modules/next/dist/docs/` (resolved from this file's directory; in monorepos the `next` package may not be visible from the repo root) before writing any code. Heed deprecation notices.

This block is written and re-added by `next dev` — verify at `node_modules/next/dist/server/lib/generate-agent-files.js`. Removing it from a diff only re-creates the uncommitted change; committing it with your work keeps the tree clean.

<!-- END:nextjs-agent-rules -->
# AGENTS.md

## 1. Project Technology

This project uses the following technology stack:

* **Frontend:** Next.js
* **Backend:** Laravel
* **Database:** MySQL
* **API:** REST API
* **Frontend Language:** TypeScript
* **Backend Language:** PHP

Do not introduce a different framework or replace any of the above technologies without explicit permission.

---

## 2. Frontend and Backend Separation

The project must maintain a strict separation between frontend and backend.

```text
Pos/
├── frontend/
└── backend/
```

### Frontend

The `frontend/` folder contains the complete Next.js application.

### Backend

The `backend/` folder contains the Laravel application.

### Development Order

Development must follow this order:

1. Complete the frontend first.
2. Use mock/static/local data where necessary during frontend development.
3. Complete the backend after the frontend structure and UI are sufficiently complete.
4. Connect frontend and backend through REST APIs.
5. Frontend must never directly access the MySQL database.

```text
Next.js Frontend
       │
       │ REST API
       ▼
Laravel Backend
       │
       ▼
     MySQL
```

Do not add backend implementation just because a frontend feature currently uses mock data.

When working on the frontend phase, keep the implementation frontend-focused unless backend work is explicitly requested.

---

## 3. User-Friendly Design

The application must always be designed with usability as a priority.

All UI/UX decisions should follow these principles:

* The interface must be easy to understand.
* Common actions should require as few steps as reasonably possible.
* Navigation must be clear and predictable.
* Forms should be simple and well organized.
* Important actions must have clear visual feedback.
* Loading states must be handled properly.
* Empty states must be handled properly.
* Error messages must be clear and useful.
* Confirmation should be required for destructive actions when appropriate.
* Tables and forms should remain readable and practical.
* The POS interface must be optimized for fast cashier operation.
* Avoid unnecessary animations, dialogs, clicks, and navigation.
* Responsive behavior must be considered where applicable.

Do not sacrifice usability merely to make the implementation technically simpler.

---

## 4. Strict Change Scope

When the user asks to modify a specific file, component, page, feature, section, or location, **change only that requested area**.

Do not make unrelated changes elsewhere in the project.

For example, if the user says:

> Change the sidebar background.

Only the sidebar background should be changed.

Do not simultaneously change:

* Topbar
* Dashboard layout
* Typography
* Colors elsewhere
* Other components
* Unrelated CSS
* Other pages
* Business logic

unless explicitly requested.

### Related Changes

If the requested change technically requires a related change elsewhere, do not make that additional change automatically.

First ask for permission and clearly explain:

1. What additional change is required.
2. Why it is required.
3. Which file/component will be affected.

Only make the related change after receiving permission.

### Exception

If an additional change is purely internal and unavoidable to correctly implement the explicitly requested change, keep it as minimal as possible and do not alter unrelated behavior or design.

When uncertain about the scope, ask before changing.

---

## 5. Performance and Speed

The application must always be designed and implemented with speed and efficiency in mind.

This applies to:

* UI design
* Component architecture
* State management
* API communication
* Data fetching
* Rendering
* Database queries
* Code structure

### Frontend

Prefer:

* Efficient rendering
* Appropriate Server/Client Component usage
* Lazy loading where appropriate
* Pagination for large datasets
* Debounced search where appropriate
* Minimal unnecessary API requests
* Minimal unnecessary re-renders
* Reusable components
* Efficient state management

Avoid:

* Unnecessary client-side rendering
* Unnecessary global state
* Repeated API calls
* Large components containing unrelated logic
* Heavy libraries when a lightweight solution is sufficient
* Unnecessary animations
* Blocking UI operations

### POS Performance

The POS screen is a high-priority performance area.

Common operations such as:

* Product search
* Barcode entry
* Adding products to cart
* Changing quantity
* Removing items
* Applying discounts
* Opening payment
* Completing a sale

should feel immediate and require minimal interaction.

---

## 6. Code Quality

Code should be:

* Clean
* Readable
* Maintainable
* Modular
* Reusable
* Consistent

Avoid unnecessary complexity.

Do not duplicate logic when it can reasonably be shared.

Do not leave dead or superseded code behind. When code is replaced, the old implementation must be removed entirely — see **§7 (Replace, Do Not Accumulate)**.

Keep components, services, utilities, hooks, and business logic separated according to their responsibilities.

Do not create abstractions without a practical reason.

---

## 7. Existing Code Preservation

Before modifying existing code:

1. Understand the current implementation.
2. Identify the exact requested change.
3. Preserve existing functionality.
4. Avoid unnecessary refactoring.
5. Avoid changing working code unrelated to the request.

Do not rewrite an existing component simply because another implementation style is preferred.

Preserve the current architecture unless the user explicitly requests architectural changes.

### Replace, Do Not Accumulate

Whenever a change is made in any file, component, page, function, or section:

1. **Delete the old code being replaced.**
2. **Write the new code in its place.**

The previous implementation must not be kept anywhere in the codebase. Specifically, do not:

* Comment out the old code
* Leave the old code behind in the same file
* Keep both the old and new implementations side by side
* Add comments such as `// old code`, `// previous version`, `// kept for reference`, or similar
* Wrap the old code in a disabled block, unused flag, or "just in case" fallback
* Leave dead, unreachable, unused, or superseded functions, variables, imports, styles, or types behind

Only the current, active implementation should remain. Version history is the responsibility of Git, not of commented-out code.

A replaced implementation must be fully removed — partially removing it and leaving the remainder is not acceptable.

**Exception (applies both ways):**

* This rule covers the code that is actually being replaced. Unrelated working code must still be preserved as described above in this section.
* If the old code is deliberately required as a fallback or toggle, ask for permission first and clearly explain why.

---

## 8. No Unrequested Features

Do not add features that were not requested.

Examples:

* Do not add new pages without permission.
* Do not add new libraries without a reason.
* Do not add new business logic without permission.
* Do not redesign unrelated sections.
* Do not change existing workflows without permission.

If a potentially useful improvement is identified, mention it separately and ask whether it should be implemented.

---

## 9. API and Backend Integration

When backend integration begins, all frontend-backend communication must use REST APIs.

The frontend must not:

* Access MySQL directly.
* Contain database credentials.
* Implement backend-only business rules.
* Bypass Laravel APIs.

The Laravel backend is responsible for:

* Authentication
* Authorization
* Validation
* Business rules
* Database operations
* Transaction handling
* Stock calculations
* Payment validation
* Security-sensitive operations

The Next.js frontend is responsible for:

* UI
* User interaction
* Client-side presentation logic
* Form interaction
* API communication
* Local UI state

---

## 10. Change Discipline

Before making changes, always determine:

```text
What was requested?
        ↓
Where is the requested change?
        ↓
What is the minimum change required?
        ↓
Will another area be affected?
        ↓
If yes → Ask permission
        ↓
Implement only the approved scope
```

The goal is to make the **smallest correct change** that fully satisfies the user's request.

---

## 11. General Rule

When there is a conflict between:

* adding unnecessary functionality,
* making broad changes,
* refactoring unrelated code,
* or strictly following the user's requested scope,

**strictly follow the user's requested scope.**

When requirements are unclear, ask before making assumptions.
