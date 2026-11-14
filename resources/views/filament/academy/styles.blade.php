{{--
    Academy-only chrome.

    Emitted through the panel's STYLES_AFTER render hook rather than a
    Vite theme, so the portal needs no new build input and cannot affect
    the admin panel's compiled stylesheet. The Academy therefore has
    Filament's own component CSS and nothing else: no Tailwind utilities
    and none of the admin theme's fynn-* building blocks. Every class the
    trainee views use is defined here, mirroring the admin design system
    (same radii, borders, shadows and accent rules) under academy-* names.

    Colours come from the panel's --primary-* / --gray-* variables, which
    Filament emits as complete oklch() colours — use them directly or via
    color-mix(), never wrapped in rgb().
--}}
<style>
    .fi-body {
        --academy-radius-xl: 1.125rem;
        --academy-radius-lg: 0.875rem;
        --academy-radius-md: 0.625rem;
        --academy-radius-sm: 0.5rem;

        --academy-surface: #fff;
        --academy-surface-2: color-mix(in oklab, var(--gray-50) 70%, white);
        --academy-surface-3: var(--gray-100);
        --academy-border: color-mix(in oklab, var(--gray-950) 9%, transparent);
        --academy-border-soft: color-mix(in oklab, var(--gray-950) 6%, transparent);
        --academy-border-strong: color-mix(in oklab, var(--gray-950) 16%, transparent);

        --academy-text: var(--gray-950);
        --academy-text-2: var(--gray-700);
        --academy-text-3: var(--gray-500);

        --academy-accent-text: var(--primary-800);
        --academy-accent-soft: color-mix(in oklab, var(--primary-500) 14%, transparent);
        --academy-accent-softer: color-mix(in oklab, var(--primary-500) 7%, transparent);
        --academy-line: var(--gray-200);

        --academy-shadow-sm: 0 1px 2px rgb(16 24 40 / 4%), 0 2px 10px -3px rgb(16 24 40 / 7%);
        --academy-shadow-md: 0 1px 3px rgb(16 24 40 / 5%), 0 12px 28px -10px rgb(16 24 40 / 16%);
    }

    .dark .fi-body {
        --academy-surface: var(--gray-900);
        --academy-surface-2: color-mix(in oklab, white 3%, var(--gray-900));
        --academy-surface-3: color-mix(in oklab, white 7%, var(--gray-900));
        --academy-border: rgb(255 255 255 / 9%);
        --academy-border-soft: rgb(255 255 255 / 6%);
        --academy-border-strong: rgb(255 255 255 / 16%);

        --academy-text: #fff;
        --academy-text-2: var(--gray-300);
        --academy-text-3: var(--gray-400);

        --academy-accent-text: var(--primary-300);
        --academy-accent-soft: color-mix(in oklab, var(--primary-400) 16%, transparent);
        --academy-accent-softer: color-mix(in oklab, var(--primary-400) 8%, transparent);
        --academy-line: var(--gray-700);

        --academy-shadow-sm: 0 1px 2px rgb(0 0 0 / 30%), 0 4px 14px -4px rgb(0 0 0 / 45%);
        --academy-shadow-md: 0 1px 3px rgb(0 0 0 / 35%), 0 14px 30px -10px rgb(0 0 0 / 55%);
    }

    /* ---- Sections on trainee pages: icon as a tinted tile ---- */

    .academy-page .fi-section-header > .fi-icon,
    .academy-widget .fi-section-header > .fi-icon {
        box-sizing: content-box;
        padding: 0.4375rem;
        border-radius: var(--academy-radius-md);
        color: var(--academy-accent-text);
        background-color: var(--academy-accent-soft);
        box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--primary-500) 22%, transparent);
    }

    /* ---- Layout ---- */

    .academy-stack {
        display: grid;
        gap: 1rem;
        min-width: 0;
    }

    .academy-grid {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 19rem), 1fr));
    }

    .academy-grid--wide {
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 24rem), 1fr));
    }

    .academy-player {
        display: grid;
        gap: 1rem;
        grid-template-columns: minmax(0, 1fr);
    }

    @media (min-width: 1024px) {
        .academy-player {
            grid-template-columns: 20rem minmax(0, 1fr);
            align-items: start;
        }

        .academy-player__outline {
            position: sticky;
            top: 1rem;
        }
    }

    .academy-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.75rem;
    }

    .academy-actions--end {
        justify-content: flex-end;
    }

    /* ---- Card ---- */

    .academy-card {
        display: flex;
        flex-direction: column;
        min-width: 0;
        padding: 1.125rem 1.25rem;
        border: 1px solid var(--academy-border);
        border-radius: var(--academy-radius-lg);
        background-color: var(--academy-surface);
        box-shadow: var(--academy-shadow-sm);
        transition: box-shadow 150ms ease, border-color 150ms ease;
    }

    .academy-card:hover {
        border-color: color-mix(in oklab, var(--primary-500) 35%, var(--academy-border));
        box-shadow: var(--academy-shadow-md);
    }

    .academy-icon-tile {
        display: inline-flex;
        flex-shrink: 0;
        align-items: center;
        justify-content: center;
        width: 2.5rem;
        height: 2.5rem;
        border-radius: var(--academy-radius-md);
        color: var(--academy-accent-text);
        background-color: var(--academy-accent-soft);
        box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--primary-500) 22%, transparent);
    }

    .academy-icon-tile .fi-icon {
        width: 1.25rem;
        height: 1.25rem;
    }

    /* ---- Course card (My Courses) ---- */

    .academy-course__head {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .academy-course__title {
        flex: 1;
        min-width: 0;
        margin: 0;
        padding-top: 0.125rem;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.4;
        letter-spacing: -0.01em;
        color: var(--academy-text);
    }

    .academy-course__summary {
        margin: 0.75rem 0 0;
        font-size: 0.875rem;
        line-height: 1.5;
        color: var(--academy-text-3);
    }

    .academy-course__progress {
        margin-top: 1rem;
        padding: 0.75rem 0.875rem;
        border: 1px solid var(--academy-border-soft);
        border-radius: var(--academy-radius-md);
        background-color: var(--academy-surface-2);
    }

    .academy-course__next {
        display: flex;
        align-items: center;
        gap: 0.375rem;
        margin: 0.75rem 0 0;
        font-size: 0.75rem;
        color: var(--academy-text-3);
    }

    .academy-course__next .fi-icon {
        flex-shrink: 0;
        width: 1rem;
        height: 1rem;
        color: var(--academy-accent-text);
    }

    .academy-course__next strong {
        min-width: 0;
        font-weight: 600;
        color: var(--academy-text-2);
        overflow-wrap: anywhere;
    }

    .academy-course__footer {
        margin-top: auto;
        padding-top: 1rem;
    }

    /* ---- Progress ---- */

    .academy-progress-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.4375rem;
        font-size: 0.75rem;
        color: var(--academy-text-3);
    }

    .academy-progress-meta__value {
        font-weight: 800;
        color: var(--academy-text);
        font-variant-numeric: tabular-nums;
    }

    .academy-progress {
        height: 0.5rem;
        border-radius: 9999px;
        background: var(--academy-line);
        overflow: hidden;
    }

    .academy-progress__bar {
        height: 100%;
        min-width: 0;
        border-radius: 9999px;
        background: linear-gradient(90deg, var(--primary-400), var(--primary-600));
        transition: width 240ms ease;
    }

    /* ---- Small type ---- */

    .academy-eyebrow {
        margin: 0;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        line-height: 1rem;
        text-transform: uppercase;
        color: var(--academy-text-3);
    }

    .academy-muted {
        margin: 0;
        font-size: 0.875rem;
        color: var(--academy-text-3);
    }

    .academy-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.3125rem;
        padding: 0.125rem 0.5625rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        line-height: 1.25rem;
        white-space: nowrap;
        color: var(--academy-text-2);
        background-color: var(--academy-surface-3);
        box-shadow: inset 0 0 0 1px var(--academy-border);
    }

    .academy-chip--accent {
        color: var(--primary-900);
        background-color: var(--primary-100);
        box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--primary-500) 35%, transparent);
    }

    .dark .academy-chip--accent {
        color: var(--primary-300);
        background-color: var(--academy-accent-soft);
    }

    /* ---- Empty state ---- */

    .academy-empty {
        display: grid;
        justify-items: center;
        gap: 0.5rem;
        padding: 2.5rem 1.5rem;
        border: 1px dashed var(--academy-border-strong);
        border-radius: var(--academy-radius-lg);
        background-color: var(--academy-surface-2);
        text-align: center;
        font-size: 0.875rem;
        line-height: 1.5;
        color: var(--academy-text-3);
    }

    .academy-empty__icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 3rem;
        height: 3rem;
        margin-bottom: 0.25rem;
        border-radius: 9999px;
        color: var(--academy-accent-text);
        background-color: var(--academy-accent-soft);
        box-shadow: 0 0 0 6px var(--academy-accent-softer);
    }

    .academy-empty__icon .fi-icon {
        width: 1.5rem;
        height: 1.5rem;
    }

    .academy-empty--compact {
        padding: 1.5rem 1rem;
    }

    /* ---- Course outline (Course Player) ---- */

    .academy-outline__progress {
        margin-bottom: 1.25rem;
    }

    .academy-module + .academy-module {
        margin-top: 1.25rem;
    }

    .academy-module__title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin: 0 0 0.5rem;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--academy-text-3);
    }

    .academy-module__title::before {
        content: '';
        width: 0.25rem;
        height: 0.75rem;
        border-radius: 9999px;
        background-color: var(--primary-500);
    }

    .academy-lessons {
        display: grid;
        gap: 0.125rem;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .academy-lesson {
        position: relative;
        display: flex;
        width: 100%;
        align-items: flex-start;
        gap: 0.625rem;
        padding: 0.5rem 0.625rem;
        border-radius: var(--academy-radius-sm);
        font-size: 0.875rem;
        line-height: 1.35;
        text-align: start;
        color: var(--academy-text-2);
        transition: background-color 120ms ease, color 120ms ease;
    }

    .academy-lesson:hover {
        background-color: var(--academy-accent-softer);
        color: var(--academy-text);
    }

    .academy-lesson:focus-visible {
        outline: 2px solid var(--primary-500);
        outline-offset: 1px;
    }

    .academy-lesson.is-current {
        font-weight: 600;
        color: var(--academy-accent-text);
        background-color: var(--academy-accent-soft);
        box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--primary-500) 28%, transparent);
    }

    .academy-lesson.is-current::before {
        content: '';
        position: absolute;
        inset-block: 0.5rem;
        inset-inline-start: -0.375rem;
        width: 3px;
        border-radius: 3px;
        background-color: var(--primary-500);
    }

    .academy-lesson__icon {
        display: inline-flex;
        flex-shrink: 0;
        margin-top: 0.0625rem;
        color: var(--gray-400);
    }

    .academy-lesson__icon .fi-icon {
        width: 1rem;
        height: 1rem;
    }

    .academy-lesson.is-done .academy-lesson__icon {
        color: var(--success-600);
    }

    .academy-lesson.is-current .academy-lesson__icon {
        color: var(--academy-accent-text);
    }

    .academy-outline__assessment {
        margin-top: 1.25rem;
        padding-top: 1rem;
        border-top: 1px solid var(--academy-border-soft);
    }

    .academy-outline__assessment .fi-btn {
        width: 100%;
    }

    /* ---- Lesson body ---- */

    .academy-video {
        margin-bottom: 1.25rem;
        overflow: hidden;
        border-radius: var(--academy-radius-lg);
        background: #000;
        box-shadow: var(--academy-shadow-sm);
    }

    .academy-video video {
        display: block;
        width: 100%;
        aspect-ratio: 16 / 9;
    }

    .academy-prose {
        max-width: 72ch;
        font-size: 0.9375rem;
        line-height: 1.75;
        color: var(--academy-text-2);
        overflow-wrap: anywhere;
    }

    .academy-materials {
        margin-top: 1.5rem;
        padding-top: 1.25rem;
        border-top: 1px solid var(--academy-border-soft);
    }

    .academy-materials .academy-eyebrow {
        margin-bottom: 0.625rem;
    }

    .academy-docs {
        display: grid;
        gap: 0.5rem;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr));
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .academy-doc {
        display: flex;
        align-items: center;
        gap: 0.625rem;
        padding: 0.5rem 0.75rem;
        border: 1px solid var(--academy-border);
        border-radius: var(--academy-radius-md);
        background-color: var(--academy-surface-2);
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--academy-text);
        text-decoration: none;
        transition: border-color 120ms ease, background-color 120ms ease;
    }

    .academy-doc:hover {
        border-color: color-mix(in oklab, var(--primary-500) 45%, var(--academy-border));
        background-color: var(--academy-accent-softer);
    }

    .academy-doc .academy-icon-tile {
        width: 2rem;
        height: 2rem;
        border-radius: var(--academy-radius-sm);
    }

    .academy-doc .academy-icon-tile .fi-icon {
        width: 1.125rem;
        height: 1.125rem;
    }

    .academy-doc__title {
        min-width: 0;
        overflow-wrap: anywhere;
    }

    /* ---- Stat tiles (quiz start screen + result) ---- */

    .academy-stats {
        display: grid;
        gap: 0.75rem;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 11rem), 1fr));
        margin: 0;
    }

    .academy-stat {
        min-width: 0;
        padding: 0.75rem 0.875rem;
        border: 1px solid var(--academy-border-soft);
        border-radius: var(--academy-radius-md);
        background-color: var(--academy-surface-2);
    }

    .academy-stat__label {
        display: flex;
        align-items: center;
        gap: 0.375rem;
        margin: 0;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: var(--academy-text-3);
    }

    .academy-stat__label .fi-icon {
        width: 0.875rem;
        height: 0.875rem;
    }

    .academy-stat__value {
        margin: 0.3125rem 0 0;
        font-size: 1.5rem;
        font-weight: 800;
        line-height: 1.2;
        letter-spacing: -0.02em;
        color: var(--academy-text);
        font-variant-numeric: tabular-nums;
    }

    .academy-stat__value--sm {
        font-size: 1.125rem;
    }

    .academy-stat__badge {
        margin-top: 0.4375rem;
    }

    /* ---- Quiz ---- */

    .academy-question {
        margin: 0 0 1rem;
        font-size: 0.9375rem;
        font-weight: 600;
        line-height: 1.55;
        color: var(--academy-text);
    }

    .academy-options {
        display: grid;
        gap: 0.5rem;
    }

    .academy-option {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        padding: 0.75rem 0.875rem;
        border: 1px solid var(--academy-border);
        border-radius: var(--academy-radius-md);
        background-color: var(--academy-surface);
        cursor: pointer;
        transition: border-color 120ms ease, background-color 120ms ease, box-shadow 120ms ease;
    }

    .academy-option:hover {
        border-color: color-mix(in oklab, var(--primary-500) 40%, var(--academy-border));
        background-color: var(--academy-accent-softer);
    }

    .academy-option:has(input:checked) {
        border-color: var(--primary-500);
        background-color: var(--academy-accent-soft);
        box-shadow: 0 0 0 3px var(--academy-accent-softer);
    }

    .academy-option:has(input:focus-visible) {
        outline: 2px solid var(--primary-500);
        outline-offset: 2px;
    }

    .academy-option input {
        flex-shrink: 0;
        margin-top: 0.125rem;
    }

    .academy-option__text {
        font-size: 0.875rem;
        line-height: 1.5;
        color: var(--academy-text-2);
    }

    .academy-option:has(input:checked) .academy-option__text {
        color: var(--academy-text);
    }

    .academy-option__key {
        margin-inline-end: 0.25rem;
        font-weight: 800;
        color: var(--academy-accent-text);
    }

    /* ---- Previous attempts table ---- */

    .academy-table-wrap {
        overflow-x: auto;
        border: 1px solid var(--academy-border);
        border-radius: var(--academy-radius-md);
    }

    .academy-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.8125rem;
    }

    .academy-table th {
        padding: 0.625rem 0.875rem;
        text-align: start;
        font-size: 0.6875rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        white-space: nowrap;
        color: var(--academy-text-3);
        background-color: var(--academy-surface-2);
        border-bottom: 1px solid var(--academy-border);
    }

    .academy-table td {
        padding: 0.625rem 0.875rem;
        color: var(--academy-text-2);
        border-bottom: 1px solid var(--academy-border-soft);
        font-variant-numeric: tabular-nums;
        vertical-align: middle;
    }

    .academy-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .academy-table tbody tr:hover td {
        background-color: var(--academy-accent-softer);
    }

    .academy-table td.academy-table__strong {
        font-weight: 700;
        color: var(--academy-text);
    }

    .academy-table td.academy-table__muted {
        color: var(--academy-text-3);
    }

    /* ---- Certificate ---- */

    .academy-certificate {
        position: relative;
        overflow: hidden;
        padding: 0.5rem;
        border: 1px solid var(--academy-border);
        border-radius: var(--academy-radius-xl);
        background:
            radial-gradient(70% 60% at 50% 0%, color-mix(in oklab, var(--primary-400) 16%, transparent), transparent 70%),
            var(--academy-surface);
        box-shadow: var(--academy-shadow-sm);
    }

    .academy-certificate__face {
        display: grid;
        justify-items: center;
        padding: 1.75rem 1.5rem 1.5rem;
        border: 2px dashed color-mix(in oklab, var(--primary-500) 45%, transparent);
        border-radius: calc(var(--academy-radius-xl) - 0.375rem);
        text-align: center;
    }

    .academy-certificate__seal {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 3.25rem;
        height: 3.25rem;
        margin-bottom: 0.875rem;
        border-radius: 9999px;
        color: var(--primary-950);
        background: linear-gradient(135deg, var(--primary-200), var(--primary-500));
        box-shadow: 0 0 0 6px var(--academy-accent-softer), inset 0 0 0 1px color-mix(in oklab, var(--primary-700) 25%, transparent);
    }

    .academy-certificate__seal .fi-icon {
        width: 1.625rem;
        height: 1.625rem;
    }

    .academy-certificate__kicker {
        margin: 0;
        font-size: 0.75rem;
        font-weight: 800;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--academy-accent-text);
    }

    .academy-certificate__small {
        margin: 1rem 0 0;
        font-size: 0.6875rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--academy-text-3);
    }

    .academy-certificate__small--tight {
        margin-top: 0.75rem;
    }

    .academy-certificate__name {
        margin: 0.25rem 0 0;
        font-size: 1.375rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        color: var(--academy-text);
        overflow-wrap: anywhere;
    }

    .academy-certificate__course {
        margin: 0.25rem 0 0;
        font-size: 1rem;
        font-weight: 600;
        color: var(--academy-text-2);
        overflow-wrap: anywhere;
    }

    .academy-certificate__score {
        margin: 1rem 0 0;
        font-size: 0.875rem;
        color: var(--academy-text-2);
    }

    .academy-certificate__score strong {
        font-weight: 800;
        color: var(--academy-text);
    }

    .academy-certificate__meta {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 0.5rem 2rem;
        width: 100%;
        margin: 1.25rem 0 0;
        padding-top: 1rem;
        border-top: 1px solid var(--academy-border-soft);
        font-size: 0.75rem;
        color: var(--academy-text-3);
    }

    .academy-certificate__meta dt {
        font-size: 0.6875rem;
        letter-spacing: 0.06em;
        text-transform: uppercase;
    }

    .academy-certificate__meta dd {
        margin: 0.125rem 0 0;
        font-weight: 600;
        color: var(--academy-text-2);
    }

    .academy-certificate__meta dd.academy-mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }

    /* ---- Learning path (dashboard widget) ---- */

    .academy-path + .academy-path {
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--academy-border-soft);
    }

    .academy-path__head {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.625rem;
    }

    .academy-path__title {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: var(--academy-text);
    }

    .academy-path__footer {
        margin-top: 1.25rem;
    }

    .academy-timeline {
        --academy-step-line: var(--academy-line);
        display: grid;
        gap: 1rem;
        grid-template-columns: minmax(0, 1fr);
        margin-top: 1.25rem;
    }

    @media (min-width: 640px) {
        .academy-timeline {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem 1.5rem;
        }
    }

    .academy-timeline__col {
        min-width: 0;
        padding: 0.875rem 1rem 0.25rem;
        border: 1px solid var(--academy-border-soft);
        border-radius: var(--academy-radius-md);
        background-color: var(--academy-surface-2);
    }

    .academy-timeline__col > .academy-eyebrow {
        margin-bottom: 0.75rem;
    }

    .academy-timeline__col > .academy-muted {
        padding-bottom: 0.75rem;
    }

    .academy-step {
        position: relative;
        padding-left: 1.75rem;
        padding-bottom: 0.875rem;
        font-size: 0.875rem;
        line-height: 1.4;
        color: var(--academy-text-2);
    }

    .academy-step::before {
        content: '';
        position: absolute;
        left: 0.3125rem;
        top: 1.25rem;
        bottom: 0;
        width: 2px;
        background: var(--academy-step-line);
    }

    .academy-step:last-child::before {
        display: none;
    }

    .academy-step__dot {
        position: absolute;
        left: 0;
        top: 0.25rem;
        width: 0.75rem;
        height: 0.75rem;
        border-radius: 9999px;
        border: 2px solid var(--academy-step-line);
        background: var(--academy-surface);
    }

    .academy-step--done .academy-step__dot {
        background: var(--success-600);
        border-color: var(--success-600);
    }

    .academy-step--current {
        font-weight: 600;
        color: var(--academy-text);
    }

    .academy-step--current .academy-step__dot {
        background: var(--primary-500);
        border-color: var(--primary-500);
        box-shadow: 0 0 0 4px color-mix(in oklab, var(--primary-500) 20%, transparent);
    }

    .academy-step--upcoming {
        color: var(--academy-text-3);
    }

    @media (prefers-reduced-motion: reduce) {
        .academy-card,
        .academy-progress__bar,
        .academy-lesson,
        .academy-option,
        .academy-doc {
            transition: none;
        }
    }
</style>
