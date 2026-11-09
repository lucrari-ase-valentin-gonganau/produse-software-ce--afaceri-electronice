{extends file='page.tpl'}

{block name='page_title'}Plata nu a fost efectuata{/block}

{block name='page_content'}
  {if $ae_valid}
    <div class="alert alert-danger">
      Plata cu cardul pentru comanda <strong>{$ae_reference}</strong> nu a fost efectuata.
      Comanda este salvata, dar nu va fi procesata pana nu este platita.
    </div>
    {if $ae_pay_url}
      <p><a class="btn btn-primary" href="{$ae_pay_url}">Incearca din nou plata</a></p>
    {/if}
    <p>Poti plati si mai tarziu, din <a href="{$ae_history_url}">istoricul comenzilor</a>.
      Daca ai nevoie de ajutor, <a href="{$ae_contact_url}">contacteaza-ne</a>.</p>
  {else}
    <div class="alert alert-warning">
      Raspunsul procesatorului de plati nu este valid (semnatura sau detaliile comenzii nu corespund),
      asa ca starea comenzii nu a fost schimbata.
    </div>
    <p>Verifica starea comenzii in <a href="{$ae_history_url}">istoricul comenzilor</a>
      sau <a href="{$ae_contact_url}">contacteaza-ne</a>.</p>
  {/if}
{/block}
