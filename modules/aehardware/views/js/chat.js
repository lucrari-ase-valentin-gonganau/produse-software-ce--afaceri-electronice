/**
 * Fereastra chatbotului (modules/aehardware). Conversatia se pastreaza in sessionStorage;
 * la fiecare intrebare se trimit serverului ultimele mesaje (fara mesajele de eroare).
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
      return JSON.parse(window.sessionStorage.getItem(STORAGE)) || {};
    } catch (e) {
      return {};
    }
  }

  function save() {
    try {
      window.sessionStorage.setItem(STORAGE, JSON.stringify({messages: messages.slice(-30), open: !panel.hidden}));
    } catch (e) {
      // fara sessionStorage, conversatia se pierde la schimbarea paginii
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

  function bubble(message) {
    var role = message.role === 'user' ? 'user' : 'assistant';
    return '<div class="ae-msg ae-msg--' + role + (message.error ? ' ae-msg--error' : '') + '">'
      + (role === 'user' ? esc(message.content) : markdown(message.content)) + '</div>';
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

  function send(text) {
    text = text.trim();
    if (!text || busy) {
      return;
    }
    messages.push({role: 'user', content: text});
    busy = true;
    save();
    render();

    var history = messages.filter(function (m) { return !m.error; }).slice(-12).map(function (m) {
      return {role: m.role, content: m.content};
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
        messages.push(data.reply ? {role: 'assistant', content: data.reply} : {role: 'assistant', content: data.error || 'Eroare.', error: true});
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
