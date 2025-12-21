<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Layla Kitchen - Daily Dish Order Form (December)</title>
  <meta name="recaptcha-site-key" content="<?php echo htmlspecialchars((string) (getenv('RECAPTCHA_SITE_KEY') ?: '')); ?>">

  <?php if ((string) (getenv('RECAPTCHA_SITE_KEY') ?: '') !== ''): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo urlencode((string) getenv('RECAPTCHA_SITE_KEY')); ?>"></script>
  <?php endif; ?>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&family=Great+Vibes&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root{
      --bg: #f6f3ee;
      --paper: #ffffff;
      --ink: #1a1a1a;
      --muted: #6b6b6b;

      --blue: #1f3b86;
      --blue-2: #0f2d6f;

      --gold: #d9a24a;

      --pill: #f3dfbf;
      --pill-text: #6a3e00;

      --radius-lg: 18px;
      --radius-md: 12px;
      --shadow-soft: 0 10px 30px rgba(0,0,0,0.08);
      --shadow-mini: 0 4px 12px rgba(0,0,0,0.06);

      --selected-ring: rgba(31,59,134,0.20);
      --selected-bg: #fbfbff;
    }

    *{box-sizing:border-box}
    body{
      margin:0;
      font-family: Poppins, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      color: var(--ink);
      background: var(--bg);
      min-height: 100vh;
      position: relative;
      overflow-x: hidden;
    }

    .blob{
      position: fixed;
      width: 420px;
      height: 420px;
      background: radial-gradient(circle at 30% 30%, var(--blue), var(--blue-2));
      border-radius: 45% 55% 60% 40% / 45% 35% 65% 55%;
      opacity: 0.95;
      z-index: 0;
      filter: saturate(0.95);
    }
    .blob.left{ left: -220px; bottom: -220px; }
    .blob.right{ right: -220px; top: -220px; transform: rotate(12deg); }

    .page{
      position: relative;
      z-index: 1;
      max-width: 980px;
      margin: 40px auto 80px;
      padding: 0 18px;
    }

    .paper{
      background: var(--paper);
      border-radius: 28px;
      box-shadow: var(--shadow-soft);
      padding: 28px 28px 18px;
      border: 1px solid rgba(0,0,0,0.04);
    }

    .header{
      display: grid;
      grid-template-columns: 1fr auto;
      gap: 16px;
      align-items: center;
      margin-bottom: 18px;
    }

    .brand{
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .brand-mark{
      width: 64px;
      height: 64px;
      border-radius: 50%;
      /* border: 3px solid var(--gold); */
      display: grid;
      place-items: center;
      position: relative;
      background: #fff;
    }
    .brand-mark::after{
      content:"";
      position:absolute;
      width: 76px;
      height: 76px;
      border-radius: 50%;
      border: 1px solid rgba(217,162,74,0.35);
    }

    .brand-name{ line-height: 1; }
    .brand-name .layla{
      font-family: "Great Vibes", cursive;
      font-size: 42px;
      color: var(--gold);
      margin: 0;
    }
    .brand-name .kitchen{
      font-size: 13px;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: #7a7a7a;
      margin-top: 4px;
    }

    .title-ribbon{
      background: linear-gradient(135deg, var(--blue), var(--blue-2));
      color: #fff;
      padding: 12px 18px;
      border-radius: 999px;
      font-weight: 600;
      font-size: 18px;
      box-shadow: var(--shadow-mini);
      white-space: nowrap;
    }

    .customer{
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px 14px;
      margin: 14px 0 24px;
      padding: 14px;
      background: #fbfaf8;
      border: 1px solid rgba(0,0,0,0.04);
      border-radius: var(--radius-lg);
    }
    .field{
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .field label{
      font-size: 12px;
      color: var(--muted);
      font-weight: 500;
    }

    /* Bilingual labels (left/right) */
    .label-dual{
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 12px;
    }
    .label-dual .l-en{ text-align: left; }
    .label-dual .l-ar{
      text-align: right;
      direction: rtl;
      font-family: Cairo, system-ui, sans-serif;
    }
    .field input, .field textarea{
      border: 1px solid rgba(0,0,0,0.08);
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 14px;
      outline: none;
      transition: border 0.15s ease, box-shadow 0.15s ease;
      background: #fff;
    }
    .field textarea{
      min-height: 70px;
      resize: vertical;
    }
    .field input:focus, .field textarea:focus{
      border-color: rgba(31,59,134,0.45);
      box-shadow: 0 0 0 3px rgba(31,59,134,0.08);
    }
    .field.full{ grid-column: 1 / -1; }

    .meal-plan-section{
      margin: 24px 0;
      padding: 18px;
      background: #fbfaf8;
      border: 1px solid rgba(0,0,0,0.04);
      border-radius: var(--radius-lg);
    }
    .meal-plan-options{
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 12px;
      margin-top: 10px;
    }
    .meal-plan-card{
      position: relative;
      border: 2px solid rgba(0,0,0,0.08);
      border-radius: var(--radius-md);
      padding: 14px;
      background: #fff;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .meal-plan-card:hover{
      border-color: rgba(31,59,134,0.3);
      box-shadow: var(--shadow-mini);
    }
    .meal-plan-card input[type="radio"]{
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }
    .meal-plan-card input[type="radio"]:checked + .plan-content{
      color: var(--blue);
    }
    .meal-plan-card:has(input:checked){
      border-color: var(--blue);
      background: var(--selected-bg);
      box-shadow: 0 0 0 3px var(--selected-ring);
    }
    .plan-content{
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .plan-dual{
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 12px;
    }
    .plan-dual .p-en{ text-align: left; }
    .plan-dual .p-ar{
      text-align: right;
      direction: rtl;
      font-family: Cairo, system-ui, sans-serif;
    }
    .plan-name{
      font-weight: 700;
      font-size: 16px;
      color: var(--ink);
    }
    .plan-price{
      font-weight: 700;
      font-size: 18px;
      color: var(--blue);
      margin: 4px 0;
    }
    .plan-desc{
      font-size: 12px;
      color: var(--muted);
    }
    .meal-plan-status{
      margin-top: 12px;
      padding: 10px 14px;
      border-radius: var(--radius-md);
      font-size: 13px;
      font-weight: 600;
      display: none;
    }
    .meal-plan-status.show{
      display: block;
    }
    .meal-plan-status.info{
      background: rgba(31,59,134,0.08);
      color: var(--blue);
      border: 1px solid rgba(31,59,134,0.2);
    }
    .meal-plan-status.warning{
      background: rgba(217,162,74,0.1);
      color: var(--pill-text);
      border: 1px solid rgba(217,162,74,0.3);
    }

    .menu-grid{
      display: grid;
      grid-template-columns: 1fr;
      gap: 14px;
      margin-top: 8px;
    }
    .menu-grid.loading{
      opacity: 0.4;
      pointer-events: none;
    }
    .menu-loader{
      display: none;
      align-items: center;
      gap: 10px;
      margin-top: 10px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px dashed rgba(0,0,0,0.15);
      background: #faf8f4;
      color: var(--muted);
      font-size: 13px;
      font-weight: 600;
    }
    .menu-loader.show{
      display: flex;
    }
    .menu-loader::before{
      content: "";
      width: 16px;
      height: 16px;
      border-radius: 50%;
      border: 2px solid rgba(31,59,134,0.2);
      border-top-color: var(--blue);
      animation: spin 0.8s linear infinite;
    }
    @keyframes spin{
      to { transform: rotate(360deg); }
    }

    .day-card{
      border: 1px solid rgba(0,0,0,0.05);
      border-radius: var(--radius-lg);
      padding: 14px 14px 12px;
      background: #fff;
      transition: box-shadow .15s ease, background .15s ease, border-color .15s ease;
    }
    .day-card.selected{
      background: var(--selected-bg);
      border-color: var(--selected-ring);
      box-shadow: 0 0 0 3px var(--selected-ring);
    }
    .day-card.disabled{
      opacity: 0.5;
      pointer-events: none;
      filter: grayscale(0.2);
    }
    .past-toggle{
      margin: 6px 0 0;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #fff;
      border: 1px solid rgba(0,0,0,0.08);
      border-radius: 12px;
      padding: 10px 14px;
      cursor: pointer;
      font-weight: 600;
      color: var(--blue);
      box-shadow: var(--shadow-mini);
    }
    .past-toggle span.count{
      background: var(--pill);
      color: var(--pill-text);
      border-radius: 999px;
      padding: 2px 10px;
      font-size: 12px;
      font-weight: 700;
    }
    .past-list{
      margin-top: 10px;
      display: none;
    }
    .past-list.show{
      display: grid;
      grid-template-columns: 1fr;
      gap: 14px;
    }

    .day-head{
      display: grid;
      grid-template-columns: 1fr auto auto;
      align-items: center;
      gap: 10px;
      margin-bottom: 10px;
    }

    .day-pill{
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 8px 14px;
      background: var(--pill);
      color: var(--pill-text);
      border-radius: 999px;
      font-weight: 700;
      font-size: 13px;
      letter-spacing: 0.02em;
      width: fit-content;
    }
    .day-pill small{
      font-weight: 600;
      opacity: 0.85;
    }

    .day-status{
      font-size: 11px;
      font-weight: 600;
      color: var(--muted);
      background: #fafafa;
      border: 1px solid rgba(0,0,0,0.05);
      padding: 6px 10px;
      border-radius: 10px;
      white-space: nowrap;
    }
    .day-status.active{
      color: var(--blue);
      border-color: rgba(31,59,134,0.25);
      background: rgba(31,59,134,0.06);
    }

    .clear-day{
      font-size: 11px;
      font-weight: 600;
      border: 1px solid rgba(0,0,0,0.07);
      background: #f5f5f5;
      padding: 6px 10px;
      border-radius: 10px;
      cursor: pointer;
    }

    /* Meal type now full width */
    .meal-bar{
      background: #fbfaf8;
      border: 1px solid rgba(0,0,0,0.035);
      border-radius: var(--radius-md);
      padding: 10px 12px;
      margin-bottom: 12px;
    }

    .section-title{
      font-size: 11px;
      color: var(--muted);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 6px;
    }
    .section-head{
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      margin-top: 16px;
    }
    .section-clear{
      border: 1px solid rgba(0,0,0,0.08);
      background: #fff;
      color: var(--muted);
      font-size: 11px;
      padding: 6px 10px;
      border-radius: 10px;
      cursor: pointer;
      white-space: nowrap;
    }

    /* Bilingual section titles: English left, Arabic right (mirrored) */
    .section-title.dual{
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 12px;
    }
    .section-title.dual .st-en{
      flex: 1;
      min-width: 0;
      text-align: left;
    }
    .section-title.dual .st-ar{
      flex: 1;
      min-width: 0;
      text-align: right;
      direction: rtl;
      font-family: Cairo, system-ui, sans-serif;
    }

    .meal-type{
      display: flex;
      flex-wrap: wrap;
      gap: 8px 12px;
      margin-top: 6px;
    }
    .meal-pill{
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 1px solid rgba(0,0,0,0.08);
      padding: 6px 10px;
      border-radius: 999px;
      background: #fff;
      font-size: 12px;
      cursor: pointer;
    }
    .meal-pill input{ margin: 0; }

    .day-body{
      display: grid;
      grid-template-columns: 1fr;
      gap: 14px;
    }
    .col{
      padding: 10px 12px;
      border-radius: var(--radius-md);
      background: #fbfaf8;
      border: 1px solid rgba(0,0,0,0.035);
      min-height: 100%;
    }
    .col.full-width{
      grid-column: 1 / -1;
    }

    ul.clean{
      list-style: none;
      padding: 0;
      margin: 0 0 10px 0;
    }
    ul.clean li{
      font-size: 13px;
      padding: 2px 0;
    }

    .choice-group{
      display: grid;
      gap: 0;
      margin-top: 6px;
    }
    .choice{
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      cursor: pointer;
      padding: 2px 0;
    }
    .choice input{ 
      transform: translateY(1px);
      cursor: pointer;
    }
    .choice input[type="checkbox"]{
      width: 16px;
      height: 16px;
      margin-right: 8px;
    }
    .item-row{
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 16px;
      padding: 12px 0;
      border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    .item-row:last-child{
      border-bottom: none;
    }
    .item-row.has-qty{
      background: rgba(31,59,134,0.04);
      border-radius: 10px;
      padding: 12px 10px;
    }
    .item-info{
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .item-name-en{
      font-size: 14px;
      font-weight: 600;
      color: var(--ink);
    }
    .item-options{
      display: grid;
      gap: 6px;
      justify-items: end;
      text-align: right;
    }
    .item-name-ar{
      font-size: 13px;
      font-weight: 600;
      color: var(--ink);
      font-family: Cairo, system-ui, sans-serif;
      direction: rtl;
    }
    .quantity-choice{
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
      padding: 4px 0;
      cursor: pointer;
    }
    .quantity-choice.has-qty{
      font-weight: 600;
    }
    .quantity-choice.disabled{
      opacity: 0.55;
      pointer-events: none;
    }
    .increment-check{
      width: 18px;
      height: 18px;
      margin: 0 0 0 8px;
      cursor: pointer;
    }
    .option-label{
      font-size: 12px;
      font-weight: 500;
      color: var(--muted);
      white-space: nowrap;
    }
    .qty-display{
      font-size: 12px;
      font-weight: 700;
      color: var(--blue);
      min-width: 34px;
      text-align: right;
    }
    .bilingual-label{
      flex: 1;
      display: flex;
      flex-direction: row;
      justify-content: space-between;
      align-items: center;
      min-width: 0;
      max-width: none;
      gap: 12px;
    }
    .label-en{
      font-size: 13px;
      color: var(--ink);
      font-weight: 500;
      line-height: 1.3;
      flex: 1;
    }
    .label-ar{
      font-size: 13px;
      color: var(--ink);
      font-weight: 500;
      font-family: Cairo, system-ui, sans-serif;
      direction: rtl;
      text-align: right;
      line-height: 1.3;
      flex: 1;
    }
    .quantity-input{
      width: 70px;
      padding: 8px 10px;
      border: 1px solid rgba(0,0,0,0.15);
      border-radius: 8px;
      font-size: 15px;
      font-weight: 600;
      text-align: center;
      background: #fff;
      color: var(--blue);
    }
    .quantity-input:focus{
      outline: none;
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(31,59,134,0.1);
    }
    .quantity-input::-webkit-inner-spin-button,
    .quantity-input::-webkit-outer-spin-button{
      opacity: 1;
      cursor: pointer;
    }

    .portion-grid{
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      justify-content: flex-end;
      align-items: flex-start;
    }
    .portion-item{
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 4px;
      font-size: 10px;
      color: var(--muted);
    }
    .portion-item span{
      font-size: 10px;
      font-weight: 600;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .portion-input{
      width: 60px;
      padding: 6px 8px;
      border: 1px solid rgba(0,0,0,0.15);
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      text-align: center;
      background: #fff;
      color: var(--blue);
    }
    .portion-input:focus{
      outline: none;
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(31,59,134,0.1);
    }
    .portion-input:disabled{
      background: #f3f3f3;
      color: #9b9b9b;
      cursor: not-allowed;
    }

    .bundle-preview{
      font-size: 12px;
      color: var(--ink);
      line-height: 1.6;
      background: #f8f9fa;
      border: 1px solid rgba(0,0,0,0.08);
    }
    .bundle-preview strong{
      color: var(--blue);
      font-weight: 600;
    }

    .pricing{
      margin-top: 22px;
      padding: 14px 16px;
      border-radius: var(--radius-lg);
      background: #fbfaf8;
      border: 1px solid rgba(0,0,0,0.04);
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px 14px;
      align-items: center;
    }
    .pricing strong{ color: var(--blue); }
    .pricing .note{
      font-size: 12px;
      color: var(--muted);
    }
    .pricing .ar{
      font-family: Cairo, system-ui, sans-serif;
      direction: rtl;
      text-align: right;
    }

    /* Floating actions bar */
    body {
      padding-bottom: 110px; /* space for fixed bar */
    }
    .actions-bar{
      position: fixed;
      left: 50%;
      bottom: 16px;
      transform: translateX(-50%);
      width: min(960px, calc(100% - 32px));
      background: rgba(255,255,255,0.96);
      border: 1px solid rgba(0,0,0,0.05);
      border-radius: 16px;
      box-shadow: var(--shadow-soft);
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      justify-content: flex-end;
      padding: 10px 12px;
      z-index: 10;
      backdrop-filter: blur(6px);
    }
    .actions-bar .summary-hint{
      font-size: 12px;
      color: var(--muted);
      margin-right: auto;
      line-height: 1.35;
    }
    button{
      border: 0;
      padding: 12px 16px;
      border-radius: 12px;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      transition: transform 0.08s ease, opacity 0.15s ease;
    }
    button:active{ transform: translateY(1px); }

    .btn-primary{
      background: linear-gradient(135deg, var(--blue), var(--blue-2));
      color: #fff;
      box-shadow: var(--shadow-mini);
    }
    .btn-ghost{
      background: #f3f3f3;
      color: #222;
    }

    .modal{
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.35);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
      z-index: 50;
      overflow-y: auto;
    }
    .modal.show{ display: flex; }

    .modal-card{
      width: min(820px, 100%);
      background: #fff;
      border-radius: 22px;
      padding: 22px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.18);
      max-height: calc(100vh - 36px);
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    .modal-head{
      display:flex;
      justify-content: space-between;
      align-items:center;
      gap: 12px;
      margin-bottom: 10px;
    }
    .modal-head h3{
      margin: 0;
      font-size: 20px;
    }
    .summary{
      background: #fbfaf8;
      border: 1px solid rgba(0,0,0,0.05);
      border-radius: 14px;
      padding: 16px;
      font-size: 13px;
      line-height: 1.6;
      overflow: auto;
      flex: 1 1 auto;
      min-height: 120px;
    }
    .summary h4{
      margin: 0 0 8px 0;
      font-size: 16px;
      color: var(--blue);
    }
    .summary .meta{
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 8px 12px;
      margin-bottom: 12px;
      color: var(--ink);
      font-weight: 600;
    }
    .summary .meta span{
      display: block;
      font-size: 12px;
      color: var(--muted);
      font-weight: 500;
    }
    .summary .items{
      display: grid;
      grid-template-columns: 1fr;
      gap: 10px;
      margin: 12px 0;
    }
    .summary .item{
      background: #fff;
      border: 1px solid rgba(0,0,0,0.05);
      border-radius: 12px;
      padding: 10px 12px;
      box-shadow: var(--shadow-mini);
    }
    .summary .item .title{
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-weight: 700;
      color: var(--blue);
      margin-bottom: 6px;
    }
    .summary .item .sub{
      font-size: 12px;
      color: var(--muted);
    }
    .summary .item ul{
      list-style: none;
      padding: 0;
      margin: 8px 0 0;
      font-size: 13px;
      color: var(--ink);
    }
    .summary .total{
      margin-top: 8px;
      font-size: 15px;
      font-weight: 700;
      color: var(--blue-2);
    }

    .modal-actions{
      display:flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 12px;
    }

    @media (max-width: 860px){
      .customer{ grid-template-columns: 1fr; }
      .pricing{ grid-template-columns: 1fr; }
      .header{ grid-template-columns: 1fr; }
      .title-ribbon{ width: fit-content; }
      .day-head{ grid-template-columns: 1fr auto; }
      .clear-day{ grid-column: 2; }
      .meal-plan-options{ grid-template-columns: 1fr; }
      .quantity-input{ width: 60px; }
      .item-row{ grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="blob left"></div>
  <div class="blob right"></div>

  <div class="page">
    <div class="paper">
      <div class="header">
        <div class="brand">
          <div class="brand-mark">
            <img src="./assets/img/Logo.jpg" alt="Layla Kitchen" style="width:60px;height:60px;object-fit:cover;border-radius:50%;">
          </div>
          <div class="brand-name">
            <div class="layla">Layla Kitchen</div>
            <div class="kitchen">Premium Catering</div>
          </div>
        </div>
        <div class="title-ribbon">Daily Dish Menu - Order Form</div>
      </div>

      <form id="orderForm">
        <div class="customer">
          <div class="field">
            <label class="label-dual">
              <span class="l-en">Customer Name</span>
              <span class="l-ar">اسم العميل</span>
            </label>
            <input type="text" name="customerName" placeholder="Your name / اسمك" required />
          </div>
          <div class="field">
            <label class="label-dual">
              <span class="l-en">Phone</span>
              <span class="l-ar">رقم الهاتف</span>
            </label>
            <input type="tel" name="phone" placeholder="e.g., 55683442" required />
          </div>
          <div class="field">
            <label class="label-dual">
              <span class="l-en">Email</span>
              <span class="l-ar">البريد الإلكتروني</span>
            </label>
            <input type="email" name="email" placeholder="your.email@example.com" required />
          </div>
          <div class="field full">
            <label class="label-dual">
              <span class="l-en">Delivery Address</span>
              <span class="l-ar">عنوان التوصيل</span>
            </label>
            <textarea name="address" placeholder="Building, street, area... / مبنى، شارع، منطقة..." required></textarea>
          </div>
          <div class="field full">
            <label class="label-dual">
              <span class="l-en">Notes (optional)</span>
              <span class="l-ar">ملاحظات (اختياري)</span>
            </label>
            <textarea name="notes" placeholder="Allergies, preferred delivery time, etc. / حساسية، وقت التوصيل المفضل..."></textarea>
          </div>
        </div>

        <div class="meal-plan-section">
          <div class="section-title dual">
            <span class="st-en">Meal Plan Subscription</span>
            <span class="st-ar">اشتراك خطة الوجبات</span>
          </div>
          <div class="meal-plan-options">
            <label class="meal-plan-card">
              <input type="radio" name="mealPlan" value="" checked />
              <div class="plan-content">
                <div class="plan-name plan-dual">
                  <span class="p-en">No Subscription</span>
                  <span class="p-ar">بدون اشتراك</span>
                </div>
                <div class="plan-desc plan-dual">
                  <span class="p-en">Pay per meal</span>
                  <span class="p-ar">الدفع لكل وجبة</span>
                </div>
              </div>
            </label>
            <label class="meal-plan-card">
              <input type="radio" name="mealPlan" value="20" />
              <div class="plan-content">
                <div class="plan-name plan-dual">
                  <span class="p-en">20 Meals</span>
                  <span class="p-ar">٢٠ وجبة</span>
                </div>
                <div class="plan-price plan-dual">
                  <span class="p-en">QAR 800</span>
                  <span class="p-ar">٨٠٠ ر.ق</span>
                </div>
                <div class="plan-desc plan-dual">
                  <span class="p-en">QAR 40 per meal</span>
                  <span class="p-ar">٤٠ ر.ق لكل وجبة</span>
                </div>
              </div>
            </label>
            <label class="meal-plan-card">
              <input type="radio" name="mealPlan" value="26" />
              <div class="plan-content">
                <div class="plan-name plan-dual">
                  <span class="p-en">26 Meals</span>
                  <span class="p-ar">٢٦ وجبة</span>
                </div>
                <div class="plan-price plan-dual">
                  <span class="p-en">QAR 1,100</span>
                  <span class="p-ar">١٬١٠٠ ر.ق</span>
                </div>
                <div class="plan-desc plan-dual">
                  <span class="p-en">QAR 42.31 per meal</span>
                  <span class="p-ar">٤٢٫٣١ ر.ق لكل وجبة</span>
                </div>
              </div>
            </label>
          </div>
          <div id="mealPlanStatus" class="meal-plan-status"></div>
        </div>

        <div id="menuLoader" class="menu-loader" aria-live="polite">Loading daily menu...</div>
        <div id="menuGrid" class="menu-grid loading"></div>

        <div class="pricing">
          <div>
            <div><strong>Plate bundles (plate-only):</strong></div>
            <div>Full meal: QAR 65</div>
            <div>Main dish + salad: QAR 55</div>
            <div>Main dish + dessert: QAR 55</div>
            <div>Main dish only: QAR 50</div>
            <div style="margin-top:8px;"><strong>Portions (no meal plan):</strong></div>
            <div>Plate: QAR 50</div>
            <div>Half portion: QAR 130</div>
            <div>Full portion: QAR 200</div>
            <div style="margin-top:8px;"><strong>Add-ons (with portions):</strong> Salad QAR 15, Dessert QAR 15</div>
            <div style="margin-top:8px;"><strong>Meal plan pricing:</strong> 20 meals QAR 40/meal, 26 meals QAR 42.30/meal</div>
            <div class="note">Above prices include delivery charge.</div>
            <div class="note">Kindly place your orders before 24 hours.</div>
            <div class="note">Call / WhatsApp: <strong>55683442</strong></div>
          </div>
          <div class="ar">
            <div><strong>وجبة كاملة:</strong> ٦٥ ريال قطري</div>
            <div><strong>الطبق الرئيسي مع سلطة:</strong> ٥٥ ريال قطري</div>
            <div><strong>الطبق الرئيسي مع تحلية:</strong> ٥٥ ريال قطري</div>
            <div><strong>الطبق الرئيسي فقط:</strong> ٥٠ ريال قطري</div>
            <div class="note">األسعار المذكورة تشمل رسوم التوصيل.</div>
            <div class="note">يرجى تقديم الطلب قبل ٢٤ ساعة.</div>
            <div class="note">للطلب: <strong>٥٥٦٨٣٤٤٢</strong></div>
          </div>
        </div>

      </form>
    </div>
  </div>

  <!-- Floating action bar -->
  <div class="actions-bar">
    <div class="summary-hint">Generate your summary and place the order without scrolling to the bottom.</div>
    <button class="btn-primary" type="submit" form="orderForm">Generate Order Summary</button>
    <button class="btn-ghost" type="button" id="clearBtn">Clear All</button>
  </div>

  <div id="modal" class="modal" aria-hidden="true">
    <div class="modal-card">
      <div class="modal-head">
        <h3>Your Order Summary</h3>
        <button class="btn-ghost" id="closeModal" type="button">Close</button>
      </div>
      <div id="summaryBox" class="summary"></div>
      <div class="modal-actions">
        <button class="btn-primary" id="submitOrder" type="button">Submit Order</button>
        <button class="btn-primary" id="copySummary" type="button">Copy Summary</button>
        <button class="btn-ghost" id="openWhatsApp" type="button">Open WhatsApp Draft</button>
      </div>
    </div>
  </div>

  <script>
    const BUNDLE_PRICES = {
      full: 65,
      mainSalad: 55,
      mainDessert: 55,
      mainOnly: 50
    };
    const PORTION_PRICES = {
      plate: 50,
      half: 130,
      full: 200
    };
    const ADDON_PRICES = {
      salad: 15,
      dessert: 15
    };
    const PLAN_PRICES = {
      '20': 40,
      '26': 42.30
    };

    // Meal plan selection is tracked as a request/lead in the dashboard.
    let selectedMealPlan = null;
    let selectedMealCount = 0;

      // reCAPTCHA (v3). Set RECAPTCHA_SITE_KEY on the website host.
      const RECAPTCHA_SITE_KEY = document.querySelector('meta[name="recaptcha-site-key"]')?.content || '';
      async function getRecaptchaToken(){
        if (!RECAPTCHA_SITE_KEY || typeof grecaptcha === 'undefined') return '';
        try {
          return await grecaptcha.execute(RECAPTCHA_SITE_KEY, { action: 'daily_dish_order' });
        } catch {
          return '';
        }
      }

    function roundMoney(value){
      return Math.round((Number(value) + Number.EPSILON) * 100) / 100;
    }

    function formatPrice(value){
      const rounded = roundMoney(value);
      return Number.isInteger(rounded) ? rounded.toString() : rounded.toFixed(2);
    }

    function parseQuantity(value){
      const qty = parseInt(value, 10);
      return Number.isFinite(qty) && qty > 0 ? qty : 0;
    }

    function formatPortionLabel(portion){
      if (portion === 'full') return 'Full Portion';
      if (portion === 'half') return 'Half Portion';
      return 'Plate';
    }

    function setRowQty(row, qty){
      if (!row) {
        return;
      }
      const safeQty = Math.max(0, parseInt(qty, 10) || 0);
      row.dataset.qty = safeQty;
      const display = row.querySelector('[data-role="qty-display"]');
      if (display) {
        display.textContent = safeQty > 0 ? `x${safeQty}` : '';
      }
      row.classList.toggle('has-qty', safeQty > 0);

      const itemRow = row.closest('.item-row');
      if (itemRow) {
        const hasAnyQty = Array.from(itemRow.querySelectorAll('.quantity-choice'))
          .some((option) => parseQuantity(option.dataset.qty) > 0);
        itemRow.classList.toggle('has-qty', hasAnyQty);
      }
    }

    function incrementRow(row){
      const current = parseQuantity(row?.dataset?.qty);
      setRowQty(row, current + 1);
    }

    function setRowDisabled(row, disabled){
      if (!row) {
        return;
      }
      row.classList.toggle('disabled', !!disabled);
      const checkbox = row.querySelector('[data-role="increment"]');
      if (checkbox) {
        checkbox.disabled = !!disabled;
      }
    }

    // Translation helper
    const TRANSLATIONS = {
      // Salads
      'Green Salad': 'سلطة خضراء',
      'Tabbouleh': 'تبولة',
      'Greek Salad': 'سلطة يونانية',
      'Fattouch': 'فتوش',
      'Fresh Salad': 'سلطة طازجة',
      'Rocca Salad': 'سلطة جرجير',
      'Halloumi Salad': 'سلطة حلوم',
      'Quinoa salad': 'سلطة كينوا',
      'Malfouf salad or Green salad': 'سلطة ملفوف أو سلطة خضراء',
      // Desserts
      'Chocolate Cake': 'كيك شوكولاتة',
      'Tarte': 'تارت',
      'Orange Cake': 'كيك برتقال',
      'Cookies': 'بسكويت',
      'Banana cake': 'كيك موز',
      'Vanilla cake': 'كيك فانيليا',
      'Lazy cake': 'كيك كسول',
      'Muffins': 'مافن',
      'Brownies': 'براونيز',
      'Cake': 'كيك',
      'Carrot Cake': 'كيك جزر',
      'Rice pudding': 'أرز بالحليب',
      'Sfouf': 'صفوف',
      // Main Dishes
      'Fajita': 'فاهيتا',
      'Fassolia with meat': 'فاصوليا باللحم',
      'Fassolia with oil': 'فاصوليا بالزيت',
      'Chicken supreme': 'دجاج سوبريم',
      'Pasta bolognese': 'باستا بولونيز',
      'Eggplant msakaa': 'مسقعة',
      'Daoud bacha with rice': 'داود باشا مع أرز',
      'Mehchi koussa': 'محشي كوسا',
      'Fish fillet with vegetables': 'فيليه سمك مع خضار',
      'Beef stroganoff': 'ستروغانوف لحم',
      'Chicken shawarma': 'شاورما دجاج',
      'Loubye with oil': 'لوبيا بالزيت',
      'Chicken kaju nuts': 'دجاج كاجو',
      'Potato souffle': 'سوفليه بطاطا',
      'Pasta pesto': 'باستا بيستو',
      'Falafel': 'فلافل',
      'Mix Kabab': 'كبة مشكلة',
      'Shish Taouk': 'شيش طاووق',
      'Chicken stroganoff': 'ستروغانوف دجاج',
      'Kabab orfali': 'كبة أورفالي',
      'Noodles vegetables': 'نودلز خضار',
      'Vine leaves with meat': 'ورق عنب باللحم',
      'Chicken alfredo': 'دجاج ألفريدو',
      'Mjadara': 'مجدرة',
      'Kebbeh bi laban': 'كبة بلبن',
      'Chicken biryani': 'برياني دجاج',
      'Okra with oil': 'بامية بالزيت',
      'Coconut chicken curry': 'كاري دجاج بالكوكو',
      'Philadelphia': 'فيلادلفيا',
      'Penne Arrabbiata': 'بيني أرابياتا',
      'Lasagna': 'لازانيا',
      'Spinach with rice': 'سبانخ مع أرز',
      'Mdardara': 'مدردرة',
      'Grilled kafta': 'كفتة مشوية',
      'Shrimp with rice': 'روبيان مع أرز',
      'Pumpkin kebbeh': 'كبة يقطين',
      'Oriental rice with meat': 'أرز شرقي باللحم',
      'Noodles chicken': 'نودلز دجاج',
      'Coconut shrimp curry': 'كاري روبيان بالكوكو',
      'Mehchi Malfouf': 'محشي ملفوف',
      'Mashed potato with meat balls': 'بطاطا مهروسة مع كرات لحم',
      'Eggplant Msakaa': 'مسقعة',
      'Chich barak with rice': 'شيش برك مع أرز',
      'Creamy shrimp pasta': 'باستا روبيان كريمية',
      'Moughrabiye': 'مغربية',
      'Kabab khishkhash': 'كبة خيشخاش',
      'Meat balls with mashed': 'كرات لحم مع مهروس',
      'Shrimp kaju nuts': 'روبيان كاجو',
      'Grilled chicken': 'دجاج مشوي',
      'Beef burger': 'برجر لحم',
      'Siyadiye': 'صيادية',
      'Kafta bi tahini': 'كفتة بالطحينة',
      'Kafta with potato': 'كفتة مع بطاطا',
      'Kebbeh bil sayniye': 'كبة بالصينية',
      'Fish and chips': 'سمك ورقائق',
      'Shawarma chicken': 'شاورما دجاج',
      'Chicken nouille': 'دجاج نودلز',
      'Kabab khichkhach': 'كبة خيشخاش',
      'Frikeh chicken': 'فريكة دجاج',
      'Shawarma beef': 'شاورما لحم',
      'Loubye bi zeit': 'لوبيا بالزيت',
      'Roast beef with mashed potato': 'لحم مشوي مع بطاطا مهروسة',
      'Bazella with rice': 'بازيلا مع أرز',
      'Sheikh el mehchi': 'شيخ المحشي',
      'Chicken Alfredo': 'دجاج ألفريدو'
    };

    function getArTranslation(text) {
      return TRANSLATIONS[text] || text;
    }

    function normalizeMainSelections(mains){
      if (!Array.isArray(mains)) {
        return [];
      }
      return mains
        .map((row) => {
          const name = (row?.name ?? row?.main ?? '').toString().trim();
          const portion = (row?.portion ?? 'plate').toString().toLowerCase();
          const qty = parseQuantity(row?.qty);
          if (!name || qty <= 0) {
            return null;
          }
          return {
            name,
            portion: PORTION_PRICES[portion] ? portion : 'plate',
            qty
          };
        })
        .filter(Boolean);
    }

    function groupSelections(mains){
      const grouped = {};
      mains.forEach((item) => {
        const key = `${item.name}|${item.portion}`;
        if (!grouped[key]) {
          grouped[key] = { ...item, qty: 0 };
        }
        grouped[key].qty += item.qty;
      });
      return Object.values(grouped);
    }

    function groupMainNames(mains){
      const grouped = {};
      mains.forEach((item) => {
        const key = item.name;
        if (!grouped[key]) {
          grouped[key] = { name: item.name, qty: 0 };
        }
        grouped[key].qty += item.qty;
      });
      return Object.values(grouped);
    }

    // Your existing menu data
    let MENU_DAYS = [
      { key: "2025-11-30", enDay: "Sunday Nov 30", arDay: "الأحد ٣٠ نوفمبر", salad: "Green Salad", dessert: "Chocolate Cake", mains: ["Fajita", "Fassolia with meat", "Fassolia with oil"] },
      { key: "2025-12-01", enDay: "Monday Dec 1", arDay: "الاثنين ١ ديسمبر", salad: "Tabbouleh", dessert: "Tarte", mains: ["Chicken supreme", "Pasta bolognese", "Eggplant msakaa"] },
      { key: "2025-12-02", enDay: "Tuesday Dec 2", arDay: "الثلاثاء ٢ ديسمبر", salad: "Greek Salad", dessert: "Orange Cake", mains: ["Daoud bacha with rice", "Mehchi koussa", "Fish fillet with vegetables"] },
      { key: "2025-12-03", enDay: "Wednesday Dec 3", arDay: "الأربعاء ٣ ديسمبر", salad: "Fattouch", dessert: "Cookies", mains: ["Beef stroganoff", "Chicken shawarma", "Loubye with oil"] },
      { key: "2025-12-04", enDay: "Thursday Dec 4", arDay: "الخميس ٤ ديسمبر", salad: "Green Salad", dessert: "Banana cake", mains: ["Chicken kaju nuts", "Potato souffle", "Pasta pesto"] },
      { key: "2025-12-06", enDay: "Saturday Dec 6", arDay: "السبت ٦ ديسمبر", salad: "Tabbouleh", dessert: "Vanilla cake", mains: ["Falafel", "Mix Kabab", "Shish Taouk"] },

      { key: "2025-12-07", enDay: "Sunday Dec 7", arDay: "الأحد ٧ ديسمبر", salad: "Fresh Salad", dessert: "Lazy cake", mains: ["Chicken stroganoff", "Kabab orfali", "Noodles vegetables"] },
      { key: "2025-12-08", enDay: "Monday Dec 8", arDay: "الاثنين ٨ ديسمبر", salad: "Rocca Salad", dessert: "Muffins", mains: ["Vine leaves with meat", "Chicken alfredo", "Mjadara"] },
      { key: "2025-12-09", enDay: "Tuesday Dec 9", arDay: "الثلاثاء ٩ ديسمبر", salad: "Tabbouleh", dessert: "Chocolate cake", mains: ["Kebbeh bi laban", "Chicken biryani", "Okra with oil"] },
      { key: "2025-12-10", enDay: "Wednesday Dec 10", arDay: "الأربعاء ١٠ ديسمبر", salad: "Halloumi Salad", dessert: "Brownies", mains: ["Coconut chicken curry", "Philadelphia", "Penne Arrabbiata"] },
      { key: "2025-12-11", enDay: "Thursday Dec 11", arDay: "الخميس ١١ ديسمبر", salad: "Green Salad", dessert: "Cake", mains: ["Lasagna", "Spinach with rice", "Mdardara"] },
      { key: "2025-12-13", enDay: "Saturday Dec 13", arDay: "السبت ١٣ ديسمبر", salad: "Fattouch", dessert: "Vanilla cake", mains: ["Grilled kafta", "Shrimp with rice", "Pumpkin kebbeh"] },

      { key: "2025-12-14", enDay: "Sunday Dec 14", arDay: "الأحد ١٤ ديسمبر", salad: "Green salad", dessert: "Carrot Cake", mains: ["Oriental rice with meat", "Noodles chicken", "Coconut shrimp curry"] },
      { key: "2025-12-15", enDay: "Monday Dec 15", arDay: "الاثنين ١٥ ديسمبر", salad: "Quinoa salad", dessert: "Tarte", mains: ["Mehchi Malfouf", "Mashed potato with meat balls", "Eggplant Msakaa"] },
      { key: "2025-12-16", enDay: "Tuesday Dec 16", arDay: "الثلاثاء ١٦ ديسمبر", salad: "Fattouch", dessert: "Lazy cake", mains: ["Chich barak with rice", "Grilled kafta", "Creamy shrimp pasta"] },
      { key: "2025-12-17", enDay: "Wednesday Dec 17", arDay: "الأربعاء ١٧ ديسمبر", salad: "Green salad", dessert: "Cookies", mains: ["Moughrabiye", "Pasta bolognese", "Fassolia with oil"] },
      { key: "2025-12-18", enDay: "Thursday Dec 18", arDay: "الخميس ١٨ ديسمبر", salad: "Green salad", dessert: "Brownies", mains: ["Kabab khishkhash", "Meat balls with mashed", "Shrimp kaju nuts"] },
      { key: "2025-12-20", enDay: "Saturday Dec 20 (Qatar National Day)", arDay: "السبت ٢٠ ديسمبر", salad: "Green salad", dessert: "Vanilla cake", mains: ["Grilled chicken", "Beef burger", "Shish taouk"] },

      { key: "2025-12-21", enDay: "Sunday Dec 21", arDay: "الأحد ٢١ ديسمبر", salad: "Fattouch", dessert: "Sfouf", mains: ["Siyadiye", "Kafta bi tahini", "Eggplant msakaa"] },
      { key: "2025-12-22", enDay: "Monday Dec 22", arDay: "الاثنين ٢٢ ديسمبر", salad: "Tabbouleh", dessert: "Chocolate cake", mains: ["Kafta with potato", "Chicken alfredo", "Mjadara"] },
      { key: "2025-12-23", enDay: "Tuesday Dec 23", arDay: "الثلاثاء ٢٣ ديسمبر", salad: "Malfouf salad or Green salad", dessert: "Rice pudding", mains: ["Kebbeh bil sayniye", "Fish and chips", "Shawarma chicken"] },
      { key: "2025-12-24", enDay: "Wednesday Dec 24", arDay: "الأربعاء ٢٤ ديسمبر", salad: "Fattouch", dessert: "Lazy cake", mains: ["Chicken nouille", "Kabab khichkhach", "Pasta pesto"] },
      { key: "2025-12-25", enDay: "Thursday Dec 25", arDay: "الخميس ٢٥ ديسمبر", salad: "Greek salad", dessert: "Tarte", mains: ["Chicken kaju nuts", "Philadelphia", "Pumpkin kebbeh"] },
      { key: "2025-12-27", enDay: "Saturday Dec 27 (Christmas Holiday)", arDay: "السبت ٢٧ ديسمبر", salad: "Green Salad", dessert: "Brownies", mains: ["Mix Kabab", "Philadelphia", "Fish fillet with vegetables"] },

      { key: "2025-12-28", enDay: "Sunday Dec 28", arDay: "الأحد ٢٨ ديسمبر", salad: "Fresh salad", dessert: "Orange cake", mains: ["Frikeh chicken", "Shawarma beef", "Loubye bi zeit"] },
      { key: "2025-12-29", enDay: "Monday Dec 29", arDay: "الاثنين ٢٩ ديسمبر", salad: "Tabbouleh", dessert: "Cookies", mains: ["Roast beef with mashed potato", "Noodles chicken", "Bazella with rice"] },
      { key: "2025-12-30", enDay: "Tuesday Dec 30", arDay: "الثلاثاء ٣٠ ديسمبر", salad: "Fresh salad", dessert: "Tarte", mains: ["Chicken biryani", "Sheikh el mehchi", "Falafel"] },
      { key: "2025-12-31", enDay: "Wednesday Dec 31", arDay: "الأربعاء ٣١ ديسمبر", salad: "Fattouch", dessert: "Lazy cake", mains: ["Moughrabiye", "Chicken Alfredo", "Fish and chips"] },
    ];

    const menuGrid = document.getElementById("menuGrid");
    const menuLoader = document.getElementById("menuLoader");
    const mealPlanStatus = document.getElementById("mealPlanStatus");
    const mealPlanRadios = document.querySelectorAll('input[name="mealPlan"]');

    const API_MENU_URL = 'api/daily-dish/menu.php';
    const API_ORDER_URL = 'api/daily-dish/order.php';

    // Meal plan change handler
    mealPlanRadios.forEach(radio => {
      radio.addEventListener('change', () => {
        selectedMealPlan = radio.value || null;
        updateMealPlanStatus();
        updateAllCardStates();
      });
    });

    function updateMealPlanStatus(){
      if (!selectedMealPlan) {
        mealPlanStatus.classList.remove('show');
        return;
      }
      mealPlanStatus.classList.add('show');
      mealPlanStatus.className = 'meal-plan-status show info';
      const planPrice = PLAN_PRICES[selectedMealPlan] ?? 0;
      mealPlanStatus.textContent = selectedMealPlan === '20'
        ? `Meal plan request: 20 meals (QAR ${formatPrice(planPrice)} per meal). Our team will contact you to finalize.`
        : `Meal plan request: 26 meals (QAR ${formatPrice(planPrice)} per meal). Our team will contact you to finalize.`;
    }

    function updateAllCardStates(){
      document.querySelectorAll(".day-card").forEach((card, idx) => {
        applyMealPlanRules(card, idx);
        updateCardSelectionState(card, idx);
      });
    }

    function countSelectedMeals(){
      const cards = document.querySelectorAll('.day-card:not(.disabled)');
      let totalMeals = 0;
      cards.forEach((card, idx) => {
        const selection = getCardSelections(card, idx);
        totalMeals += selection.mainCount;
      });
      return totalMeals;
    }

    function getCardSelections(card, idx){
      const mainRows = Array.from(card.querySelectorAll(`[data-role="main-row"]`));
      const mains = [];
      mainRows.forEach(row => {
        const qty = parseQuantity(row.dataset.qty);
        if (qty > 0) {
          mains.push({
            name: row.dataset.mainName || '',
            portion: row.dataset.portion || 'plate',
            qty
          });
        }
      });

      const saladRow = card.querySelector(`[data-role="salad-row"]`);
      const dessertRow = card.querySelector(`[data-role="dessert-row"]`);
      const saladQty = parseQuantity(saladRow?.dataset?.qty);
      const dessertQty = parseQuantity(dessertRow?.dataset?.qty);
      const mainCount = mains.reduce((sum, item) => sum + item.qty, 0);
      const hasNonPlate = mains.some(item => item.portion !== 'plate');

      return { mains, saladQty, dessertQty, mainCount, hasNonPlate };
    }

    function applyMealPlanRules(card, idx){
      if (card.classList.contains('disabled')) {
        return;
      }

      const mainRows = Array.from(card.querySelectorAll(`[data-role="main-row"]`));
      const saladRow = card.querySelector(`[data-role="salad-row"]`);
      const dessertRow = card.querySelector(`[data-role="dessert-row"]`);

      if (selectedMealPlan) {
        let plateCount = 0;
        mainRows.forEach(row => {
          if (row.dataset.portion !== 'plate') {
            setRowQty(row, 0);
            setRowDisabled(row, true);
            return;
          }
          setRowDisabled(row, false);
          plateCount += parseQuantity(row.dataset.qty);
        });
        if (saladRow) {
          setRowQty(saladRow, plateCount);
          setRowDisabled(saladRow, true);
        }
        if (dessertRow) {
          setRowQty(dessertRow, plateCount);
          setRowDisabled(dessertRow, true);
        }
        return;
      }

      mainRows.forEach(row => {
        setRowDisabled(row, false);
      });
      setRowDisabled(saladRow, false);
      setRowDisabled(dessertRow, false);

      const selection = getCardSelections(card, idx);
      if (selection.mainCount === 0) {
        setRowQty(saladRow, 0);
        setRowQty(dessertRow, 0);
        return;
      }

      if (!selection.hasNonPlate) {
        const maxQty = selection.mainCount;
        if (saladRow && parseQuantity(saladRow.dataset.qty) > maxQty) {
          setRowQty(saladRow, maxQty);
        }
        if (dessertRow && parseQuantity(dessertRow.dataset.qty) > maxQty) {
          setRowQty(dessertRow, maxQty);
        }
      }
    }

    function buildBundlesFromSelections(mains, saladQty, dessertQty){
      const mainsWithQty = [];
      mains.forEach((item) => {
        if (item.portion !== 'plate') {
          return;
        }
        for (let i = 0; i < item.qty; i++) {
          mainsWithQty.push(item.name);
        }
      });

      let remainingSalads = Math.min(saladQty, mainsWithQty.length);
      let remainingDesserts = Math.min(dessertQty, mainsWithQty.length);

      const bundles = [];
      let mainIndex = 0;

      while (mainIndex < mainsWithQty.length && remainingSalads > 0 && remainingDesserts > 0) {
        bundles.push({
          type: 'full',
          main: mainsWithQty[mainIndex],
          salad: true,
          dessert: true,
          quantity: 1
        });
        mainIndex++;
        remainingSalads--;
        remainingDesserts--;
      }

      while (mainIndex < mainsWithQty.length && remainingSalads > 0) {
        bundles.push({
          type: 'mainSalad',
          main: mainsWithQty[mainIndex],
          salad: true,
          dessert: false,
          quantity: 1
        });
        mainIndex++;
        remainingSalads--;
      }

      while (mainIndex < mainsWithQty.length && remainingDesserts > 0) {
        bundles.push({
          type: 'mainDessert',
          main: mainsWithQty[mainIndex],
          salad: false,
          dessert: true,
          quantity: 1
        });
        mainIndex++;
        remainingDesserts--;
      }

      while (mainIndex < mainsWithQty.length) {
        bundles.push({
          type: 'mainOnly',
          main: mainsWithQty[mainIndex],
          salad: false,
          dessert: false,
          quantity: 1
        });
        mainIndex++;
      }

      return bundles;
    }

    function getBundleLabel(type){
      if (type === 'full') return 'Full Meal';
      if (type === 'mainSalad') return 'Main + Salad';
      if (type === 'mainDessert') return 'Main + Dessert';
      return 'Main Only';
    }

    function computeDayPricing(selection, mealPlan){
      const mains = normalizeMainSelections(selection.mains);
      const saladQty = parseQuantity(selection.saladQty);
      const dessertQty = parseQuantity(selection.dessertQty);
      const mainCount = mains.reduce((sum, item) => sum + item.qty, 0);
      const hasNonPlate = mains.some(item => item.portion !== 'plate');

      if (mainCount === 0) {
        return { mode: 'none', total: 0, mainCount: 0, mains, saladQty, dessertQty };
      }

      if (mealPlan) {
        const planPrice = PLAN_PRICES[mealPlan] ?? 0;
        return {
          mode: 'plan',
          total: roundMoney(planPrice * mainCount),
          planPrice,
          mainCount,
          mains,
          saladQty: mainCount,
          dessertQty: mainCount
        };
      }

      if (hasNonPlate) {
        const portionLines = groupSelections(mains).map((item) => {
          const unitPrice = PORTION_PRICES[item.portion] ?? PORTION_PRICES.plate;
          const lineTotal = roundMoney(unitPrice * item.qty);
          return {
            ...item,
            unitPrice,
            lineTotal
          };
        });

        let total = portionLines.reduce((sum, line) => sum + line.lineTotal, 0);
        const addonLines = [];

        if (saladQty > 0) {
          const lineTotal = roundMoney(saladQty * ADDON_PRICES.salad);
          addonLines.push({ type: 'salad', qty: saladQty, unitPrice: ADDON_PRICES.salad, lineTotal });
          total += lineTotal;
        }

        if (dessertQty > 0) {
          const lineTotal = roundMoney(dessertQty * ADDON_PRICES.dessert);
          addonLines.push({ type: 'dessert', qty: dessertQty, unitPrice: ADDON_PRICES.dessert, lineTotal });
          total += lineTotal;
        }

        return {
          mode: 'portion',
          total: roundMoney(total),
          mainCount,
          mains,
          portionLines,
          addonLines,
          saladQty,
          dessertQty
        };
      }

      const bundleGroups = {};
      const bundles = buildBundlesFromSelections(mains, saladQty, dessertQty);
      bundles.forEach((bundle) => {
        const key = `${bundle.type}|${bundle.main}`;
        if (!bundleGroups[key]) {
          bundleGroups[key] = { ...bundle, qty: 0 };
        }
        bundleGroups[key].qty += 1;
      });

      let total = 0;
      const bundleLines = Object.values(bundleGroups).map((bundle) => {
        const unitPrice = BUNDLE_PRICES[bundle.type] ?? BUNDLE_PRICES.full;
        const lineTotal = roundMoney(unitPrice * bundle.qty);
        total += lineTotal;
        return {
          ...bundle,
          unitPrice,
          lineTotal
        };
      });

      return {
        mode: 'bundle',
        total: roundMoney(total),
        mainCount,
        mains,
        bundleLines,
        saladQty,
        dessertQty
      };
    }

    async function loadMenu(){
      menuLoader?.classList.add('show');
      menuGrid.classList.add('loading');
      try{
        const res = await fetch(API_MENU_URL);
        const json = await res.json();
        if (json.success && Array.isArray(json.data)) {
          MENU_DAYS = json.data;
        }
      }catch(err){
        console.warn('Menu load failed, using bundled data', err);
      }finally{
        renderMenu();
        menuGrid.classList.remove('loading');
        menuLoader?.classList.remove('show');
      }
    }

    function escapeHtml(str){
      return String(str)
        .replaceAll("&","&amp;")
        .replaceAll("<","&lt;")
        .replaceAll(">","&gt;")
        .replaceAll('"',"&quot;")
        .replaceAll("'","&#039;");
    }

    function renderMenu(){
      menuGrid.innerHTML = "";
      const todayKey = new Date().toISOString().slice(0,10);

      const futureDays = [];
      const pastDays = [];

      MENU_DAYS.forEach((day, idx) => {
        const isPast = day.key < todayKey;
        const card = buildDayCard(day, idx, isPast);
        if (isPast) pastDays.push(card); else futureDays.push(card);
      });

      if (pastDays.length) {
        const wrapper = document.createElement('div');
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'past-toggle';
        toggle.innerHTML = `<span>Show previous days</span><span class="count">${pastDays.length}</span>`;
        const list = document.createElement('div');
        list.className = 'past-list';
        toggle.addEventListener('click', () => {
          const showing = list.classList.toggle('show');
          toggle.firstChild.textContent = showing ? 'Hide previous days' : 'Show previous days';
        });
        wrapper.appendChild(toggle);
        wrapper.appendChild(list);
        pastDays.forEach(card => list.appendChild(card));
        menuGrid.appendChild(wrapper);
      }

      futureDays.forEach(card => menuGrid.appendChild(card));
    }

    function buildDayCard(day, idx, isPast){
        const card = document.createElement("div");
        card.className = "day-card";
        card.dataset.key = day.key;
        if (isPast) {
          card.classList.add('disabled');
        }

        card.innerHTML = `
          <div class="day-head">
            <div class="day-pill">
              <span>${escapeHtml(day.enDay)}</span>
              <small>• ${escapeHtml(day.key)}</small>
            </div>
            <span class="day-status" data-role="status">Not selected</span>
            <button class="clear-day" type="button" data-role="clear-day">Clear day</button>
          </div>

          <div class="day-body">
            <div class="col full-width">
              <div class="section-title dual">
                <span class="st-en">Select Quantities</span>
                <span class="st-ar">اختر الكميات</span>
              </div>
              
              <div class="section-head">
                <div class="section-title dual" style="margin:0;">
                  <span class="st-en">Main Dishes</span>
                  <span class="st-ar">الأطباق الرئيسية</span>
                </div>
                <button class="section-clear" type="button" data-role="clear-section" data-target="main">Clear</button>
              </div>
              <div class="choice-group">
                ${day.mains.map((m) => {
                  const portions = [
                    { key: 'full', label: 'Full Portion' },
                    { key: 'half', label: 'Half Portion' },
                    { key: 'plate', label: 'Plate' },
                  ];
                  return `
                    <div class="item-row">
                      <div class="item-info">
                        <div class="item-name-en">${escapeHtml(m)}</div>
                      </div>
                      <div class="item-options">
                        <div class="item-name-ar">${escapeHtml(getArTranslation(m))}</div>
                        ${portions.map((portion) => `
                          <label class="quantity-choice option-row"
                                 data-role="main-row"
                                 data-main-name="${escapeHtml(m)}"
                                 data-portion="${portion.key}"
                                 data-qty="0">
                            <span class="option-label">${portion.label}</span>
                            <span class="qty-display" data-role="qty-display"></span>
                            <input type="checkbox"
                                   class="increment-check"
                                   data-role="increment"
                                   aria-label="Add ${portion.label} for ${escapeHtml(m)}" />
                          </label>
                        `).join("")}
                      </div>
                    </div>
                  `;
                }).join("")}
              </div>

              <div class="section-head">
                <div class="section-title dual" style="margin:0;">
                  <span class="st-en">Salad</span>
                  <span class="st-ar">سلطة</span>
                </div>
                <button class="section-clear" type="button" data-role="clear-section" data-target="salad">Clear</button>
              </div>
              <div class="choice-group">
                <div class="item-row">
                  <div class="item-info">
                    <div class="item-name-en">${escapeHtml(day.salad)}</div>
                  </div>
                  <div class="item-options">
                    <div class="item-name-ar">${escapeHtml(getArTranslation(day.salad))}</div>
                    <label class="quantity-choice option-row"
                           data-role="salad-row"
                           data-qty="0">
                      <span class="option-label">Plate</span>
                      <span class="qty-display" data-role="qty-display"></span>
                      <input type="checkbox"
                             class="increment-check"
                             data-role="increment"
                             aria-label="Add salad" />
                    </label>
                  </div>
                </div>
              </div>

              <div class="section-head">
                <div class="section-title dual" style="margin:0;">
                  <span class="st-en">Dessert</span>
                  <span class="st-ar">تحلية</span>
                </div>
                <button class="section-clear" type="button" data-role="clear-section" data-target="dessert">Clear</button>
              </div>
              <div class="choice-group">
                <div class="item-row">
                  <div class="item-info">
                    <div class="item-name-en">${escapeHtml(day.dessert)}</div>
                  </div>
                  <div class="item-options">
                    <div class="item-name-ar">${escapeHtml(getArTranslation(day.dessert))}</div>
                    <label class="quantity-choice option-row"
                           data-role="dessert-row"
                           data-qty="0">
                      <span class="option-label">Plate</span>
                      <span class="qty-display" data-role="qty-display"></span>
                      <input type="checkbox"
                             class="increment-check"
                             data-role="increment"
                             aria-label="Add dessert" />
                    </label>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="bundle-preview" data-role="bundle-preview" style="margin-top:12px;padding:10px;background:#f8f9fa;border-radius:8px;font-size:12px;display:none;"></div>
        `;

        wireCardLogic(card, idx);
        updateCardSelectionState(card, idx);
        return card;
    }

    function wireCardLogic(card, idx){
      if (card.classList.contains('disabled')) {
        return;
      }

      const incrementers = Array.from(card.querySelectorAll(`[data-role="increment"]`));
      const rows = Array.from(card.querySelectorAll(`.quantity-choice`));
      const sectionClears = Array.from(card.querySelectorAll(`[data-role="clear-section"]`));
      const clearBtn = card.querySelector('[data-role="clear-day"]');

      function handleChange(){
        applyMealPlanRules(card, idx);
        updateCardSelectionState(card, idx);
        selectedMealCount = countSelectedMeals();
        updateMealPlanStatus();
      }

      incrementers.forEach((checkbox) => {
        checkbox.addEventListener("click", (event) => {
          event.preventDefault();
          const row = checkbox.closest('.quantity-choice');
          if (!row || row.classList.contains('disabled')) {
            return;
          }
          incrementRow(row);
          checkbox.checked = false;
          handleChange();
        });
      });

      sectionClears.forEach((button) => {
        button.addEventListener("click", () => {
          const target = button.dataset.target;
          if (target === 'main') {
            card.querySelectorAll(`[data-role="main-row"]`).forEach(row => setRowQty(row, 0));
          } else if (target === 'salad') {
            setRowQty(card.querySelector(`[data-role="salad-row"]`), 0);
          } else if (target === 'dessert') {
            setRowQty(card.querySelector(`[data-role="dessert-row"]`), 0);
          }

          applyMealPlanRules(card, idx);
          updateCardSelectionState(card, idx);
          selectedMealCount = countSelectedMeals();
          updateMealPlanStatus();
        });
      });

      clearBtn.addEventListener("click", () => {
        rows.forEach(row => setRowQty(row, 0));

        applyMealPlanRules(card, idx);
        updateCardSelectionState(card, idx);
        selectedMealCount = countSelectedMeals();
        updateMealPlanStatus();
      });
    }

    function updateCardSelectionState(card, idx){
      const selection = getCardSelections(card, idx);
      const pricing = computeDayPricing({
        mains: selection.mains,
        saladQty: selection.saladQty,
        dessertQty: selection.dessertQty
      }, selectedMealPlan);
      const selected = pricing.mainCount > 0;

      const status = card.querySelector('[data-role="status"]');
      const preview = card.querySelector('[data-role="bundle-preview"]');

      card.classList.toggle("selected", selected);
      status.classList.toggle("active", selected);

      if (selected) {
        status.textContent = `Selected (${pricing.mainCount} meal${pricing.mainCount !== 1 ? 's' : ''})`;

        const day = MENU_DAYS[idx] || {};
        const lines = [];

        if (pricing.mode === 'plan') {
          const mainNames = groupMainNames(pricing.mains)
            .map((item) => `${escapeHtml(item.name)} x${item.qty}`)
            .join(', ');
          lines.push(`<strong>Meal Plan:</strong> ${pricing.mainCount} meal${pricing.mainCount !== 1 ? 's' : ''} x QAR ${formatPrice(pricing.planPrice)}`);
          if (mainNames) {
            lines.push(`Mains: ${mainNames}`);
          }
          if (day.salad) {
            lines.push(`Salad: ${escapeHtml(day.salad)} x${pricing.mainCount}`);
          }
          if (day.dessert) {
            lines.push(`Dessert: ${escapeHtml(day.dessert)} x${pricing.mainCount}`);
          }
        } else if (pricing.mode === 'portion') {
          const portionTexts = pricing.portionLines.map((line) => {
            const qtyText = line.qty > 1 ? ` x${line.qty}` : '';
            const priceText = line.qty > 1
              ? ` = QAR ${formatPrice(line.lineTotal)}`
              : ` (QAR ${formatPrice(line.unitPrice)})`;
            return `${formatPortionLabel(line.portion)}: ${escapeHtml(line.name)}${qtyText}${priceText}`;
          });
          lines.push(`<strong>Portions:</strong> ${portionTexts.join('; ')}`);
          pricing.addonLines.forEach((addon) => {
            const label = addon.type === 'salad' ? day.salad : day.dessert;
            if (label) {
              const qtyText = addon.qty > 1 ? ` x${addon.qty}` : '';
              const priceText = addon.qty > 1
                ? ` = QAR ${formatPrice(addon.lineTotal)}`
                : ` (QAR ${formatPrice(addon.unitPrice)})`;
              lines.push(`${addon.type === 'salad' ? 'Salad' : 'Dessert'}: ${escapeHtml(label)}${qtyText}${priceText}`);
            }
          });
        } else if (pricing.mode === 'bundle') {
          const bundleTexts = pricing.bundleLines.map((bundle) => {
            const qtyText = bundle.qty > 1 ? ` x${bundle.qty}` : '';
            const priceText = bundle.qty > 1
              ? ` = QAR ${formatPrice(bundle.lineTotal)}`
              : ` (QAR ${formatPrice(bundle.unitPrice)})`;
            return `${getBundleLabel(bundle.type)}: ${escapeHtml(bundle.main)}${qtyText}${priceText}`;
          });
          lines.push(`<strong>Bundles:</strong> ${bundleTexts.join('; ')}`);
        }

        preview.innerHTML = `
          <div style="margin-bottom:6px;">${lines.map(line => `<div>${line}</div>`).join('')}</div>
          <div style="margin-top:6px;padding-top:6px;border-top:1px solid rgba(0,0,0,0.1);font-weight:600;color:var(--blue);">
            Day Total: QAR ${formatPrice(pricing.total)}
          </div>
        `;
        preview.style.display = 'block';
      } else {
        status.textContent = "Not selected";
        preview.style.display = 'none';
      }
    }

    loadMenu();

    // Initialize meal count after menu loads
    setTimeout(() => {
      updateMealPlanStatus();
      updateAllCardStates();
    }, 100);

    // ===== Summary / Form logic =====
    const form = document.getElementById("orderForm");
    const modal = document.getElementById("modal");
    const summaryBox = document.getElementById("summaryBox");
    const closeModal = document.getElementById("closeModal");
    const copySummary = document.getElementById("copySummary");
    const openWhatsApp = document.getElementById("openWhatsApp");
    const clearAllBtn = document.getElementById("clearBtn");
    const submitOrderBtn = document.getElementById("submitOrder");

    // Holds the last generated payload so user can review then submit.
    let lastOrderPayload = null;

    function collectFormData(){
      const fd = new FormData(form);
      const customerName = (fd.get("customerName") || "").toString().trim();
      const phone = (fd.get("phone") || "").toString().trim();
      const email = (fd.get("email") || "").toString().trim();
      const address = (fd.get("address") || "").toString().trim();
      const notes = (fd.get("notes") || "").toString().trim();
      const mealPlan = (fd.get("mealPlan") || "").toString().trim();

      const items = [];
      const errors = [];
      let total = 0;

      const cards = Array.from(document.querySelectorAll(".day-card"));

      cards.forEach((card, idx) => {
        const day = MENU_DAYS[idx];
        const selection = getCardSelections(card, idx);

        if ((selection.saladQty > 0 || selection.dessertQty > 0) && selection.mainCount === 0) {
          errors.push(`Please select a main dish for ${day.enDay}.`);
          return;
        }

        if (selection.mainCount === 0) {
          return;
        }

        const pricing = computeDayPricing({
          mains: selection.mains,
          saladQty: selection.saladQty,
          dessertQty: selection.dessertQty
        }, mealPlan || null);

        total += pricing.total;

        items.push({
          key: day.key,
          enDay: day.enDay,
          arDay: day.arDay,
          salad: day.salad,
          dessert: day.dessert,
          salad_qty: pricing.saladQty,
          dessert_qty: pricing.dessertQty,
          mains: selection.mains
        });
      });

      return { 
        customerName, 
        phone, 
        email,
        address, 
        notes, 
        items, 
        total: roundMoney(total),
        mealPlan: mealPlan || null,
        errors
      };
    }

    function buildSummary(data){
      const lines = [];
      lines.push("Layla Kitchen - Daily Dish Order");
      lines.push("================================");
      lines.push(`Name: ${data.customerName}`);
      lines.push(`Phone: ${data.phone}`);
      lines.push(`Email: ${data.email}`);
      lines.push(`Address: ${data.address}`);
      if (data.notes) lines.push(`Notes: ${data.notes}`);
      lines.push("");

      if (data.mealPlan) {
        lines.push(`Meal Plan Request: ${data.mealPlan} meals`);
        lines.push("");
      }

      if (!data.items.length){
        lines.push("No days selected.");
        return { text: lines.join("\n"), html: `<p>No days selected.</p>` };
      }

      lines.push("Selected Days:");
      lines.push("--------------");

      const itemsHtml = data.items.map((it, i) => {
        const pricing = computeDayPricing({
          mains: it.mains,
          saladQty: it.salad_qty,
          dessertQty: it.dessert_qty
        }, data.mealPlan);

        const mainsSummary = groupSelections(pricing.mains)
          .map((item) => `${item.name} (${formatPortionLabel(item.portion)} x${item.qty})`)
          .join(', ') || 'None';

        const saladLine = pricing.saladQty > 0
          ? `${it.salad} x${pricing.saladQty}`
          : 'None';
        const dessertLine = pricing.dessertQty > 0
          ? `${it.dessert} x${pricing.dessertQty}`
          : 'None';

        const pricingLines = [];
        if (pricing.mode === 'plan') {
          pricingLines.push(`Meal Plan: ${pricing.mainCount} meal${pricing.mainCount !== 1 ? 's' : ''} x QAR ${formatPrice(pricing.planPrice)}`);
        } else if (pricing.mode === 'portion') {
          pricing.portionLines.forEach((line) => {
            const qtyText = line.qty > 1 ? ` x${line.qty}` : '';
            const priceText = line.qty > 1
              ? ` = QAR ${formatPrice(line.lineTotal)}`
              : ` (QAR ${formatPrice(line.unitPrice)})`;
            pricingLines.push(`${formatPortionLabel(line.portion)}: ${line.name}${qtyText}${priceText}`);
          });
          pricing.addonLines.forEach((addon) => {
            const label = addon.type === 'salad' ? it.salad : it.dessert;
            const qtyText = addon.qty > 1 ? ` x${addon.qty}` : '';
            const priceText = addon.qty > 1
              ? ` = QAR ${formatPrice(addon.lineTotal)}`
              : ` (QAR ${formatPrice(addon.unitPrice)})`;
            pricingLines.push(`${addon.type === 'salad' ? 'Salad' : 'Dessert'}: ${label}${qtyText}${priceText}`);
          });
        } else if (pricing.mode === 'bundle') {
          pricing.bundleLines.forEach((bundle) => {
            const qtyText = bundle.qty > 1 ? ` x${bundle.qty}` : '';
            const priceText = bundle.qty > 1
              ? ` = QAR ${formatPrice(bundle.lineTotal)}`
              : ` (QAR ${formatPrice(bundle.unitPrice)})`;
            pricingLines.push(`${getBundleLabel(bundle.type)}: ${bundle.main}${qtyText}${priceText}`);
          });
        }

        lines.push(
          `${i+1}. ${it.enDay} (${it.key})\n` +
          `   Mains: ${mainsSummary}\n` +
          `   Salad: ${saladLine}\n` +
          `   Dessert: ${dessertLine}\n` +
          `   Pricing:\n` +
          pricingLines.map(line => `     - ${line}`).join("\n") + "\n" +
          `   Day Total: QAR ${formatPrice(pricing.total)}`
        );

        const pricingHtml = pricingLines.length
          ? pricingLines.map(line => escapeHtml(line)).join('<br>')
          : '';

        return `
          <div class="item">
            <div class="title">
              <span>${i+1}. ${escapeHtml(it.enDay)}</span>
              <span class="sub">${escapeHtml(it.key)}</span>
            </div>
            <ul>
              <li><strong>Mains:</strong> ${escapeHtml(mainsSummary)}</li>
              <li><strong>Salad:</strong> ${escapeHtml(saladLine)}</li>
              <li><strong>Dessert:</strong> ${escapeHtml(dessertLine)}</li>
              ${pricingHtml ? `<li><strong>Pricing:</strong><br>${pricingHtml}</li>` : ''}
              <li><strong>Day Total:</strong> QAR ${formatPrice(pricing.total)}</li>
            </ul>
          </div>
        `;
      }).join("");

      lines.push("");
      lines.push(`TOTAL: QAR ${formatPrice(data.total)}`);
      lines.push("");
      lines.push("Please place orders before 24 hours.");
      lines.push("Contact: 55683442");

      const planHtml = data.mealPlan
        ? `<div style="background:#fff;border:1px solid rgba(0,0,0,0.05);border-radius:12px;padding:12px;margin-bottom:12px;">
             <div style="font-weight:700;color:var(--blue);margin-bottom:6px;">Meal Plan Request: ${escapeHtml(data.mealPlan)} meals</div>
             <div style="font-size:13px;color:var(--ink);">Our team will contact you to finalize the subscription.</div>
           </div>`
        : '';

      const html = `
        <h4>Order Summary</h4>
        <div class="meta">
          <div><span>Name</span>${escapeHtml(data.customerName)}</div>
          <div><span>Phone</span>${escapeHtml(data.phone)}</div>
          <div><span>Email</span>${escapeHtml(data.email)}</div>
          <div><span>Address</span>${escapeHtml(data.address)}</div>
          ${data.notes ? `<div><span>Notes</span>${escapeHtml(data.notes)}</div>` : ''}
        </div>
        ${planHtml}
        <div class="items">${itemsHtml}</div>
        <div class="total">TOTAL: QAR ${formatPrice(data.total)}</div>
        <p class="note" style="margin-top:8px;color:${'var(--muted)'};font-size:12px;">Please place orders before 24 hours. Contact: 55683442</p>
      `;

      return { text: lines.join("\n"), html };
    }

    form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const data = collectFormData();

      if (data.errors && data.errors.length) {
        alert(data.errors[0]);
        return;
      }

      if (!data.items.length){
        alert("Please choose at least one main dish for any day.");
        return;
      }

      delete data.errors;

      // reCAPTCHA disabled for now; dashboard will accept requests without a token.
      // If you re-enable it later, restore token generation here.

      const summaryObj = buildSummary(data);
      summaryBox.innerHTML = summaryObj.html;
      summaryBox.dataset.summary = summaryObj.text;

      lastOrderPayload = data;

      modal.classList.add("show");
      modal.setAttribute("aria-hidden", "false");
    });

    async function submitOrder(){
      if (!lastOrderPayload) {
        alert("Please generate the order summary first.");
        return;
      }

      submitOrderBtn.disabled = true;
      const oldText = submitOrderBtn.textContent;
      submitOrderBtn.textContent = "Submitting...";

      try{
        const res = await fetch(API_ORDER_URL, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify(lastOrderPayload)
        });

        const json = await res.json().catch(() => ({}));
        if (!res.ok || json.success === false) {
          throw new Error(json.message || "Failed to submit order.");
        }

        submitOrderBtn.textContent = "Submitted";
      }catch(err){
        console.warn('Order API error', err);
        alert("Failed to submit order. Please try again or use WhatsApp draft.");
        submitOrderBtn.disabled = false;
        submitOrderBtn.textContent = oldText;
      }
    }

    submitOrderBtn.addEventListener("click", submitOrder);

    closeModal.addEventListener("click", () => {
      modal.classList.remove("show");
      modal.setAttribute("aria-hidden", "true");
    });

    modal.addEventListener("click", (e) => {
      if (e.target === modal){
        modal.classList.remove("show");
        modal.setAttribute("aria-hidden", "true");
      }
    });

    copySummary.addEventListener("click", async () => {
      const text = summaryBox.dataset.summary || summaryBox.textContent;
      try{
        await navigator.clipboard.writeText(text);
        copySummary.textContent = "Copied!";
        setTimeout(() => copySummary.textContent = "Copy Summary", 1200);
      }catch{
        alert("Copy failed. You can manually select and copy the text.");
      }
    });

    openWhatsApp.addEventListener("click", () => {
      const text = encodeURIComponent(summaryBox.dataset.summary || summaryBox.textContent);
      const wa = `https://wa.me/97455683442?text=${text}`;
      window.open(wa, "_blank");
    });

    clearAllBtn.addEventListener("click", () => {
      form.reset();
      selectedMealPlan = null;

      document.querySelectorAll(".day-card").forEach((card, idx) => {
        const rows = card.querySelectorAll(`.quantity-choice`);
        rows.forEach(row => setRowQty(row, 0));
      });

      selectedMealCount = 0;
      updateMealPlanStatus();
      updateAllCardStates();
    });
  </script>
</body>
</html>


