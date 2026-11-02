<div id="ae-chat" class="ae-chat" data-url="{$ae_chat_url|escape:'html':'UTF-8'}" data-token="{$ae_chat_token|escape:'html':'UTF-8'}"
     data-builder="{$ae_builder_url|escape:'html':'UTF-8'}">
  <button type="button" class="ae-chat__toggle" aria-expanded="false" aria-controls="ae-chat-panel">
    <i class="material-icons" aria-hidden="true">chat</i>
    <span>Asistent</span>
  </button>
  <section class="ae-chat__panel" id="ae-chat-panel" aria-label="Asistentul magazinului" hidden>
    <header class="ae-chat__header">
      <strong>Asistent {$shop.name|escape:'html':'UTF-8'}</strong>
      <span>
        <button type="button" class="ae-chat__reset" title="Conversatie noua" aria-label="Conversatie noua"><i class="material-icons">refresh</i></button>
        <button type="button" class="ae-chat__close" title="Inchide" aria-label="Inchide"><i class="material-icons">close</i></button>
      </span>
    </header>
    <div class="ae-chat__messages" aria-live="polite"></div>
    <div class="ae-chat__suggestions">
      <button type="button">Ce placi de baza merg cu AMD Ryzen 5 7600?</button>
      <button type="button">Recomanda-mi o placa video sub 2500 lei</button>
      <button type="button">Ce sursa imi trebuie pentru i7-14700K si RTX 5070?</button>
    </div>
    <form class="ae-chat__form">
      <input type="text" name="message" maxlength="1000" autocomplete="off" placeholder="Intreaba despre produse..." aria-label="Mesaj" required>
      <button type="submit" aria-label="Trimite"><i class="material-icons">send</i></button>
    </form>
  </section>
</div>
