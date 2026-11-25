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
