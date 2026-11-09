{if $ae_card_paid}
  <div class="alert alert-success">
    Plata cu cardul de <strong>{$ae_card_total}</strong> pentru comanda <strong>{$ae_card_reference}</strong> a fost acceptata.
    {if $ae_card_transaction}<br>Tranzactie: <strong>{$ae_card_transaction}</strong>{/if}
  </div>
{else}
  <div class="alert alert-warning">
    Plata cu cardul pentru comanda <strong>{$ae_card_reference}</strong> nu a fost inca efectuata.
    {if $ae_card_pay_url}<br><a class="btn btn-primary" href="{$ae_card_pay_url}">Plateste acum</a>{/if}
  </div>
{/if}
