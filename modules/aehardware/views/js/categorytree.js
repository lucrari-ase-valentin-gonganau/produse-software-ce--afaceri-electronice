/**
 * Meniul lateral de categorii (ps_categorytree): tema il afiseaza mereu inchis si fara categoria curenta.
 * Aici marcam categoria curenta si parintii ei (dupa breadcrumb), deschidem ramura pana la ea
 * si lista ei de subcategorii.
 */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var tree = document.querySelector('.block-categories');
  if (!tree) {
    return;
  }

  /** adresa fara parametri (?page=2, ?order=...), ca sa se potriveasca cu linkurile din meniu */
  function clean(url) {
    var u = new URL(url, window.location.href);
    return u.origin + u.pathname.replace(/\/$/, '');
  }

  var canonical = document.querySelector('link[rel="canonical"]');
  var current = clean(canonical ? canonical.href : window.location.href);
  // breadcrumb: Acasa / Componente PC / (Procesoare = pagina curenta, fara link)
  var trail = [].map.call(document.querySelectorAll('.breadcrumb a[href]'), function (link) {
    return clean(link.href);
  });
  trail.push(current);

  function open(item) {
    var toggle = item.querySelector(':scope > [data-toggle="collapse"]');
    var panel = item.querySelector(':scope > .collapse');
    if (toggle && panel) {
      panel.classList.add('in');
      toggle.setAttribute('aria-expanded', 'true');
    }
  }

  [].forEach.call(tree.querySelectorAll('.category-sub-menu a[href]'), function (link) {
    var url = clean(link.href);
    if (trail.indexOf(url) === -1) {
      return;
    }
    var item = link.parentElement;
    if (url === current) {
      item.classList.add('ae-tree-current');
      link.setAttribute('aria-current', 'page');
    } else {
      item.classList.add('ae-tree-trail');
    }
    open(item);
  });
});
