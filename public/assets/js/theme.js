function setTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('theme', theme);
  const themeIcon = document.getElementById('themeIcon');
  if (!themeIcon) return;
  themeIcon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
}

function toggleTheme() {
  const currentTheme = localStorage.getItem('theme') || 'light';
  setTheme(currentTheme === 'light' ? 'dark' : 'light');
}

document.addEventListener('DOMContentLoaded', function () {
  setTheme(localStorage.getItem('theme') || 'light');
  const btn = document.getElementById('themeToggle');
  if (btn) btn.addEventListener('click', toggleTheme);
});
