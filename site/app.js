// Langue (le texte suit le CSS, les textes alternatifs suivent ici) et version du
// firmware publiée. Aucune dépendance ; la page reste lisible sans ce script.
(function () {
  var root = document.documentElement;

  var images = document.querySelectorAll('img[data-alt-en]');
  var titleFr = document.title;
  var titleEn = 'AZBA Indicator — a light that tells whether the AZBA zone is active';
  function applyAlts() {
    images.forEach(function (img) {
      if (!img.dataset.altFr) img.dataset.altFr = img.alt;
      img.alt = root.lang === 'en' ? img.dataset.altEn : img.dataset.altFr;
    });
    document.title = root.lang === 'en' ? titleEn : titleFr;
  }
  document.getElementById('lang-toggle').addEventListener('click', function () {
    var next = root.lang === 'fr' ? 'en' : 'fr';
    root.lang = next;
    try { localStorage.setItem('lang', next); } catch (e) {}
    applyAlts();
  });
  applyAlts();

  // La version vient du manifeste que lit l'installateur : une seule source.
  var version = document.getElementById('fw-version');
  fetch('/firmware/manifest.json')
    .then(function (response) { return response.ok ? response.json() : null; })
    .then(function (manifest) { if (manifest && manifest.version) version.textContent = manifest.version; })
    .catch(function () {});
})();
