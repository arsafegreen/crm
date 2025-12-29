# Finance Futurist Redesign Plan

_Last updated: 2025-12-01_

## 1. Guiding Principles
- **Futurist aesthetic**: high contrast, neon accent colors, layered glassmorphism cards, subtle animated glows.
- **Focus on clarity**: metrics stay legible with generous spacing, dynamic pills/badges explain status at a glance.
- **Consistency**: shared tokens across overview, accounts, transactions, and importer screens to avoid visual drift.

## 2. Visual Tokens
| Token | Value | Notes |
| --- | --- | --- |
| Background gradient | `linear-gradient(135deg, #050816, #0f172a 45%, #111827)` | Applied to Finance root container; fallback solid `#0b1020`.
| Surface/Panel | `rgba(255,255,255,0.03)` with 1px border `rgba(148,163,184,0.2)` and blur `backdrop-filter: blur(18px)` | Replaces plain panels.
| Primary accent | `#38bdf8` (cyan) | Buttons, highlights, links.
| Secondary accent | `#a855f7` (violet) | Status pills, charts, sparkline glows.
| Success | `#34d399`; Warning `#fbbf24`; Error `#f87171` | Used in pills, toasts, statuses.
| Typography | Poppins/Space Grotesk (fallback Inter/Arial) | Title weight 600, body 400.
| Shadow | `0 15px 50px rgba(15,23,42,0.6)` | For cards + floating CTAs.

## 3. Components to Restyle
1. **Page shell** (Finance layout wrapper)
   - Add background gradient, subtle grid overlay (using repeating linear-gradient with opacity 0.02).
   - Center content with max-width 1280px and 32px gutters.
2. **Cards / Panels**
   - Apply glass surface + border tokens; add top-right icon or status indicator.
   - Introduce optional “glow” variant for CTA cards (import reminders, watchlist).
3. **Tables** (`finance-table` class)
   - Dark mode colors, zebra row using `rgba(56,189,248,0.04)`.
   - Rounded corners, sticky header with slight glow.
   - Inline badges for status/duplicate warnings.
4. **Pills/Badges**
   - Rebuild `.finance-pill` with gradient backgrounds per status, uppercase micro text.
5. **Buttons**
   - Primary: gradient `linear-gradient(120deg,#38bdf8,#6366f1)` with glow on hover.
   - Ghost buttons: transparent with cyan text/border.
6. **Charts/Sparklines** (if any on overview)
   - Switch to simple line sparkline with cyan stroke + violet fill.

## 4. Page-specific Layout
### Finance Overview (`resources/views/finance/overview.php`)
- Hero header with account totals + CTA “Registrar lançamento” button.
- Grid structure: 2 columns on desktop (3 for cards), responsive stacking.
- Import watchlist card uses neon border and timeline style bullets.

### Finance Accounts (`resources/views/finance/accounts*.php`)
- Account cards show balance, type, quick actions (ver extratos, lançar manual).
- Add neon progress bar representing utilization (if applicable).

### Finance Imports (`resources/views/finance/imports/*.php`)
- Align with new cards/tables; valid rows table already has interactions, just reskin.
- Header includes status pill + gradient border.

## 5. Implementation Checklist
1. Create global SCSS/CSS additions (likely under `public/css/finance.css` or similar) defining new tokens/classes.
2. Update shared layout partial (if exists) to include Finance wrapper class.
3. Refactor each Finance view to use new classes (overview, accounts, cost centers, transactions, importer create/show/index).
4. Adjust buttons/pills across Finance to ensure consistent look; update helper partials if needed.
5. Validate contrast/accessibility (use 4.5:1 for body text).
6. Provide quick before/after screenshots for review once implemented.

## 6. Milestones
1. **Foundation** (Tokens + shared components) — 1 day.
2. **Overview & Accounts** — 1 day.
3. **Transactions & Importer** — 1 day.
4. **Polish + QA** — 0.5 day.

Total estimated effort: ~3.5 days with review cycles.
