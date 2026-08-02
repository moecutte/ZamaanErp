<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'POS' }}</title>
    <link rel="icon" href="{{ asset('favicon.png') }}" type="image/png">
    @livewireStyles
    <style>
        :root {
            --pos-bg: #1a1a1a;
            --pos-panel: #262626;
            --pos-card: #333333;
            --pos-card-hover: #3d3d3d;
            --pos-line: #404040;
            --pos-accent: #714B67;
            --pos-accent-hover: #8a5f80;
            --pos-text: #f5f5f5;
            --pos-muted: #a3a3a3;
            --pos-ok: #166534;
            --pos-ok-text: #bbf7d0;
            --pos-bad: #9f1239;
            --pos-bad-text: #fecdd3;
            --pos-pay: #017e84;
            --pos-pay-hover: #01949b;
            --header-h: 64px;
            --cart-w: 380px;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            margin: 0;
            height: 100%;
            overflow: hidden;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--pos-bg);
            color: var(--pos-text);
        }
        [x-cloak] { display: none !important; }

        .pos-shell {
            display: grid;
            grid-template-rows: var(--header-h) 1fr;
            height: 100vh;
            width: 100vw;
        }

        /* Header */
        .pos-header {
            display: grid;
            grid-template-columns: 180px 1fr auto;
            align-items: center;
            gap: 16px;
            padding: 0 16px;
            background: var(--pos-panel);
            border-bottom: 1px solid var(--pos-line);
        }
        .pos-brand {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0;
            gap: 2px;
        }
        .pos-brand-logo {
            height: 44px;
            width: auto;
            max-width: 220px;
            padding: 4px 8px;
            box-sizing: content-box;
            object-fit: contain;
            display: block;
        }
        .pos-brand span {
            font-size: 11px;
            color: var(--pos-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .pos-search {
            width: 100%;
            max-width: 520px;
            margin: 0 auto;
            height: 36px;
            padding: 0 14px;
            border: 1px solid var(--pos-line);
            border-radius: 6px;
            background: var(--pos-bg);
            color: var(--pos-text);
            font-size: 14px;
        }
        .pos-search:focus {
            outline: 2px solid var(--pos-accent);
            border-color: transparent;
        }
        .pos-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .pos-select, .pos-btn {
            height: 36px;
            border: 1px solid var(--pos-line);
            border-radius: 6px;
            background: var(--pos-card);
            color: var(--pos-text);
            font-size: 13px;
            padding: 0 12px;
        }
        .pos-select { max-width: 180px; }
        .pos-btn {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            cursor: pointer;
        }
        .pos-btn:hover { background: var(--pos-card-hover); }
        .pos-btn-link { background: transparent; }

        /* Body: products + cart */
        .pos-body {
            display: grid;
            grid-template-columns: 1fr var(--cart-w);
            min-height: 0;
            height: 100%;
        }

        .pos-catalog {
            display: grid;
            grid-template-rows: auto 1fr;
            min-width: 0;
            min-height: 0;
            background: var(--pos-bg);
        }
        .pos-categories {
            display: flex;
            gap: 8px;
            padding: 12px 16px;
            overflow-x: auto;
            border-bottom: 1px solid var(--pos-line);
            background: var(--pos-panel);
        }
        .pos-chip {
            flex: 0 0 auto;
            height: 32px;
            padding: 0 14px;
            border: 1px solid var(--pos-line);
            border-radius: 999px;
            background: var(--pos-card);
            color: var(--pos-text);
            font-size: 12px;
            cursor: pointer;
        }
        .pos-chip.is-active {
            background: var(--pos-accent);
            border-color: var(--pos-accent);
        }

        .pos-grid-wrap {
            overflow: auto;
            padding: 12px;
            min-height: 0;
        }
        .pos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 10px;
        }
        .pos-product {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 210px;
            padding: 0;
            overflow: hidden;
            border: 1px solid var(--pos-line);
            border-radius: 8px;
            background: var(--pos-card);
            color: inherit;
            text-align: left;
            cursor: pointer;
            transition: background 0.12s, border-color 0.12s;
        }
        .pos-product:hover:not(:disabled) {
            background: var(--pos-card-hover);
            border-color: var(--pos-accent);
        }
        .pos-product:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .pos-product-media {
            width: 100%;
            aspect-ratio: 4 / 3;
            background: #1f1f1f;
            overflow: hidden;
        }
        .pos-product-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .pos-product-img-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            background: linear-gradient(145deg, #2a2a2a, #1f1f1f);
        }
        .pos-product-img-fallback svg {
            width: 36px;
            height: 36px;
        }
        .pos-product-body {
            padding: 10px 12px 0;
        }
        .pos-product-name {
            font-size: 13px;
            font-weight: 600;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 2.6em;
        }
        .pos-product-sku {
            margin-top: 4px;
            font-size: 11px;
            color: var(--pos-muted);
        }
        .pos-product-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 8px;
            margin-top: auto;
            padding: 10px 12px 12px;
        }
        .pos-product-price {
            font-size: 16px;
            font-weight: 700;
        }
        .pos-product-unit {
            font-size: 10px;
            color: var(--pos-muted);
        }
        .pos-badge {
            font-size: 10px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .pos-badge-ok { background: var(--pos-ok); color: var(--pos-ok-text); }
        .pos-badge-bad { background: var(--pos-bad); color: var(--pos-bad-text); }
        .pos-empty {
            grid-column: 1 / -1;
            padding: 64px 16px;
            text-align: center;
            color: var(--pos-muted);
            font-size: 14px;
        }

        /* Cart */
        .pos-cart {
            display: grid;
            grid-template-rows: auto 1fr auto;
            min-height: 0;
            border-left: 1px solid var(--pos-line);
            background: var(--pos-panel);
        }
        .pos-cart-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 16px;
            border-bottom: 1px solid var(--pos-line);
        }
        .pos-cart-head h2 {
            margin: 0;
            font-size: 15px;
        }
        .pos-cart-head p {
            margin: 2px 0 0;
            font-size: 11px;
            color: var(--pos-muted);
        }
        .pos-cart-clear {
            border: 0;
            background: transparent;
            color: #fda4af;
            font-size: 12px;
            cursor: pointer;
        }
        .pos-cart-lines {
            overflow: auto;
            min-height: 0;
        }
        .pos-line {
            padding: 12px 16px;
            border-bottom: 1px solid var(--pos-line);
        }
        .pos-line-top {
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }
        .pos-line-name {
            font-size: 13px;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .pos-line-meta {
            font-size: 11px;
            color: var(--pos-muted);
            margin-top: 2px;
        }
        .pos-line-remove {
            border: 0;
            background: transparent;
            color: var(--pos-muted);
            cursor: pointer;
            font-size: 14px;
        }
        .pos-line-remove:hover { color: #fda4af; }
        .pos-line-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }
        .pos-qty {
            display: inline-flex;
            align-items: stretch;
            border: 1px solid var(--pos-line);
            border-radius: 6px;
            overflow: hidden;
        }
        .pos-qty button {
            width: 32px;
            border: 0;
            background: var(--pos-card);
            color: var(--pos-text);
            font-size: 16px;
            cursor: pointer;
        }
        .pos-qty button:hover { background: var(--pos-card-hover); }
        .pos-qty input {
            width: 56px;
            border: 0;
            border-left: 1px solid var(--pos-line);
            border-right: 1px solid var(--pos-line);
            background: var(--pos-bg);
            color: var(--pos-text);
            text-align: center;
            font-size: 13px;
        }
        .pos-line-subtotal {
            font-size: 14px;
            font-weight: 700;
        }
        .pos-cart-empty {
            padding: 48px 16px;
            text-align: center;
            color: var(--pos-muted);
            font-size: 13px;
        }

        .pos-cart-footer {
            padding: 16px;
            border-top: 1px solid var(--pos-line);
            background: #222;
        }
        .pos-cart-footer label {
            display: block;
            font-size: 11px;
            color: var(--pos-muted);
            margin-bottom: 6px;
        }
        .pos-cart-footer .pos-select {
            width: 100%;
            max-width: none;
            margin-bottom: 14px;
        }
        .pos-total-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 14px;
        }
        .pos-total-row span { color: var(--pos-muted); font-size: 13px; }
        .pos-total-row strong { font-size: 28px; letter-spacing: -0.02em; }
        .pos-pay-btn {
            width: 100%;
            height: 48px;
            border: 0;
            border-radius: 8px;
            background: var(--pos-pay);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.03em;
            cursor: pointer;
        }
        .pos-pay-btn:hover { background: var(--pos-pay-hover); }
        .pos-pay-btn:disabled { opacity: 0.55; cursor: wait; }

        .pos-toast {
            position: fixed;
            top: calc(var(--header-h) + 12px);
            left: 50%;
            transform: translateX(-50%);
            z-index: 50;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 8px 24px rgba(0,0,0,0.35);
        }
        .pos-toast-success { background: #15803d; }
        .pos-toast-error { background: #be123c; }

        @media (max-width: 960px) {
            :root { --cart-w: 320px; }
            .pos-header {
                grid-template-columns: 1fr;
                grid-template-rows: auto auto auto;
                height: auto;
                padding: 10px 12px;
                gap: 8px;
            }
            .pos-shell { grid-template-rows: auto 1fr; }
            .pos-body { grid-template-columns: 1fr; grid-template-rows: 1fr 45vh; }
            .pos-cart { border-left: 0; border-top: 1px solid var(--pos-line); }
        }
    </style>
</head>
<body>
    {{ $slot }}
    @livewireScripts
</body>
</html>
