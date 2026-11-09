<!doctype html>
<html lang="ro">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>AE Pay - plata cu cardul (simulator)</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
      font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1f2430; background: #eef1f7; }
    .pay { width: 100%; max-width: 440px; background: #fff; border-radius: 14px; box-shadow: 0 10px 30px rgba(20, 30, 60, .12); overflow: hidden; }
    .pay__head { padding: 18px 24px; background: #1d2b64; color: #fff; display: flex; justify-content: space-between; align-items: center; }
    .pay__brand { font-weight: 700; font-size: 18px; letter-spacing: .5px; }
    .pay__badge { font-size: 12px; padding: 2px 8px; border-radius: 99px; background: rgba(255, 255, 255, .18); }
    .pay__body { padding: 24px; }
    .pay__amount { text-align: center; margin: 0 0 20px; }
    .pay__amount small { display: block; color: #6b7280; }
    .pay__amount strong { font-size: 32px; }
    dl { margin: 0 0 20px; display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; }
    dt { color: #6b7280; }
    dd { margin: 0; text-align: right; overflow-wrap: anywhere; }
    .pay__card { margin: 0 0 20px; padding: 12px 14px; border: 1px dashed #c7cde0; border-radius: 10px; color: #4b5563; font-size: 13px; }
    .pay__actions { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    button { padding: 12px; border: 0; border-radius: 10px; font: inherit; font-weight: 600; cursor: pointer; color: #fff; }
    .pay__ok { background: #15803d; }
    .pay__ok:hover { background: #166534; }
    .pay__ko { background: #b91c1c; }
    .pay__ko:hover { background: #991b1b; }
    .pay__note { margin: 16px 0 0; text-align: center; font-size: 12px; color: #6b7280; }
    .pay__error { color: #b91c1c; }
  </style>
</head>
<body>
  <main class="pay">
    <div class="pay__head">
      <span class="pay__brand">AE Pay</span>
      <span class="pay__badge">simulator</span>
    </div>
    <div class="pay__body">
      {if $ae_valid}
        <p class="pay__amount"><small>De plata catre {$ae_request.comerciant}</small><strong>{$ae_amount}</strong></p>
        <dl>
          <dt>Comerciant</dt><dd>{$ae_request.comerciant}</dd>
          <dt>Comanda</dt><dd>{$ae_request.referinta}</dd>
          <dt>Descriere</dt><dd>{$ae_request.descriere}</dd>
          <dt>Client</dt><dd>{$ae_request.client}</dd>
          <dt>E-mail</dt><dd>{$ae_request.email}</dd>
          <dt>Suma</dt><dd>{$ae_request.suma} {$ae_request.moneda}</dd>
        </dl>
        <p class="pay__card">Card de test: 4111 1111 1111 1111, exp. 12/30, CVV 123. Alege rezultatul platii:</p>
        <form method="post" class="pay__actions">
          {foreach $ae_request as $name => $value}
            <input type="hidden" name="{$name}" value="{$value}">
          {/foreach}
          <button type="submit" name="rezultat" value="platit" class="pay__ok">Platit</button>
          <button type="submit" name="rezultat" value="neplatit" class="pay__ko">Neplatit</button>
        </form>
        <p class="pay__note">Procesator de plati demonstrativ: nu se efectueaza nicio plata reala.</p>
      {else}
        <p class="pay__error"><strong>Link de plata invalid.</strong></p>
        <p>Detaliile platii lipsesc sau au fost modificate (semnatura nu corespunde). Intoarce-te in magazin si reia plata
          din istoricul comenzilor.</p>
      {/if}
    </div>
  </main>
</body>
</html>
