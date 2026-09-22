# Workspace Rules for Sunday School Project

This file contains rules and guidelines that AI agents and developers MUST follow when editing this workspace.

---

## 1. Intelligent Search Standard
All search elements added to this project (inputs, selectors, autocomplete suggestions) MUST be intelligent.
- Standard search utility functions are centralized in [search_intelligent.js](file:///Users/peterfayez/Documents/Sunday%20School/sunday-school.rf.gd/js/search_intelligent.js).
- When implementing a search, include this JS file or copy the functions to the local page context.
- Score search queries using `getMatchScore(item, query, matchFields)` and sort results in descending order by `_score`.

---

## 2. Page & Styling Consistency — Uncle Dashboard Design System

All UI screens, components, modals, and views across the Sunday School project MUST strictly adhere to the styling and interaction standards of [uncle/dashboard/index.php](file:///Users/peterfayez/Documents/Sunday%20School/sunday-school.rf.gd/uncle/dashboard/index.php). All other legacy or divergent styles must be migrated to match this specification.

### 2.1 Typography: Cairo Only
- **Strictly Cairo**: Use `'Cairo', sans-serif` as the universal font across all pages, headings, inputs, modals, and buttons.
- **Do NOT use Baloo Bhaijaan**: Remove any previous references to `Baloo Bhaijaan` or other fonts.
- Include Google Fonts Cairo:
  ```html
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap">
  ```

### 2.2 Coupon Icon Standard
- **Static Star Icon**: The coupons icon is strictly static and MUST always be `<i class="fas fa-star"></i>`.
- Accompany with coupon tokens:
  - Color: `var(--coupon)` (`#8b5cf6`) / `var(--coupon-dark)` (`#7c3aed`)
  - Background: `var(--coupon-bg)` (`#ede9fe` in light, `rgba(139, 92, 246, .15)` in dark)
  - Gradient: `var(--coupon-grad)` (`linear-gradient(135deg, #8b5cf6, #7c3aed)`)

### 2.3 Color Palette & Design Tokens
```css
:root {
    /* Brand */
    --brand: #5b6cf5;
    --brand-dark: #4354e8;
    --brand-light: #a5b0ff;
    --brand-bg: #eef0ff;
    --brand-glow: rgba(91, 108, 245, .18);

    /* Semantic */
    --success: #10b981;
    --success-dark: #059669;
    --success-bg: #d1fae5;
    --danger: #ef4444;
    --danger-dark: #dc2626;
    --danger-bg: #fee2e2;
    --warning: #f59e0b;
    --warning-dark: #d97706;
    --warning-bg: #fef3c7;
    --coupon: #8b5cf6;
    --coupon-dark: #7c3aed;
    --coupon-bg: #ede9fe;
    --coupon-grad: linear-gradient(135deg, #8b5cf6, #7c3aed);

    /* Surfaces & Text (Light Mode) */
    --bg: #f3f4f9;
    --surface: #ffffff;
    --surface-2: #f7f8fc;
    --surface-3: #eceef7;
    --border: rgba(91, 108, 245, .12);
    --border-solid: #e4e6f0;
    --text: #1a1d2e;
    --text-2: #4b5068;
    --text-3: #8b90a8;

    /* Elevation & Shadows */
    --shadow-sm: 0 2px 8px -2px rgba(0, 0, 0, .07);
    --shadow-md: 0 8px 24px -4px rgba(0, 0, 0, .10);
    --shadow-lg: 0 20px 48px -8px rgba(0, 0, 0, .14);
    --shadow-xl: 0 32px 64px -12px rgba(0, 0, 0, .18);

    /* Border Radius */
    --r-xs: 6px;
    --r-sm: 10px;
    --r-md: 14px;
    --r-lg: 18px;
    --r-xl: 24px;
    --r-2xl: 32px;
    --r-full: 9999px;

    /* Motion */
    --ease: cubic-bezier(.4, 0, .2, 1);
    --spring: cubic-bezier(.16, 1, .3, 1);
    --t: .22s;
}

[data-theme="dark"] {
    --bg: #0f1117;
    --surface: #181b26;
    --surface-2: #1e2132;
    --surface-3: #252840;
    --border: rgba(91, 108, 245, .18);
    --border-solid: #2a2d42;
    --text: #e8eaf6;
    --text-2: #9299be;
    --text-3: #565c7a;
    --shadow-sm: 0 2px 8px -2px rgba(0, 0, 0, .4);
    --shadow-md: 0 8px 24px -4px rgba(0, 0, 0, .5);
    --shadow-lg: 0 20px 48px -8px rgba(0, 0, 0, .6);
    --shadow-xl: 0 32px 64px -12px rgba(0, 0, 0, .7);
    --brand-bg: rgba(91, 108, 245, .15);
    --success-bg: rgba(16, 185, 129, .15);
    --danger-bg: rgba(239, 68, 68, .15);
    --warning-bg: rgba(245, 158, 11, .15);
    --coupon-bg: rgba(139, 92, 246, .15);
}
```

### 2.4 Borderless Aesthetic & No Heavy Strokes
- **No Harsh Outlines / Strokes**: Do not apply heavy 2px or dark solid outlines around cards or buttons.
- Modern elevation is achieved using subtle background contrasts (`--surface`, `--surface-2`, `--surface-3`) and soft shadow layers.
- Form inputs and subtle dividers use minimal 1px `var(--border-solid)` or translucent `var(--border)`.

### 2.5 Buttons & Form Controls
- **Primary Buttons (`.btn`)**:
  - Background: `linear-gradient(135deg, var(--brand), var(--brand-dark))`
  - Color: `#ffffff`
  - Border: `none`
  - Inset highlight & shadow:
    `box-shadow: 0 1px 0 rgba(255, 255, 255, .22) inset, 0 8px 18px rgba(79, 70, 229, .16);`
  - Hover: `transform: translateY(-2px); box-shadow: 0 1px 0 rgba(255, 255, 255, .26) inset, 0 12px 24px rgba(79, 70, 229, .24);`
  - Border radius: `var(--r-md)` (14px)
  - Padding: `9px 16px`
  - Gap: `6px` between icon and label
- **Inputs & Selects**:
  - Background: `var(--surface-3)`
  - Border: `1.5px solid var(--border-solid)`
  - Radius: `var(--r-md)`
  - Font: `'Cairo', sans-serif`
  - Focus state: `border-color: var(--brand); outline: none; box-shadow: 0 0 0 3px var(--brand-glow);`
- **Dropdown Menus**:
  - Background: `var(--surface)`
  - Border: `1px solid var(--border-solid)`
  - Radius: `var(--r-lg)`
  - Shadow: `var(--shadow-xl)`
  - Items hover: `background: var(--surface-2); color: var(--brand);`

### 2.6 Universal Modal Architecture (Mobile Bottom Sheet vs. Desktop Centered)
Modals MUST implement responsive behavior mirroring the Uncle Dashboard:

- **Overlay (`.modal-overlay`)**:
  - Fixed full viewport: `position: fixed; inset: 0; z-index: 999999;`
  - Backdrop blur: `background: rgba(0, 0, 0, .45); backdrop-filter: blur(6px);`
  - Animation: `animation: overlayIn .2s var(--ease);`

- **Mobile Viewport (`<= 768px`) — Bottom Sheet**:
  - Alignment: `justify-content: flex-end; align-items: center; flex-direction: column;`
  - Border Radius: Top corners only `border-radius: var(--r-2xl) var(--r-2xl) 0 0;`
  - Dimensions: `width: 100%; height: 82vh; max-height: 82vh;`
  - Shadow: `box-shadow: 0 -8px 40px rgba(0, 0, 0, .2);`
  - Drag Indicator:
    ```css
    .modal-header::before {
        content: '';
        position: absolute;
        top: 10px;
        left: 50%;
        transform: translateX(-50%);
        width: 36px;
        height: 4px;
        background: var(--border-solid);
        border-radius: 2px;
    }
    ```
  - Animation: `animation: sheetUp .35s var(--spring);`
  - Closing Animation: `.modal.closing { animation: sheetDown .25s var(--ease) forwards; }`

- **Desktop Viewport (`> 768px`) — Centered Modal Card**:
  ```css
  @media (min-width: 769px) {
      .modal-overlay {
          justify-content: center;
          align-items: center;
          flex-direction: row;
      }
      .modal {
          height: auto;
          max-height: 90vh;
          border-radius: var(--r-xl);
          box-shadow: var(--shadow-xl);
          animation: fadeScaleIn .25s var(--spring);
      }
      .modal-header::before {
          display: none;
      }
      .modal-header {
          position: static;
          padding-top: 0;
          margin-top: 20px;
          margin-inline: 0;
          padding-inline: 0;
      }
  }
  ```

### 2.7 Universal Modal Sub-Page Navigation Pattern
Modals requiring sub-pages or detailed views MUST use the all-in-one navigation pattern:

1. **Navigation Rows (`.navigation-row`)**:
   - Background: `var(--surface-2)`
   - Border: `1px solid var(--border-solid)`
   - Border Radius: `var(--r-xl)`
   - Structure:
     ```html
     <div class="navigation-row" onclick="openSubPage('pageId')">
         <div style="display:flex; align-items:center; gap:10px;">
             <span class="navigation-icon purple"><i class="fas fa-icon"></i></span>
             <span class="navigation-label">اسم القسم</span>
         </div>
         <div style="display:flex; align-items:center; gap:6px;">
             <span class="navigation-count">4</span>
             <i class="fas fa-chevron-left navigation-arrow"></i>
         </div>
     </div>
     ```
2. **Back Button in Header (`.back-btn`)**:
   - In RTL layouts, the back button is placed inside the header title:
     ```html
     <div class="modal-header">
         <h3>
             <button class="back-btn" onclick="backToMainModalView()">
                 <i class="fas fa-arrow-right"></i>
             </button>
             <span>عنوان الشاشة الفرعية</span>
         </h3>
         <button class="close-btn" onclick="closeModal()">&times;</button>
     </div>
     ```
   - Hover state: `color: var(--brand); background: var(--surface-3); border-radius: var(--r-md);`
3. **Close Button (`.close-btn`)**:
   - Circular `34px` pill, `border-radius: 50%`, `background: var(--surface-3); color: var(--text-2); border: none;`
   - Hover: `background: var(--brand-bg); color: var(--brand); transform: scale(1.1);`

### 2.8 Revealing Animations & Motion Timing
- Use the spring transition token `var(--spring)` (`cubic-bezier(.16, 1, .3, 1)`) for card and modal entrances.
- Core keyframes:
  ```css
  @keyframes overlayIn {
      from { opacity: 0; }
      to { opacity: 1; }
  }
  @keyframes sheetUp {
      from { transform: translateY(100%); }
      to { transform: translateY(0); }
  }
  @keyframes sheetDown {
      from { transform: translateY(0); opacity: 1; }
      to { transform: translateY(100%); opacity: 0; }
  }
  @keyframes fadeScaleIn {
      from { opacity: 0; transform: scale(.96) translateY(16px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
  }
  ```

---

## 3. Mandatory Absolute Root API Path
- All frontend fetch calls to `api.php` from subdirectories (e.g. `/user/login/`, `/uncle/trip/`, `/uncle/dashboard/`) MUST use the absolute root URL `/api.php` instead of relative `api.php`.
