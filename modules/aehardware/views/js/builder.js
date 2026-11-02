/**
 * Configuratorul "Creaza propriul tau PC" (modules/aehardware).
 * Selectia (componenta => id produs) se pastreaza in localStorage; la fiecare schimbare cerem
 * serverului starea: produsul ales si produsele compatibile pentru fiecare componenta.
 */
(function () {
  'use strict';

  var root = document.getElementById('ae-builder');
  if (!root) {
    return;
  }

  var STORAGE = 'aePcBuilder';
  var slotsEl = root.querySelector('.ae-builder__slots');
  var summaryEl = root.querySelector('.ae-builder__summary');
  var selection = load();
  var start = parseInt(root.dataset.start, 10) || 0;
  var state = null;
  var open = null; // componenta a carei lista de produse e deschisa

  function load() {
    try {
      return JSON.parse(window.localStorage.getItem(STORAGE)) || {};
    } catch (e) {
      return {};
    }
  }

  function save() {
    try {
      window.localStorage.setItem(STORAGE, JSON.stringify(selection));
    } catch (e) {
      // fara localStorage, selectia se pierde la reincarcare
    }
  }

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
    });
  }

  function specs(product) {
    return Object.keys(product.caracteristici).map(function (name) {
      return name + ': ' + product.caracteristici[name];
    }).join(' · ');
  }

  /** prima componenta neleasa dupa `after` (sau de la inceput) */
  function nextOpen(after) {
    var keys = state.componente.map(function (c) { return c.cheie; });
    var ordered = keys.slice(keys.indexOf(after) + 1).concat(keys);
    for (var i = 0; i < ordered.length; i++) {
      if (!selection[ordered[i]]) {
        return ordered[i];
      }
    }
    return null;
  }

  function refresh(afterChoose) {
    var url = new URL(root.dataset.url, window.location.href);
    url.searchParams.set('ajax', '1');
    url.searchParams.set('selectie', JSON.stringify(selection));
    if (start) {
      url.searchParams.set('start', start);
    }

    return fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        state = data;
        // pastram doar ce a acceptat serverul (produse existente si compatibile)
        selection = {};
        data.componente.forEach(function (c) {
          if (c.ales) {
            selection[c.cheie] = c.ales.id;
          }
        });
        save();
        if (start) {
          // "Construieste un PC cu acest produs": continuam cu componentele lipsa
          start = 0;
          window.history.replaceState(null, '', root.dataset.url);
          open = nextOpen(null);
        } else if (afterChoose !== undefined) {
          open = nextOpen(afterChoose);
        } else if (open === null && !Object.keys(selection).length) {
          open = data.componente[0].cheie;
        }
        render();
        if (afterChoose !== undefined && open) {
          var el = slotsEl.querySelector('[data-slot="' + open + '"]');
          if (el && el.getBoundingClientRect().top < 0) {
            el.scrollIntoView({behavior: 'smooth', block: 'start'});
          }
        }
      })
      .catch(function () {
        slotsEl.innerHTML = '<p class="alert alert-danger">Componentele nu au putut fi incarcate. Reincarca pagina.</p>';
      });
  }

  function render() {
    slotsEl.innerHTML = state.componente.map(renderSlot).join('');
    summaryEl.innerHTML = renderSummary(state.rezumat);
  }

  function renderSlot(c) {
    var isOpen = open === c.cheie;
    var chosen = c.ales;
    var html = '<section class="ae-slot' + (chosen ? ' is-chosen' : '') + (isOpen ? ' is-open' : '') + '" data-slot="' + esc(c.cheie) + '">'
      + '<div class="ae-slot__head">'
      + (c.imagine ? '<img class="ae-slot__icon" src="' + esc(c.imagine) + '" alt="" width="56" height="56" loading="lazy">' : '')
      + '<div class="ae-slot__info"><h2 class="ae-slot__title">' + esc(c.eticheta) + '</h2>';
    if (chosen) {
      html += '<a class="ae-slot__product" href="' + esc(chosen.url) + '" target="_blank" rel="noopener">' + esc(chosen.nume) + '</a>'
        + '<span class="ae-slot__specs">' + esc(specs(chosen)) + '</span>';
    } else {
      html += '<span class="ae-slot__empty">Neales · ' + c.compatibile.length + ' produse compatibile</span>';
    }
    html += '</div>'
      + (chosen ? '<strong class="ae-slot__price">' + esc(chosen.pret_text) + '</strong>' : '')
      + '<div class="ae-slot__actions">'
      + '<button type="button" class="btn btn-sm ' + (chosen && !isOpen ? 'btn-secondary' : 'btn-primary') + '" data-action="toggle" aria-expanded="' + isOpen + '">'
      + (isOpen ? 'Inchide' : (chosen ? 'Schimba' : 'Alege')) + '</button>'
      + (chosen ? '<button type="button" class="btn btn-link btn-sm" data-action="remove">Elimina</button>' : '')
      + '</div></div>';

    return html + (isOpen ? renderList(c) : '') + '</section>';
  }

  function renderList(c) {
    var html = '<div class="ae-slot__list">';
    if (c.conditii.length) {
      html += '<p class="ae-slot__filter"><i class="material-icons" aria-hidden="true">filter_list</i>'
        + '<span>Afisam doar produsele compatibile: ' + esc(c.conditii.join(', ')) + '.'
        + (c.incompatibile ? ' <strong>' + c.incompatibile + ' ' + (c.incompatibile === 1 ? 'produs incompatibil este ascuns' : 'produse incompatibile sunt ascunse') + '.</strong>' : '')
        + '</span></p>';
    }
    if (!c.compatibile.length) {
      html += '<p class="alert alert-warning">Niciun produs nu este compatibil cu componentele alese. Schimba una dintre ele.</p>';
    }
    // la surse: cata putere ramane peste consumul configuratiei
    var power = c.cheie === 'sursa' ? state.rezumat.consum_estimat : 0;
    c.compatibile.forEach(function (p) {
      var current = c.ales && c.ales.id === p.id;
      var soldOut = p.stoc <= 0;
      var watts = parseInt(p.caracteristici.Putere, 10);
      var headroom = power && watts ? '<span class="ae-option__headroom">' + watts + ' W pentru un consum de ' + power + ' W: '
        + Math.round((watts / power - 1) * 100) + '% rezerva</span>' : '';
      html += '<div class="ae-option' + (current ? ' is-current' : '') + '">'
        + (p.imagine ? '<img src="' + esc(p.imagine) + '" alt="" width="72" height="72" loading="lazy">' : '')
        + '<div class="ae-option__info">'
        + '<a href="' + esc(p.url) + '" target="_blank" rel="noopener">' + esc(p.nume) + '</a>'
        + '<span class="ae-option__specs">' + esc(specs(p)) + '</span>'
        + headroom
        + '<span class="ae-option__stock' + (soldOut ? ' is-out' : '') + '">' + (soldOut ? 'Stoc epuizat' : 'In stoc') + '</span>'
        + '</div>'
        + '<strong class="ae-option__price">' + esc(p.pret_text) + '</strong>'
        + '<button type="button" class="btn btn-sm ' + (current ? 'btn-secondary' : 'btn-primary') + '" data-action="choose" data-id="' + p.id + '"'
        + (current || soldOut ? ' disabled' : '') + '>' + (current ? 'Ales' : 'Alege') + '</button>'
        + '</div>';
    });

    return html + '</div>';
  }

  function renderSummary(s) {
    var html = '<div class="card-block"><h2 class="h5 ae-summary__title">Configuratia ta</h2>';
    if (s.componente.length) {
      html += '<ul class="ae-summary__items">' + s.componente.map(function (item) {
        return '<li><span class="ae-summary__label">' + esc(item.eticheta) + '</span>'
          + '<span class="ae-summary__name">' + esc(item.produs.nume) + '</span>'
          + '<strong>' + esc(item.produs.pret_text) + '</strong></li>';
      }).join('') + '</ul>';
    } else {
      html += '<p class="ae-summary__empty">Nu ai ales nicio componenta.</p>';
    }
    html += '<p class="ae-summary__total"><span>Total</span><strong>' + esc(s.total_text) + '</strong></p>';
    if (s.consum_componente.length) {
      html += '<div class="ae-summary__power"><h3 class="ae-summary__subtitle">Consum pe componente</h3><ul>'
        + s.consum_componente.map(function (row) {
          return '<li><span>' + esc(row.eticheta) + '</span><span>' + row.w + ' W</span></li>';
        }).join('')
        + '<li class="ae-summary__power-total"><span>Consum total</span><span>' + s.consum_estimat + ' W</span></li></ul>'
        + '<p>Sursa necesara: <strong>minim ' + s.sursa_recomandata + ' W</strong> (consum + 30% rezerva)</p></div>';
    }
    if (s.probleme.length) {
      html += '<div class="alert alert-danger">' + s.probleme.map(esc).join('<br>') + '</div>';
    }
    if (s.componente.length && s.lipsa.length) {
      html += '<p class="ae-summary__missing">Mai lipsesc: ' + esc(s.lipsa.join(', ')) + '.</p>';
    }
    html += '<button type="button" class="btn btn-primary btn-block" data-action="cart"' + (s.componente.length ? '' : ' disabled') + '>'
      + '<i class="material-icons" aria-hidden="true">shopping_cart</i> Adauga configuratia in cos</button>';
    if (s.componente.length) {
      html += '<button type="button" class="btn btn-link btn-block" data-action="reset">Goleste configuratia</button>';
    }

    return html + '<p class="ae-summary__message" role="status"></p></div>';
  }

  /** adauga produsele pe rand, prin controllerul standard al cosului, apoi deschide cosul */
  function addToCart(button) {
    var message = summaryEl.querySelector('.ae-summary__message');
    if (typeof window.prestashop === 'undefined') {
      return;
    }
    var cartUrl = window.prestashop.urls.pages.cart;
    button.disabled = true;
    message.textContent = 'Se adauga produsele in cos...';

    Object.keys(selection).reduce(function (previous, slot) {
      return previous.then(function () {
        var body = new URLSearchParams({
          token: window.prestashop.static_token,
          id_product: selection[slot],
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
              throw new Error((data.errors || []).join(' '));
            }
          });
      });
    }, Promise.resolve())
      .then(function () {
        window.location.href = cartUrl + (cartUrl.indexOf('?') < 0 ? '?' : '&') + 'action=show';
      })
      .catch(function (error) {
        button.disabled = false;
        message.textContent = 'Nu am putut adauga toate produsele in cos. ' + (error.message || '');
      });
  }

  root.addEventListener('click', function (event) {
    var button = event.target.closest('[data-action]');
    if (!button || !state) {
      return;
    }
    var slotEl = button.closest('[data-slot]');
    var slot = slotEl ? slotEl.dataset.slot : null;

    switch (button.dataset.action) {
      case 'toggle':
        open = open === slot ? null : slot;
        render();
        break;
      case 'choose':
        selection[slot] = parseInt(button.dataset.id, 10);
        refresh(slot);
        break;
      case 'remove':
        delete selection[slot];
        refresh();
        break;
      case 'reset':
        selection = {};
        open = state.componente[0].cheie;
        refresh();
        break;
      case 'cart':
        addToCart(button);
        break;
    }
  });

  refresh();
})();
