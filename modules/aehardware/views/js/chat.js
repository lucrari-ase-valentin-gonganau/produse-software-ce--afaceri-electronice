/**
 * Fereastra chatbotului (modules/aehardware). Conversatia se pastreaza in localStorage (ramane si dupa
 * inchiderea tab-ului, pana la butonul de conversatie noua); la fiecare intrebare se trimit serverului
 * ultimele mesaje (fara mesajele de eroare), ca asistentul sa tina minte ce s-a discutat.
 * Fereastra e adaugata la sfarsitul paginii (displayBeforeBodyClosingTag), dupa scripturi,
 * deci pornim dupa ce s-a incarcat toata pagina.
 */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var root = document.getElementById('ae-chat');
  if (!root) {
    return;
  }

  var STORAGE = 'aeChat';
  var GREETING = 'Salut! Te ajut sa gasesti produse, sa compari preturi sau sa verifici daca doua componente sunt compatibile. '
    + 'Pentru un calculator complet poti folosi si [Creaza propriul tau PC](' + root.dataset.builder + ').';
  var toggle = root.querySelector('.ae-chat__toggle');
  var panel = root.querySelector('.ae-chat__panel');
  var list = root.querySelector('.ae-chat__messages');
  var suggestions = root.querySelector('.ae-chat__suggestions');
  var form = root.querySelector('.ae-chat__form');
  var input = form.querySelector('input');
  var stored = load();
  var messages = stored.messages || [];
  var busy = false;

  function load() {
    try {
      return JSON.parse(window.localStorage.getItem(STORAGE)) || {};
    } catch (e) {
      return {};
    }
  }

  function save() {
    try {
      window.localStorage.setItem(STORAGE, JSON.stringify({messages: messages.slice(-40), open: !panel.hidden}));
    } catch (e) {
      // fara localStorage, conversatia se pierde la schimbarea paginii
    }
  }

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
  }

  /** Markdown simplu, dupa escape: linkuri http(s), **ingrosat**, titluri, liste */
  function markdown(text) {
    var html = esc(text)
      .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, function (match, label, href) {
        var external = href.indexOf(window.location.origin) !== 0;
        return '<a href="' + href + '"' + (external ? ' target="_blank" rel="noopener"' : '') + '>' + label + '</a>';
      })
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/^#{1,6}\s+(.*)$/gm, '<strong>$1</strong>');

    var out = [];
    var inList = false;
    html.split('\n').forEach(function (line) {
      var item = line.match(/^\s*(?:[-*]|\d+\.)\s+(.*)$/);
      if (item) {
        if (!inList) {
          out.push('<ul>');
          inList = true;
        }
        out.push('<li>' + item[1] + '</li>');
        return;
      }
      if (inList) {
        out.push('</ul>');
        inList = false;
      }
      if (line.trim() !== '') {
        out.push('<p>' + line + '</p>');
      }
    });
    if (inList) {
      out.push('</ul>');
    }

    return out.join('');
  }

  /**
   * Cardul configuratiei verificate (venit din unealta completeaza/verifica_configuratie, nu scris de model):
   * componentele cu link si pret, totalul, bugetul si butonul de cos.
   */
  function buildCard(build, index) {
    var rows = build.componente.map(function (item) {
      return '<li><span class="ae-build__label">' + esc(item.eticheta) + '</span> '
        + '<a href="' + esc(item.url) + '">' + esc(item.nume) + '</a> '
        + '<span class="ae-build__price">' + esc(item.pret) + '</span></li>';
    }).join('');
    var budget = build.ramane ? 'Ramane din buget: ' + build.ramane
      : (build.lipseste ? 'Lipseste fata de buget: ' + build.lipseste : '');
    var warnings = (build.probleme || []).map(function (problem) {
      return '<div class="ae-build__warning">' + esc(problem) + '</div>';
    }).join('');
    var button = build.completa
      ? '<button type="button" class="btn btn-primary btn-sm ae-build__cart" data-build="' + index + '"'
        + (build.inCart ? ' disabled>Adaugata in cos' : '>Adauga configuratia in cos') + '</button>'
      : '';

    return '<div class="ae-build"><div class="ae-build__title">Configuratia verificata</div>'
      + '<ul class="ae-build__list">' + rows + '</ul>'
      + '<div class="ae-build__total"><strong>Total: ' + esc(build.total) + '</strong>'
      + (budget ? '<br>' + esc(budget) : '') + '</div>'
      + warnings + button + '</div>';
  }

  /** cardul ca text, pentru istoricul trimis modelului (ca sa stie exact ce i s-a afisat clientului) */
  function buildText(build) {
    return '[Configuratia afisata clientului sub acest mesaj' + (build.completa ? '' : ', incompleta') + ']\n'
      + build.componente.map(function (item) {
        return '- ' + item.eticheta + ': [' + item.nume + '](' + item.url + ') - ' + item.pret;
      }).join('\n')
      + '\nTotal: ' + build.total + '\n\n';
  }

  function bubble(message, index) {
    var role = message.role === 'user' ? 'user' : 'assistant';
    return '<div class="ae-msg ae-msg--' + role + (message.error ? ' ae-msg--error' : '') + '">'
      + (role === 'user' ? esc(message.content) : markdown(message.content))
      + (message.build ? buildCard(message.build, index) : '') + '</div>';
  }

  function render() {
    list.innerHTML = bubble({role: 'assistant', content: GREETING})
      + messages.map(bubble).join('')
      + (busy ? '<div class="ae-msg ae-msg--assistant ae-msg--typing" aria-label="Asistentul scrie"><span></span><span></span><span></span></div>' : '');
    suggestions.hidden = messages.length > 0;
    list.scrollTop = list.scrollHeight;
  }

  function setOpen(isOpen) {
    panel.hidden = !isOpen;
    toggle.setAttribute('aria-expanded', String(isOpen));
    root.classList.toggle('is-open', isOpen);
    save();
    if (isOpen) {
      render();
      input.focus();
    }
  }

  /**
   * Produsele cerute de asistent (unealta adauga_in_cos): le adauga pe rand prin controllerul standard
   * al cosului, ca in configurator, apoi actualizeaza cosul din header.
   */
  function addToCart(products) {
    if (typeof window.prestashop === 'undefined') {
      return Promise.resolve();
    }
    var cartUrl = window.prestashop.urls.pages.cart;
    var added = [];
    var failed = [];
    var lastResponse = null; // raspunsul cosului, trimis temei cu updateCart (tema citeste resp.cart)

    return products.reduce(function (previous, product) {
      return previous.then(function () {
        var body = new URLSearchParams({
          token: window.prestashop.static_token,
          id_product: product.id,
          id_customization: 0,
          qty: 1,
          add: 1,
          action: 'update',
          ajax: 1,
        });
        return fetch(cartUrl, {
          method: 'POST',
          body: body,
          credentials: 'same-origin',
          headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        })
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (data.hasError) {
              failed.push(product.nume + ' (' + (data.errors || []).join(' ') + ')');
            } else {
              added.push(product.nume);
              lastResponse = data;
            }
          })
          .catch(function () {
            failed.push(product.nume);
          });
      });
    }, Promise.resolve()).then(function () {
      // fara linkAction, modulul cosului doar reincarca cosul din header (fara fereastra modala);
      // tema citeste resp.cart, deci trimitem ultimul raspuns; o eroare a temei nu blocheaza chatul
      if (lastResponse) {
        try {
          window.prestashop.emit('updateCart', {reason: {}, resp: lastResponse});
        } catch (e) {
          // cosul e deja actualizat pe server; se vede la reincarcarea paginii
        }
      }
      // lista reala a produselor din cos (nu ce a scris modelul); ramane si in istoricul conversatiei
      if (added.length) {
        messages.push({role: 'assistant', content: 'Am adaugat in cos:\n' + added.map(function (name) { return '- ' + name; }).join('\n')
          + '\n\n[Vezi cosul](' + cartUrl + (cartUrl.indexOf('?') < 0 ? '?' : '&') + 'action=show)'});
      }
      if (failed.length) {
        messages.push({role: 'assistant', content: 'Nu am putut adauga in cos: ' + failed.join(', ') + '.', error: true});
      }
    });
  }

  function send(text) {
    text = text.trim();
    if (!text || busy) {
      return;
    }
    messages.push({role: 'user', content: text});
    busy = true;
    save();
    render();

    var history = messages.filter(function (m) { return !m.error; }).slice(-30).map(function (m) {
      // cardul inainte de text: serverul taie mesajele lungi de la sfarsit
      return {role: m.role, content: (m.build ? buildText(m.build) : '') + m.content};
    });
    fetch(root.dataset.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', Accept: 'application/json'},
      body: JSON.stringify({token: root.dataset.token, messages: history}),
    })
      .then(function (response) {
        return response.json().catch(function () {
          return {error: 'Serverul a trimis un raspuns invalid.'};
        });
      })
      .then(function (data) {
        messages.push(data.reply ? {role: 'assistant', content: data.reply, build: data.build || null}
          : {role: 'assistant', content: data.error || 'Eroare.', error: true});
        if (data.cart && data.cart.length) {
          return addToCart(data.cart);
        }
      })
      .catch(function () {
        messages.push({role: 'assistant', content: 'Nu ma pot conecta la server. Verifica conexiunea la internet.', error: true});
      })
      .then(function () {
        busy = false;
        save();
        render();
        input.focus();
      });
  }

  toggle.addEventListener('click', function () {
    setOpen(panel.hidden);
  });
  root.querySelector('.ae-chat__close').addEventListener('click', function () {
    setOpen(false);
    toggle.focus();
  });
  root.querySelector('.ae-chat__reset').addEventListener('click', function () {
    messages = [];
    save();
    render();
    input.focus();
  });
  // butonul "Adauga configuratia in cos" din cardul unei configuratii
  list.addEventListener('click', function (event) {
    var button = event.target.closest('.ae-build__cart');
    var message = button && messages[Number(button.dataset.build)];
    if (!message || !message.build || busy) {
      return;
    }
    busy = true;
    button.disabled = true;
    button.textContent = 'Se adauga...';
    addToCart(message.build.componente).then(function () {
      message.build.inCart = true;
      busy = false;
      save();
      render();
    });
  });
  suggestions.addEventListener('click', function (event) {
    if (event.target.tagName === 'BUTTON') {
      send(event.target.textContent);
    }
  });
  form.addEventListener('submit', function (event) {
    event.preventDefault();
    send(input.value);
    input.value = '';
  });
  root.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !panel.hidden) {
      setOpen(false);
      toggle.focus();
    }
  });

  if (stored.open) {
    setOpen(true);
  }
});
