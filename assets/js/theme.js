(function () {
  const THEME_STORAGE_KEY = 'wxballoon_theme';

  function getStoredTheme() {
    const saved = localStorage.getItem(THEME_STORAGE_KEY);
    return saved === 'dark' || saved === 'light' ? saved : null;
  }

  function systemPrefersDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function getTheme() {
    return getStoredTheme() || (systemPrefersDark() ? 'dark' : 'light');
  }

  function applyTheme(theme) {
    const next = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-bs-theme', next);
    document.body.classList.toggle('theme-dark', next === 'dark');
    window.dispatchEvent(new CustomEvent('wb-theme-change', { detail: { theme: next } }));
    return next;
  }

  function isDark() {
    return document.documentElement.getAttribute('data-bs-theme') === 'dark';
  }

  function updateToggleLabel(button, isDarkMode) {
    if (!button) return;
    button.textContent = isDarkMode ? 'Light mode' : 'Dark mode';
    button.setAttribute('aria-pressed', isDarkMode ? 'true' : 'false');
  }

  function init(toggleId) {
    const button = document.getElementById(toggleId);
    const initial = applyTheme(getTheme());
    updateToggleLabel(button, initial === 'dark');

    if (button) {
      button.addEventListener('click', function () {
        const next = isDark() ? 'light' : 'dark';
        localStorage.setItem(THEME_STORAGE_KEY, next);
        applyTheme(next);
        updateToggleLabel(button, next === 'dark');
      });
    }

    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if (getStoredTheme()) return;
        const next = applyTheme(getTheme());
        updateToggleLabel(button, next === 'dark');
      });
    }
  }

  window.WxTheme = { init, isDark, getTheme, applyTheme };
})();
