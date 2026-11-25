const search = document.querySelector('[data-search]');

if (search) {
  const rows = [...document.querySelectorAll('[data-search-text]')];

  search.addEventListener('input', () => {
    const terms = search.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    for (const row of rows) {
      const text = row.dataset.searchText;
      row.classList.toggle('dim', terms.length > 0 && !terms.every((term) => text.includes(term)));
    }
  });

  document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      search.focus();
      search.select();
    } else if (event.key === 'Escape' && document.activeElement === search) {
      search.value = '';
      search.dispatchEvent(new Event('input'));
      search.blur();
    }
  });
}

for (const menu of document.querySelectorAll('details.groups')) {
  document.addEventListener('click', (event) => {
    if (menu.open && !menu.contains(event.target)) {
      menu.open = false;
    }
  });
}

for (const tabs of document.querySelectorAll('[data-tabs]')) {
  const buttons = [...tabs.querySelectorAll('[role=tab]')];
  const select = (button) => {
    for (const other of buttons) {
      const selected = other === button;
      other.setAttribute('aria-selected', String(selected));
      document.getElementById(other.getAttribute('aria-controls')).hidden = !selected;
    }
  };
  for (const button of buttons) {
    button.addEventListener('click', () => {
      select(button);
      if ('tabsHash' in tabs.dataset) {
        history.replaceState(null, '', `#${button.dataset.hash}`);
      }
    });
  }
  if ('tabsHash' in tabs.dataset) {
    const wanted = buttons.find((button) => `#${button.dataset.hash}` === location.hash);
    if (wanted) {
      select(wanted);
    }
  }
}

for (const link of document.querySelectorAll('a[data-keep-hash]')) {
  link.addEventListener('click', () => {
    link.hash = location.hash;
  });
}

for (const button of document.querySelectorAll('[data-copy]')) {
  button.addEventListener('click', async () => {
    const label = button.querySelector('[data-copy-label]');
    try {
      await navigator.clipboard.writeText(button.dataset.copy);
    } catch {
      return;
    }
    if (label) {
      const text = label.textContent;
      label.textContent = label.dataset.done;
      setTimeout(() => { label.textContent = text; }, 1500);
    }
  });
}

const live = document.querySelector('[data-live]');
if (live) {
  const rows = document.querySelector('[data-rows]');
  let timer = null;
  let last = live.dataset.last;
  const dot = live.querySelector('.dot');
  const poll = async () => {
    try {
      const url = new URL(live.dataset.live, location.href);
      if (last) url.searchParams.set('after', last);
      const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!response.ok) return;
      const data = await response.json();
      if (data.html) {
        const template = document.createElement('template');
        template.innerHTML = data.html;
        for (const row of template.content.querySelectorAll('.rq')) row.classList.add('fresh');
        rows.prepend(template.content);
        while (rows.children.length > 200) rows.lastElementChild.remove();
      }
      if (data.last) last = data.last;
    } catch {
      return;
    }
  };
  const toggle = (on) => {
    live.setAttribute('aria-pressed', String(on));
    dot.style.background = on ? '#8FD69C' : '#6B7078';
    clearInterval(timer);
    timer = on ? setInterval(poll, 2000) : null;
    if (on) poll();
    try { localStorage.setItem('tollbooth.live', on ? '1' : '0'); } catch {}
  };
  live.addEventListener('click', () => toggle(live.getAttribute('aria-pressed') !== 'true'));
  let saved = null;
  try { saved = localStorage.getItem('tollbooth.live'); } catch {}
  if (saved === '1' && !new URLSearchParams(location.search).has('before')) toggle(true);
}

for (const menu of document.querySelectorAll('details.chip')) {
  document.addEventListener('click', (event) => {
    if (menu.open && !menu.contains(event.target)) menu.open = false;
  });
}

for (const form of document.querySelectorAll('form[data-confirm]')) {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
}

for (const layouts of document.querySelectorAll('[data-layouts]')) {
  const options = [...layouts.children];
  const pick = () => {
    const room = layouts.clientWidth - 36;
    const fit = options.find((option) => Number(option.dataset.width) <= room) ?? options[options.length - 1];
    for (const option of options) {
      option.hidden = option !== fit;
    }
  };
  pick();
  new ResizeObserver(pick).observe(layouts);
}
