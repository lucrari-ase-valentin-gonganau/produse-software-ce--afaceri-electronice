{extends file='page.tpl'}

{block name='page_title'}Creaza propriul tau PC{/block}

{block name='page_content'}
  <div id="ae-builder" class="ae-builder" data-url="{$ae_builder_url|escape:'html':'UTF-8'}" data-start="{$ae_start|intval}">
    <p class="ae-builder__intro">
      Alege componentele in orice ordine. La fiecare pas vezi <strong>doar produsele compatibile</strong> cu ce ai ales deja:
      socket-ul procesorului si al placii de baza, tipul memoriei (DDR4 sau DDR5), formatul placii si al carcasei
      si puterea sursei, calculata din consumul fiecarei componente alese (plus 30% rezerva).
    </p>
    <div class="ae-builder__layout">
      <div class="ae-builder__slots" aria-live="polite">
        <p class="ae-builder__loading">Se incarca componentele...</p>
      </div>
      <aside class="ae-builder__summary card"></aside>
    </div>
    <noscript><p class="alert alert-warning">Configuratorul are nevoie de JavaScript.</p></noscript>
  </div>
{/block}
