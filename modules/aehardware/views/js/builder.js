/**
 * Configuratorul "Creaza propriul tau PC" (modules/aehardware), pe pasi: cate o componenta pe ecran
 * (Procesor -> Placa de baza -> ... -> Rezumat), cu filtre ca lista sa ramana usor de parcurs si la sute
 * de produse: cautare, marca, pret maxim, doar in stoc, sortare si cate un select pentru fiecare
 * caracteristica cu putine valori (ex. Socket, Nuclee, Tip memorie), generate din produse.
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
  var SUMMARY = 'rezumat'; // ultimul pas, dupa componente
  var step = null; // pasul afisat: cheia unei componente sau SUMMARY
  var PAGE = 20; // cate produse se afiseaza odata
  var shown = {}; // componenta => cate produse sunt afisate acum
  var filters = {}; // componenta => {q, marca, pret, stoc, sort, feat: {caracteristica: valoare}}
  // caracteristici fara rost ca filtru: aceeasi la toate produsele sau doar pentru calculul sursei
  var SKIP_FEATURES = ['Consum', 'Garantie'];
  var PRICE_STEPS = [250, 500, 750, 1000, 1500, 2000, 3000, 5000, 7500, 10000];

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
    return Object.keys(product.caracteristici).filter(function (name) {
      return name !== 'Garantie';
    }).map(function (name) {
      return name + ': ' + product.caracteristici[name];
    }).join(' · ');
  }

  function keys() {
    return state.componente.map(function (c) { return c.cheie; });
  }

  function slotState(key) {
    return state.componente.filter(function (c) { return c.cheie === key; })[0];
  }

  /** prima componenta neleasa dupa `after` (sau de la inceput); daca sunt toate alese, rezumatul */
  function nextOpen(after) {
    var all = keys();
    var ordered = all.slice(all.indexOf(after) + 1).concat(all);
    for (var i = 0; i < ordered.length; i++) {
      if (!selection[ordered[i]]) {
        return ordered[i];
      }
    }
    return SUMMARY;
  }

  /** pasul de dinainte / de dupa `key`, in ordinea pasilor (componentele, apoi rezumatul) */
  function neighbour(key, delta) {
    var all = keys().concat(SUMMARY);
    var index = all.indexOf(key) + delta;
    return all[Math.max(0, Math.min(all.length - 1, index))];
  }

  function goTo(key) {
    step = key;
    render();
    if (root.getBoundingClientRect().top < 0) {
      root.scrollIntoView({behavior: 'smooth', block: 'start'});
    }
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
          goTo(nextOpen(null));
        } else if (afterChoose !== undefined) {
          // dupa alegere trecem la urmatoarea componenta neleasa
          goTo(nextOpen(afterChoose));
        } else {
          if (step === null) {
            step = Object.keys(selection).length ? nextOpen(null) : data.componente[0].cheie;
          }
          render();
        }
      })
      .catch(function () {
        slotsEl.innerHTML = '<p class="alert alert-danger">Componentele nu au putut fi incarcate. Reincarca pagina.</p>';
      });
  }

  function render() {
    slotsEl.innerHTML = renderSteps() + (step === SUMMARY ? renderReview() : renderStep(slotState(step)));
    summaryEl.innerHTML = renderSummary(state.rezumat);
  }

  /** bara de pasi: fiecare componenta (bifata daca e aleasa), apoi rezumatul; se poate sari la orice pas */
  function renderSteps() {
    var items = state.componente.map(function (c, index) {
      return '<li><button type="button" class="ae-steps__item' + (c.ales ? ' is-done' : '') + (step === c.cheie ? ' is-current' : '') + '"'
        + ' data-action="goto" data-step="' + esc(c.cheie) + '"' + (step === c.cheie ? ' aria-current="step"' : '') + '>'
        + '<span class="ae-steps__number">' + (c.ales ? '&#10003;' : index + 1) + '</span>'
        + '<span class="ae-steps__label">' + esc(c.eticheta) + '</span></button></li>';
    });
    items.push('<li><button type="button" class="ae-steps__item' + (step === SUMMARY ? ' is-current' : '') + '" data-action="goto" data-step="' + SUMMARY + '"'
      + (step === SUMMARY ? ' aria-current="step"' : '') + '><span class="ae-steps__number">' + (items.length + 1) + '</span>'
      + '<span class="ae-steps__label">Rezumat</span></button></li>');

    return '<nav aria-label="Pasii configuratiei"><ol class="ae-steps">' + items.join('') + '</ol></nav>';
  }

  /** un pas: componenta, produsul ales, filtrele, lista de produse compatibile si navigarea */
  function renderStep(c) {
    var position = keys().indexOf(c.cheie);
    var chosen = c.ales;
    var html = '<section class="ae-slot is-open' + (chosen ? ' is-chosen' : '') + '" data-slot="' + esc(c.cheie) + '">'
      + '<div class="ae-slot__head">'
      + (c.imagine ? '<img class="ae-slot__icon" src="' + esc(c.imagine) + '" alt="" width="56" height="56" loading="lazy">' : '')
      + '<div class="ae-slot__info"><span class="ae-slot__step">Pasul ' + (position + 1) + ' din ' + keys().length + '</span>'
      + '<h2 class="ae-slot__title">' + esc(c.eticheta) + '</h2>';
    if (chosen) {
      html += '<a class="ae-slot__product" href="' + esc(chosen.url) + '" target="_blank" rel="noopener">' + esc(chosen.nume) + '</a>'
        + '<span class="ae-slot__specs">' + esc(specs(chosen)) + '</span>';
    } else {
      html += '<span class="ae-slot__empty">Neales · ' + c.compatibile.length + ' produse compatibile</span>';
    }
    html += '</div>'
      + (chosen ? '<strong class="ae-slot__price">' + esc(chosen.pret_text) + '</strong>'
        + '<div class="ae-slot__actions"><button type="button" class="btn btn-link btn-sm" data-action="remove">Elimina</button></div>' : '')
      + '</div>';

    if (c.compatibile.length > 1) {
      html += renderFilters(c);
    }
    html += '<div class="ae-slot__list" data-results>' + renderResults(c) + '</div>'
      + '<div class="ae-step__nav">'
      + (position > 0 ? '<button type="button" class="btn btn-secondary btn-sm" data-action="prev">&larr; Inapoi</button>' : '<span></span>')
      + '<button type="button" class="btn btn-' + (chosen ? 'primary' : 'secondary') + ' btn-sm" data-action="next">'
      + (chosen ? 'Urmatorul pas' : 'Sari peste') + ' &rarr;</button></div>';

    return html + '</section>';
  }

  /** ultimul pas: toate componentele, cu link catre pasul fiecareia */
  function renderReview() {
    var s = state.rezumat;
    var html = '<section class="ae-slot is-open"><div class="ae-slot__head"><div class="ae-slot__info">'
      + '<span class="ae-slot__step">Ultimul pas</span><h2 class="ae-slot__title">Rezumatul configuratiei</h2></div></div>'
      + '<ul class="ae-review">' + state.componente.map(function (c) {
        return '<li><span class="ae-summary__label">' + esc(c.eticheta) + '</span>'
          + (c.ales ? '<a href="' + esc(c.ales.url) + '" target="_blank" rel="noopener">' + esc(c.ales.nume) + '</a> <strong>' + esc(c.ales.pret_text) + '</strong>'
            : '<span class="ae-slot__empty">Neales</span>')
          + ' <button type="button" class="btn btn-link btn-sm" data-action="goto" data-step="' + esc(c.cheie) + '">'
          + (c.ales ? 'Schimba' : 'Alege') + '</button></li>';
      }).join('') + '</ul>'
      + '<p class="ae-summary__total"><span>Total</span><strong>' + esc(s.total_text) + '</strong></p>';
    if (s.probleme.length) {
      html += '<div class="alert alert-danger">' + s.probleme.map(esc).join('<br>') + '</div>';
    }
    if (s.lipsa.length) {
      html += '<p class="ae-summary__missing">Mai lipsesc: ' + esc(s.lipsa.join(', ')) + '.</p>';
    }

    return html + '<div class="ae-step__nav"><button type="button" class="btn btn-secondary btn-sm" data-action="prev">&larr; Inapoi</button>'
      + '<button type="button" class="btn btn-primary btn-sm" data-action="cart"' + (s.componente.length ? '' : ' disabled') + '>'
      + '<i class="material-icons" aria-hidden="true">shopping_cart</i> Adauga in cos</button></div></section>';
  }

  /** valorile unei caracteristici; cele multiple sunt despartite prin " / " (ex. Memorie suportata: "DDR4 / DDR5") */
  function featureValues(product, name) {
    var value = product.caracteristici[name];
    return value === undefined ? [] : String(value).split(' / ');
  }

  function byNatural(a, b) {
    return String(a).localeCompare(String(b), 'ro', {numeric: true});
  }

  /**
   * Selecturile de filtrare, generate din produse: caracteristicile cu 2-30 de valori, dar nu una diferita
   * la fiecare produs (ex. Chipset la placi video, cu un singur model pe valoare) - acelea se cauta cu textul.
   */
  function filterFields(c) {
    var values = {};
    c.compatibile.forEach(function (p) {
      Object.keys(p.caracteristici).forEach(function (name) {
        if (SKIP_FEATURES.indexOf(name) < 0) {
          values[name] = values[name] || {};
          featureValues(p, name).forEach(function (v) { values[name][v] = true; });
        }
      });
    });

    return Object.keys(values).map(function (name) {
      return {name: name, values: Object.keys(values[name]).sort(byNatural)};
    }).filter(function (field) {
      return field.values.length > 1 && field.values.length <= 30 && field.values.length < c.compatibile.length;
    });
  }

  function select(name, label, options, current) {
    return '<label class="ae-filter"><span>' + esc(label) + '</span><select class="form-control form-control-sm" data-filter="' + esc(name) + '">'
      + options.map(function (o) {
        return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(current || '') ? ' selected' : '') + '>' + esc(o[1]) + '</option>';
      }).join('') + '</select></label>';
  }

  function renderFilters(c) {
    var f = filters[c.cheie] || {};
    var brands = c.compatibile.map(function (p) { return p.marca; }).filter(function (b, i, all) {
      return b && all.indexOf(b) === i;
    }).sort(byNatural);
    var prices = c.compatibile.map(function (p) { return p.pret; });
    var min = Math.min.apply(null, prices);
    var max = Math.max.apply(null, prices);
    var html = '<div class="ae-filters">'
      + '<label class="ae-filter ae-filter--search"><span>Cauta</span><input type="search" class="form-control form-control-sm" data-filter="q"'
      + ' value="' + esc(f.q || '') + '" placeholder="Nume sau model..."></label>';
    if (brands.length > 1) {
      html += select('marca', 'Marca', [['', 'Toate']].concat(brands.map(function (b) { return [b, b]; })), f.marca);
    }
    var steps = PRICE_STEPS.filter(function (p) { return p > min && p < max; });
    if (steps.length) {
      html += select('pret', 'Pret maxim', [['', 'Orice pret']].concat(steps.map(function (p) { return [p, 'pana la ' + p + ' lei']; })), f.pret);
    }
    filterFields(c).forEach(function (field) {
      html += select('feat:' + field.name, field.name, [['', 'Toate']].concat(field.values.map(function (v) { return [v, v]; })), (f.feat || {})[field.name]);
    });
    html += select('sort', 'Ordonare', [['', 'Pret crescator'], ['pret_desc', 'Pret descrescator'], ['nume', 'Nume']], f.sort)
      + '<label class="ae-filter ae-filter--check"><input type="checkbox" data-filter="stoc"' + (f.stoc ? ' checked' : '') + '> Doar in stoc</label>';

    return html + '</div>';
  }

  /** produsele compatibile care trec de filtrele pasului, in ordinea aleasa */
  function filtered(c) {
    var f = filters[c.cheie] || {};
    var q = (f.q || '').toLowerCase().trim();
    var list = c.compatibile.filter(function (p) {
      if (q && (p.nume + ' ' + (p.marca || '')).toLowerCase().indexOf(q) < 0) {
        return false;
      }
      if ((f.marca && p.marca !== f.marca) || (f.pret && p.pret > Number(f.pret)) || (f.stoc && p.stoc <= 0)) {
        return false;
      }
      return Object.keys(f.feat || {}).every(function (name) {
        return !f.feat[name] || featureValues(p, name).indexOf(f.feat[name]) >= 0;
      });
    });
    if (f.sort === 'pret_desc') {
      list.sort(function (a, b) { return b.pret - a.pret; });
    } else if (f.sort === 'nume') {
      list.sort(function (a, b) { return byNatural(a.nume, b.nume); });
    }

    return list;
  }

  function hasFilters(f) {
    return Boolean(f && (f.q || f.marca || f.pret || f.stoc || Object.keys(f.feat || {}).some(function (k) { return f.feat[k]; })));
  }

  /** lista unui pas (refacuta singura la schimbarea filtrelor, ca sa nu se piarda focusul din cautare) */
  function renderResults(c) {
    var list = filtered(c);
    var limit = shown[c.cheie] || PAGE;
    var html = '<p class="ae-results__count">' + list.length + ' din ' + c.compatibile.length + ' produse compatibile'
      + (hasFilters(filters[c.cheie]) ? ' <button type="button" class="btn btn-link btn-sm" data-action="clear-filters">Sterge filtrele</button>' : '')
      + '</p>';

    return html + renderList(c, list.slice(0, limit))
      + (list.length > limit ? '<button type="button" class="btn btn-secondary btn-block btn-sm" data-action="more">Arata inca '
        + Math.min(PAGE, list.length - limit) + ' (din ' + (list.length - limit) + ' ramase)</button>' : '');
  }

  function updateResults() {
    var results = slotsEl.querySelector('[data-results]');
    if (results && step !== SUMMARY) {
      results.innerHTML = renderResults(slotState(step));
    }
  }

  function renderList(c, products) {
    var html = '';
    if (c.conditii.length) {
      html += '<p class="ae-slot__filter"><i class="material-icons" aria-hidden="true">filter_list</i>'
        + '<span>Afisam doar produsele compatibile: ' + esc(c.conditii.join(', ')) + '.'
        + (c.incompatibile ? ' <strong>' + c.incompatibile + ' ' + (c.incompatibile === 1 ? 'produs incompatibil este ascuns' : 'produse incompatibile sunt ascunse') + '.</strong>' : '')
        + '</span></p>';
    }
    if (!c.compatibile.length) {
      html += '<p class="alert alert-warning">Niciun produs nu este compatibil cu componentele alese. Schimba una dintre ele.</p>';
    } else if (!products.length) {
      html += '<p class="alert alert-info">Niciun produs nu se potriveste filtrelor alese.</p>';
    }
    // la surse: cata putere ramane peste consumul configuratiei
    var power = c.cheie === 'sursa' ? state.rezumat.consum_estimat : 0;
    products.forEach(function (p) {
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

    return html;
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
      case 'goto':
        goTo(button.dataset.step);
        break;
      case 'prev':
        goTo(neighbour(step, -1));
        break;
      case 'next':
        goTo(neighbour(step, 1));
        break;
      case 'more':
        shown[step] = (shown[step] || PAGE) + PAGE;
        updateResults();
        break;
      case 'clear-filters':
        filters[step] = {};
        shown[step] = PAGE;
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
        filters = {};
        shown = {};
        step = state.componente[0].cheie;
        refresh();
        break;
      case 'cart':
        addToCart(button);
        break;
    }
  });

  /** filtrele pasului curent: se reface doar lista, ca selecturile si cautarea sa ramana cum sunt */
  function onFilter(event) {
    var name = event.target.dataset.filter;
    if (!name || step === SUMMARY) {
      return;
    }
    var f = filters[step] = filters[step] || {};
    var value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
    var target = f;
    if (name.indexOf('feat:') === 0) {
      target = f.feat = f.feat || {};
      name = name.slice(5);
    }
    // "change" vine si la iesirea din cautare (ex. click pe un buton din lista): fara schimbare nu refacem
    // lista, altfel butonul apasat ar disparea inainte de click
    if ((target[name] || '') === (value || '')) {
      return;
    }
    target[name] = value;
    shown[step] = PAGE;
    updateResults();
  }

  root.addEventListener('change', onFilter);
  root.addEventListener('input', function (event) {
    if (event.target.dataset.filter === 'q') {
      onFilter(event);
    }
  });

  refresh();
})();
